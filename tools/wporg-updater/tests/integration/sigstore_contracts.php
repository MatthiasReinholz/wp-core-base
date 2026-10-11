<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\GitHubReleaseClient;
use WpOrgPluginUpdater\GitLabReleaseClient;
use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\ManifestWriter;
use WpOrgPluginUpdater\AdminGovernanceExporter;
use WpOrgPluginUpdater\DependencyAuthoringService;
use WpOrgPluginUpdater\DependencyMetadataResolver;
use WpOrgPluginUpdater\GitHubReleaseManagedSource;
use WpOrgPluginUpdater\ManagedSourceRegistry;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\SigstoreBundleVerifier;
use WpOrgPluginUpdater\SigstoreToolInstaller;
use WpOrgPluginUpdater\SigstoreVerificationPolicy;

/** Tests transport and the verifier process contract; real cryptography belongs to Cosign. */
function run_sigstore_contract_tests(callable $assert, string $repoRoot): void
{
    $root = sys_get_temp_dir() . '/wp-core-base-sigstore-' . bin2hex(random_bytes(6));
    mkdir($root, 0700, true);
    $process = null;
    $pipes = [];
    $secret = getenv('WP_CORE_BASE_SIGSTORE_TEST_SECRET');
    $policy = ['bundle_asset_pattern' => '*.sigstore.json', 'certificate_identity' => 'https://publisher.example.test/release-workflow', 'oidc_issuer' => 'https://issuer.example.test'];
    $fails = static function (callable $call, string $label) use ($assert): void {
        $failed = false;
        try { $call(); } catch (RuntimeException $e) { $failed = true; }
        $assert($failed, $label);
    };
    try {
        $assert(SigstoreVerificationPolicy::normalize(null) === null, 'Existing dependencies do not implicitly require signatures.');
        $assert(SigstoreVerificationPolicy::normalize($policy) === $policy, 'Exact signer policy is normalized without widening its identity.');
        foreach ([false, [], ['bundle_asset_pattern' => '*.json'], [...$policy, 'unexpected' => true], [...$policy, 'oidc_issuer' => 'http://issuer.example.test'], [...$policy, 'oidc_issuer' => 'https://user:password@issuer.example.test'], [...$policy, 'certificate_identity' => "identity\n--insecure-ignore-tlog"]] as $invalid) {
            $fails(static fn () => SigstoreVerificationPolicy::normalize($invalid), 'Invalid or incomplete signature policy is rejected.');
        }
        $manifest = require $repoRoot . '/.wp-core-base/manifest.php';
        foreach (['github-release', 'gitlab-release'] as $source) {
            $entry = array_values(array_filter($manifest['dependencies'], static fn (array $entry): bool => $entry['management'] === 'managed'))[0];
            $entry['source'] = $source;
            $entry['source_config'] = ['github_repository' => 'fixture/project', 'github_release_asset_pattern' => 'package.zip', 'gitlab_project' => 'fixture/project', 'gitlab_release_asset_pattern' => 'package.zip', 'sigstore' => $policy];
            unset($entry['policy']['class']);
            $config = Config::fromArray($root, [...$manifest, 'dependencies' => [$entry]]);
            $assert($config->managedDependencies()[0]['source_config']['sigstore'] === $policy, 'Hosted manifest retains its exact signer policy.');
            (new ManifestWriter())->write($config);
            $assert(Config::load($root)->managedDependencies()[0]['source_config']['sigstore'] === $policy, 'Manifest serialization preserves required verification.');
        }
        $fails(static fn () => SigstoreVerificationPolicy::forDependency(['source' => 'local', 'source_config' => ['sigstore' => $policy]]), 'Signature policy cannot silently apply to an unsupported source.');
        $unconfigured = Config::fromArray($root, [...$manifest, 'dependencies' => []]);
        $assert((new SigstoreToolInstaller())->install($unconfigured, new HttpClient()) === null, 'Tool installation is a network-free no-op when signatures are not configured.');

        // Normal upgrades render new templates with the already installed renderer.
        // v1.6.7 supplies only these two placeholders to the validation template.
        $template = (string) file_get_contents($repoRoot . '/tools/wporg-updater/templates/downstream-validate-runtime-workflow.yml.tpl');
        foreach (['vendor/wp-core-base', 'vendor/custom-framework', '.'] as $toolRoot) {
            $relativeScript = ($toolRoot === '.' ? '' : $toolRoot . '/') . 'tools/wporg-updater/bin/wporg-updater.php';
            $rendered = strtr($template, [
                '__WPORG_DOCTOR_COMMAND__' => 'php ' . $relativeScript . ' doctor --repo-root=. --automation',
                '__WPORG_STAGE_RUNTIME_COMMAND__' => 'php ' . $relativeScript . ' stage-runtime --repo-root=.',
            ]);
            $assert(! str_contains($rendered, '__WPORG_'), 'Older renderers resolve every validation placeholder for default and custom tool roots.');
            if (preg_match('/name: Install required release verification tools\n        run: \|\n(.*?)\n      - name:/s', $rendered, $match) !== 1) {
                throw new RuntimeException('The rendered verification setup step is missing.');
            }
            $scriptRoot = $root . '/workflow-' . str_replace('/', '-', $toolRoot);
            $scriptPath = $scriptRoot . '/' . $relativeScript;
            mkdir(dirname($scriptPath), 0700, true);
            file_put_contents($scriptPath, '<?php file_put_contents("installer-argv.json", json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR));');
            $step = (string) preg_replace('/^          /m', '', $match[1]);
            $setupPipes = [];
            $setupProcess = proc_open(['bash', '-c', $step], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $setupPipes, $scriptRoot, [
                'PATH' => (string) getenv('PATH'), 'GITHUB_WORKSPACE' => $scriptRoot, 'GITHUB_PATH' => $scriptRoot . '/github-path',
            ]);
            if (! is_resource($setupProcess)) { throw new RuntimeException('Unable to execute the rendered validation setup step.'); }
            foreach ($setupPipes as $pipe) { stream_get_contents($pipe); fclose($pipe); }
            $assert(proc_close($setupProcess) === 0, 'The older-rendered setup step executes successfully.');
            $argv = json_decode((string) file_get_contents($scriptRoot . '/installer-argv.json'), true, flags: JSON_THROW_ON_ERROR);
            $assert($argv === ['install-verification-tools', '--repo-root=.'], 'The setup invokes the installer without leaking doctor flags.');
        }

        $binary = $root . '/cosign fixture';
        file_put_contents($binary, <<<'PHP'
#!/usr/bin/env php
<?php
// Protocol fake: cryptography is deliberately not simulated by these tests.
if (($argv[1] ?? '') !== 'verify-blob' || ($argv[2] ?? '') !== '--offline' || count($argv) !== 7 || getenv('WP_CORE_BASE_SIGSTORE_TEST_SECRET') !== false) exit(30);
$bundle = json_decode((string) file_get_contents(substr($argv[3], strlen('--bundle='))), true);
if (!is_array($bundle)) exit(31);
if ($argv[4] !== '--certificate-identity=' . ($bundle['identity'] ?? '') || $argv[5] !== '--certificate-oidc-issuer=' . ($bundle['issuer'] ?? '') || hash_file('sha256', $argv[6]) !== ($bundle['digest'] ?? '')) exit(32);
exit(0);
PHP
        );
        chmod($binary, 0700);
        putenv('WP_CORE_BASE_SIGSTORE_TEST_SECRET=must-not-reach-verifier');
        $verifier = new SigstoreBundleVerifier($binary, 2);
        $archive = $root . '/archive with space and quote\'.zip';
        file_put_contents($archive, 'signed package bytes');
        $bundle = json_encode(['digest' => hash('sha256', 'signed package bytes'), 'identity' => $policy['certificate_identity'], 'issuer' => $policy['oidc_issuer']], JSON_THROW_ON_ERROR);
        $verifier->verify($archive, $bundle, $policy);
        $assert(glob($root . '/sigstore-*') === [], 'Successful verification cleans its private bundle file.');
        file_put_contents($archive, 'tampered bytes');
        $fails(static fn () => $verifier->verify($archive, $bundle, $policy), 'Altered archive is rejected before use.');
        file_put_contents($archive, 'signed package bytes');
        $fails(static fn () => $verifier->verify($archive, $bundle, [...$policy, 'certificate_identity' => 'https://other.example.test/workflow']), 'Wrong exact signer identity is rejected.');
        $fails(static fn () => $verifier->verify($archive, $bundle, [...$policy, 'oidc_issuer' => 'https://other-issuer.example.test']), 'Wrong exact issuer is rejected.');
        $fails(static fn () => $verifier->verify($archive, 'malformed', $policy), 'Malformed bundle is rejected.');
        $fails(static fn () => $verifier->verify($archive, str_repeat('x', 1024 * 1024 + 1), $policy), 'Oversized bundle is rejected.');
        $fails(static fn () => (new SigstoreBundleVerifier($root . '/missing-executable'))->verify($archive, $bundle, $policy), 'Missing verifier fails closed.');
        $sleeper = $root . '/sleeping-verifier';
        file_put_contents($sleeper, "#!/usr/bin/env php\n<?php sleep(10);\n"); chmod($sleeper, 0700);
        $started = microtime(true);
        $fails(static fn () => (new SigstoreBundleVerifier($sleeper, 1))->verify($archive, $bundle, $policy), 'Hung verifier is terminated.');
        $assert(microtime(true) - $started < 3, 'Verification timeout is bounded.');
        $noisy = $root . '/noisy-verifier';
        file_put_contents($noisy, "#!/usr/bin/env php\n<?php fwrite(STDERR,str_repeat('x',100000)); sleep(10);\n"); chmod($noisy, 0700);
        $fails(static fn () => (new SigstoreBundleVerifier($noisy, 2))->verify($archive, $bundle, $policy), 'Unbounded diagnostic output is rejected.');
        $assert(glob($root . '/sigstore-*') === [], 'Failed and terminated verification cleans private bundle files.');

        // Exercise both real hosted-release clients over the existing trusted TLS fixture.
        file_put_contents($root . '/openssl.cnf', "[req]\ndistinguished_name=dn\nx509_extensions=v3\n[dn]\nCN=localhost\n[v3]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:localhost,IP:127.0.0.1\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = $key === false ? false : openssl_csr_new(['commonName' => 'localhost'], $key, ['config' => $root . '/openssl.cnf']);
        $cert = $csr === false ? false : openssl_csr_sign($csr, null, $key, 1, ['config' => $root . '/openssl.cnf', 'x509_extensions' => 'v3']);
        if ($cert === false || ! openssl_x509_export_to_file($cert, $root . '/certificate.pem') || ! openssl_pkey_export_to_file($key, $root . '/private.pem')) throw new RuntimeException('TLS fixture generation failed.');
        $process = proc_open([PHP_BINARY, $repoRoot . '/tools/wporg-updater/tests/fixtures/http-security/tls-server.php', $root], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) throw new RuntimeException('TLS fixture startup failed.');
        $deadline = microtime(true) + 5;
        while (! is_file($root . '/ready.json') && microtime(true) < $deadline) usleep(10000);
        $ports = json_decode((string) file_get_contents($root . '/ready.json'), true, flags: JSON_THROW_ON_ERROR);
        $origin = 'https://127.0.0.1:' . $ports[0];
        $http = new HttpClient(timeoutSeconds: 2, caFile: $root . '/certificate.pem');
        $dependency = ['slug' => 'fixture', 'source' => 'github-release', 'source_config' => ['github_repository' => 'fixture/project', 'github_release_asset_pattern' => 'package.zip', 'sigstore' => $policy]];
        $release = ['assets' => [['name' => 'package.zip', 'url' => $origin . '/sigstore/archive'], ['name' => 'package.sigstore.json', 'url' => $origin . '/sigstore/bundle']]];
        $github = new GitHubReleaseClient($http, $origin, $verifier);
        $github->downloadReleaseToFile($release, $dependency, $archive);
        $assert(file_get_contents($archive) === 'signed package bytes', 'GitHub download verifies its release bundle.');
        $missing = ['assets' => [$release['assets'][0]]];
        $fails(static fn () => $github->downloadReleaseToFile($missing, $dependency, $archive), 'Missing GitHub bundle blocks the download path.');
        $duplicate = ['assets' => [...$release['assets'], $release['assets'][1]]];
        $fails(static fn () => $github->downloadReleaseToFile($duplicate, $dependency, $archive), 'Ambiguous GitHub bundle selection fails closed.');
        $bad = $release; $bad['assets'][1]['url'] = $origin . '/sigstore/bad-bundle';
        $fails(static fn () => $github->downloadReleaseToFile($bad, $dependency, $archive), 'Rejected GitHub signature prevents successful ingestion.');
        $dependency['source'] = 'gitlab-release';
        $dependency['source_config']['gitlab_project'] = 'fixture/project';
        $dependency['source_config']['gitlab_release_asset_pattern'] = 'package.zip';
        $gitlabRelease = ['assets' => ['links' => $release['assets']]];
        $gitlab = new GitLabReleaseClient($http, $origin, $verifier);
        $gitlab->downloadReleaseToFile($gitlabRelease, $dependency, $archive);
        $assert(file_get_contents($archive) === 'signed package bytes', 'GitLab download uses the same generic verifier.');
        $gitlabMissing = ['assets' => ['links' => [$release['assets'][0]]]];
        $fails(static fn () => $gitlab->downloadReleaseToFile($gitlabMissing, $dependency, $archive), 'Missing GitLab bundle blocks ingestion.');

        $authoringRoot = $root . '/authoring';
        $entry['slug'] = 'fixture'; $entry['source'] = 'github-release';
        $entry['version'] = '1.0.0'; $entry['path'] = 'wp-content/plugins/fixture'; $entry['main_file'] = 'fixture.php';
        $entry['source_config'] = ['github_repository' => 'fixture/project', 'github_release_asset_pattern' => 'package.zip', 'sigstore' => $policy];
        mkdir($authoringRoot . '/' . $entry['path'], 0700, true);
        $runtimeFile = $authoringRoot . '/' . $entry['path'] . '/fixture.php';
        file_put_contents($runtimeFile, "<?php /* Plugin Name: Fixture\nVersion: 1.0.0\n*/\n");
        $authoringConfig = Config::fromArray($authoringRoot, [...$manifest, 'dependencies' => [$entry]]);
        (new ManifestWriter())->write($authoringConfig);
        $manifestBytes = file_get_contents($authoringConfig->manifestPath);
        $runtimeBytes = file_get_contents($runtimeFile);
        $service = new DependencyAuthoringService($authoringConfig, new DependencyMetadataResolver(), new RuntimeInspector($authoringConfig->runtime), new ManifestWriter(), new ManagedSourceRegistry(new GitHubReleaseManagedSource($github)), new AdminGovernanceExporter());
        $cases = [
            ['slug' => 'fixture', 'path' => $entry['path'], 'plan' => true],
            ['slug' => 'fixture', 'path' => 'wp-content/plugins/relocated-fixture', 'plan' => true],
            ['slug' => 'fixture', 'path' => 'wp-content/plugins/relocated-fixture', 'plan' => false],
            ['slug' => 'replacement-fixture', 'path' => $entry['path'], 'plan' => true],
        ];
        $alias = 'wp-content/plugins/FIXTURE';
        if (is_dir($authoringRoot . '/' . $alias)) {
            $cases[] = ['slug' => 'replacement-fixture', 'path' => $alias, 'plan' => true];
        }
        foreach ($cases as $case) {
            $reason = '';
            $options = ['source' => 'github-release', 'kind' => 'plugin', 'slug' => $case['slug'], 'path' => $case['path'], 'github-repository' => 'fixture/project', 'github-release-asset-pattern' => 'package.zip', 'version' => '1.0.0', 'force' => true, 'replace' => true];
            try {
                if ($case['plan']) { $service->planAddDependency($options); }
                else { $service->addDependency($options); }
            } catch (RuntimeException $e) { $reason = $e->getMessage(); }
            $assert(str_contains($reason, 'Sigstore verification failed'), 'Replacement, relocation and filesystem aliases retain required verification before ZIP extraction.');
            $assert(file_get_contents($authoringConfig->manifestPath) === $manifestBytes && file_get_contents($runtimeFile) === $runtimeBytes, 'Rejected authoring operations preserve manifest and installed runtime bytes.');
        }
    } finally {
        putenv($secret === false ? 'WP_CORE_BASE_SIGSTORE_TEST_SECRET' : 'WP_CORE_BASE_SIGSTORE_TEST_SECRET=' . $secret);
        if (is_resource($process)) { proc_terminate($process); foreach ($pipes as $pipe) fclose($pipe); proc_close($process); }
        (new RuntimeInspector([]))->clearPath($root);
    }
}
