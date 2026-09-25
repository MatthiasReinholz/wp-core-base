<?php

declare(strict_types=1);

use WpOrgPluginUpdater\AbstractPremiumManagedSource;
use WpOrgPluginUpdater\FileChecksum;
use WpOrgPluginUpdater\FrameworkConfig;
use WpOrgPluginUpdater\FrameworkPayloadIdentity;
use WpOrgPluginUpdater\FrameworkReleaseSignature;
use WpOrgPluginUpdater\FrameworkReleaseSource;
use WpOrgPluginUpdater\FrameworkSyncer;
use WpOrgPluginUpdater\GitCommandRunner;
use WpOrgPluginUpdater\GitHubClient;
use WpOrgPluginUpdater\GitHubReleaseClient;
use WpOrgPluginUpdater\GitLabClient;
use WpOrgPluginUpdater\GitLabReleaseClient;
use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\PrBodyRenderer;
use WpOrgPluginUpdater\PremiumCredentialsStore;
use WpOrgPluginUpdater\ReleaseClassifier;
use WpOrgPluginUpdater\RuntimeInspector;

/** @param callable(bool,string):void $assert */
function run_http_security_contract_tests(callable $assert, string $repoRoot): void
{
    $root = sys_get_temp_dir() . '/wp-core-base-http-security-' . bin2hex(random_bytes(6));
    mkdir($root, 0700, true);
    $process = null;
    $pipes = [];
    $previousToken = getenv('WP_CORE_BASE_HTTP_TEST_TOKEN');
    $expectFailure = static function (callable $operation, string $message, ?string $fragment = null) use ($assert): void {
        $failure = null;
        try {
            $operation();
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
        $assert($failure !== null && ($fragment === null || str_contains($failure->getMessage(), $fragment)), $message . ($failure !== null ? ' [' . $failure->getMessage() . ']' : ''));
    };
    try {
        $certificateConfig = $root . '/openssl.cnf';
        file_put_contents($certificateConfig, "[req]\ndistinguished_name=dn\nx509_extensions=v3\n[dn]\nCN=localhost\n[v3]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:localhost,IP:127.0.0.1\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new RuntimeException('Unable to generate TLS fixture key.');
        }
        $csr = openssl_csr_new(['commonName' => 'localhost'], $key, ['config' => $certificateConfig, 'digest_alg' => 'sha256']);
        $certificate = $csr === false ? false : openssl_csr_sign($csr, null, $key, 1, ['config' => $certificateConfig, 'digest_alg' => 'sha256', 'x509_extensions' => 'v3']);
        if ($certificate === false || ! openssl_x509_export_to_file($certificate, $root . '/certificate.pem') || ! openssl_pkey_export_to_file($key, $root . '/private.pem')) {
            throw new RuntimeException('Unable to create TLS fixture certificate.');
        }
        chmod($root . '/private.pem', 0600);
        $process = proc_open([PHP_BINARY, $repoRoot . '/tools/wporg-updater/tests/fixtures/http-security/tls-server.php', $root], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start trusted TLS fixture.');
        }
        $deadline = microtime(true) + 5;
        while (! is_file($root . '/ready.json') && microtime(true) < $deadline) {
            usleep(20000);
        }
        if (! is_file($root . '/ready.json')) {
            throw new RuntimeException('TLS fixture did not become ready.');
        }
        $ports = json_decode((string) file_get_contents($root . '/ready.json'), true, flags: JSON_THROW_ON_ERROR);
        $origin = 'https://127.0.0.1:' . $ports[0];
        $otherOrigin = 'https://127.0.0.1:' . $ports[1];
        $client = new HttpClient(timeoutSeconds: 3, caFile: $root . '/certificate.pem');
        $options = ['allowed_redirect_hosts' => ['127.0.0.1', 'localhost'], 'follow_redirects' => true, 'max_body_bytes' => 1024 * 1024, 'retry_initial_delay_milliseconds' => 0, 'credential_origin' => $origin];
        $headers = ['Authorization' => 'Bearer secret', 'Proxy-Authorization' => 'proxy-secret', 'Cookie' => 'secret-cookie', 'PRIVATE-TOKEN' => 'private-secret', 'JOB-TOKEN' => 'job-secret', 'X-Premium-Key' => 'custom-secret', 'Accept' => 'text/plain'];
        $digest = str_repeat('a', 64);
        $readLog = static fn (): array => array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($root . '/requests.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        $contents = $client->getWithOptions($origin . '/start', $headers, $options);
        $assert(FileChecksum::extractSha256ForAsset($contents, 'package.zip') === $digest, 'A two-hop HTTPS sidecar GET retries a 503 and verifies its digest.');
        $log = $readLog();
        $assert(count(array_filter($log, static fn (array $request): bool => str_starts_with($request['path'], '/checksums'))) === 2, 'Retry transport follows the same redirect policy on the next attempt.');
        foreach ($log as $request) {
            if ($request['port'] === $ports[1]) {
                foreach (array_keys($headers) as $name) {
                    if ($name !== 'Accept') {
                        $assert(! isset($request['headers'][strtolower($name)]), 'Cross-port redirect strips ' . $name . '.');
                    }
                }
            }
        }
        $client->getWithOptions($origin . '/cross-host', $headers, $options);
        $final = array_values(array_filter($readLog(), static fn (array $request): bool => $request['path'] === '/final'));
        $assert(count($final) === 1 && ! isset($final[0]['headers']['authorization']) && ! isset($final[0]['headers']['x-premium-key']), 'A→B→A redirects never restore stripped credentials.');
        $client->getWithOptions($otherOrigin . '/direct', $headers, $options);
        $direct = array_values(array_filter($readLog(), static fn (array $request): bool => $request['path'] === '/direct'));
        $assert(! isset($direct[0]['headers']['authorization']) && ! isset($direct[0]['headers']['private-token']), 'Initial asset requests on a different port receive no API credentials.');
        $assert($client->getWithOptions($origin . '/query', [], $options) !== '', 'Query-only Location retains the existing path.');
        foreach (['/loop', '/missing-location', '/bad-scheme', '/userinfo', '/disallowed-host'] as $path) {
            $before = count($readLog());
            $expectFailure(fn () => $client->getWithOptions($origin . $path, $headers, $options), 'Unsafe redirect is rejected: ' . $path);
            $assert(count($readLog()) === $before + 1, 'Policy failures are not retried: ' . $path);
        }
        $expectFailure(fn () => $client->getWithOptions($origin . '/start', [], ['max_redirects' => 0] + $options), 'Redirect limit is enforced before the next request.', 'redirect limit exceeded');
        $expectFailure(fn () => $client->getWithOptions($origin . '/large', [], $options), 'Sidecar responses over one MiB are rejected.', 'configured byte limit');
        $expectFailure(fn () => (new HttpClient(timeoutSeconds: 2))->getWithOptions($origin . '/direct', [], ['retry_attempts' => 1]), 'The TLS fixture fails without explicit CA trust.');
        $expectFailure(fn () => $client->getWithOptions('http://127.0.0.1:' . $ports[0], [], $options), 'Initial HTTP URLs are rejected before sending credentials.', 'HTTPS');
        $expectFailure(fn () => $client->requestWithOptions('POST', $origin . '/cross-host', [], ['secret' => 'body'], null, true, $options), 'Sensitive request bodies cannot be redirected.', 'GET and HEAD');
        foreach (['GET', 'HEAD'] as $method) {
            foreach ([['token' => 'synthetic-body-secret'], null] as $jsonBody) {
                $before = count($readLog());
                $rawBody = $jsonBody === null ? 'token=synthetic-body-secret' : null;
                $expectFailure(
                    fn () => $client->requestWithOptions($method, $origin . '/cross-host', $headers, $jsonBody, $rawBody, true, $options),
                    $method . ' rejects redirect following with ' . ($jsonBody === null ? 'a raw body.' : 'a JSON body.'),
                    'request body'
                );
                $assert(count($readLog()) === $before, 'A body-bearing ' . $method . ' is rejected before any network request.');
            }
        }

        $expectFailure(
            fn () => (new GitHubClient($client, 'owner/project', 'fixture-token', $origin))->listOpenPullRequests(),
            'GitHub rejects a scalar entry in an otherwise valid open-PR JSON list instead of returning a partial inventory.',
            'invalid entry in the open pull request inventory'
        );
        $expectFailure(
            fn () => (new GitLabClient($client, '42', 'fixture-token', $origin . '/api/v4'))->listOpenPullRequests(),
            'GitLab rejects a scalar entry in an otherwise valid open-MR JSON list instead of returning a partial inventory.',
            'invalid entry in the open merge request inventory'
        );

        putenv('WP_CORE_BASE_HTTP_TEST_TOKEN=adapter-secret');
        $github = new GitHubReleaseClient($client, $origin);
        $dependency = ['slug' => 'fixture', 'source_config' => ['github_repository' => 'owner/project', 'github_token_env' => 'WP_CORE_BASE_HTTP_TEST_TOKEN']];
        $release = ['assets' => [['name' => 'package.zip.sha256', 'url' => $origin . '/start']]];
        $assert($github->checksumSha256ForAssetPattern($release, $dependency, '*.sha256', 'package.zip') === $digest, 'GitHub sidecar source enables safe redirect transport.');
        $release['assets'][0]['url'] = $otherOrigin . '/github-initial';
        $github->checksumSha256ForAssetPattern($release, $dependency, '*.sha256', 'package.zip');
        $gitlab = new GitLabReleaseClient($client, $origin . '/api/v4');
        $dependency = ['slug' => 'fixture', 'source_config' => ['gitlab_project' => 'group/project', 'gitlab_token_env' => 'WP_CORE_BASE_HTTP_TEST_TOKEN']];
        $release = ['assets' => ['links' => [['name' => 'package.zip.sha256', 'direct_asset_url' => $otherOrigin . '/gitlab-initial']]]];
        $assert($gitlab->checksumSha256ForAssetPattern($release, $dependency, '*.sha256', 'package.zip') === $digest, 'GitLab sidecar source uses the shared redirect policy.');
        foreach ($readLog() as $request) {
            if (in_array($request['path'], ['/github-initial', '/gitlab-initial'], true)) {
                $assert(! isset($request['headers']['authorization']) && ! isset($request['headers']['private-token']), 'Hosted source initial credentials are bound to the API origin.');
            }
        }

        $premium = new class ($client, new PremiumCredentialsStore(), $origin) extends AbstractPremiumManagedSource {
            public function __construct(HttpClient $client, PremiumCredentialsStore $store, private string $origin) { parent::__construct($client, $store); }
            public function key(): string { return 'fixture-premium'; }
            public function fetchCatalog(array $dependency): array { return []; }
            public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array { return []; }
            public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void { $this->downloadBinary($releaseData['url'], $destination, ['X-Premium-Key' => 'premium-secret', 'Cookie' => 'premium-cookie']); }
            protected function allowedApiHosts(): array { return ['127.0.0.1']; }
            protected function allowedDownloadHosts(): array { return ['127.0.0.1', 'localhost']; }
            protected function allowedCredentialOrigins(): array { return [$this->origin]; }
        };
        $premium->downloadReleaseToFile([], ['url' => $otherOrigin . '/premium-initial'], $root . '/premium.bin');
        $premium->downloadReleaseToFile([], ['url' => $origin . '/premium-origin'], $root . '/premium.bin');
        $premium->downloadReleaseToFile([], ['url' => $origin . '/cross-host'], $root . '/premium.bin');
        foreach ($readLog() as $request) {
            if ($request['path'] === '/premium-initial' || $request['path'] === '/final') {
                $assert(! isset($request['headers']['x-premium-key']) && ! isset($request['headers']['cookie']), 'Premium custom headers are stripped from initial cross-origin downloads and return redirects.');
            } elseif ($request['path'] === '/premium-origin') {
                $assert(($request['headers']['x-premium-key'] ?? '') === 'premium-secret', 'Explicit premium credential origins retain authorized initial credentials.');
            }
        }
        $expectFailure(fn () => $client->getWithOptions($origin . '/start', [], ['retry_attempts' => 1]), 'Ordinary API GET requests do not opt into redirects implicitly.', 'status 302');
        $expectFailure(fn () => $client->downloadToFileWithOptions($origin . '/direct', $root . '/oversized.bin', [], ['max_download_bytes' => 16]), 'Download transport enforces its byte ceiling.', 'configured byte limit');
        $assert(! file_exists($root . '/oversized.bin.part'), 'A rejected download leaves no partial artifact.');

        assert_framework_payload_identity_contracts($assert, $repoRoot, $root);
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
        putenv($previousToken === false ? 'WP_CORE_BASE_HTTP_TEST_TOKEN' : 'WP_CORE_BASE_HTTP_TEST_TOKEN=' . $previousToken);
        $remove = static function (string $path) use (&$remove): void {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                foreach (scandir($path) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        $remove($path . '/' . $entry);
                    }
                }
                rmdir($path);
            }
        };
        $remove($root);
    }
}

/** @param callable(bool,string):void $assert */
function assert_framework_payload_identity_contracts(callable $assert, string $repoRoot, string $root): void
{
    $framework = FrameworkConfig::load($repoRoot);
    $data = require $repoRoot . '/.wp-core-base/framework.php';
    $data['version'] = '99.0.0';
    $payloadMetadata = '<?php return ' . var_export($data, true) . ';';
    $archive = $root . '/signed.zip';
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to build signed payload fixture.');
    }
    $zip->addFromString('wp-core-base/.wp-core-base/framework.php', $payloadMetadata);
    $zip->close();
    $checksum = $archive . '.sha256';
    $signature = $checksum . '.sig';
    file_put_contents($checksum, hash_file('sha256', $archive) . '  signed.zip' . "\n");
    $privateKey = (string) file_get_contents($repoRoot . '/tools/wporg-updater/tests/fixtures/release-signing/private.pem');
    $publicKey = $repoRoot . '/tools/wporg-updater/tests/fixtures/release-signing/public.pem';
    FrameworkReleaseSignature::signChecksumFile($checksum, $signature, $privateKey);
    $source = new class ($archive, $checksum, $signature, $publicKey) implements FrameworkReleaseSource {
        public function __construct(private string $archive, private string $checksum, private string $signature, private string $publicKey) {}
        public function fetchStableReleases(FrameworkConfig $framework): array { return [['version' => '99.0.1']]; }
        public function releaseData(FrameworkConfig $framework, array $release): array { return ['version' => '99.0.1', 'release' => $release]; }
        public function downloadVerifiedReleaseAsset(FrameworkConfig $framework, array $release, string $destination): void
        {
            FrameworkReleaseSignature::verifyChecksumFile($this->checksum, $this->signature, $this->publicKey);
            FileChecksum::assertSha256Matches($this->archive, FileChecksum::extractSha256ForAsset((string) file_get_contents($this->checksum), 'signed.zip'), 'Signed fixture');
            copy($this->archive, $destination);
        }
    };
    $syncer = new FrameworkSyncer($framework, $repoRoot, null, $source, new ReleaseClassifier(), new PrBodyRenderer(), null, new GitCommandRunner($repoRoot), new RuntimeInspector([]));
    $failure = null;
    try {
        $syncer->checkOnlyReport();
    } catch (RuntimeException $exception) {
        $failure = $exception;
    }
    $assert($failure !== null && str_contains($failure->getMessage(), 'framework version mismatch'), 'A validly signed wrong-version payload is rejected before its install-plan callback.');
    mkdir($root . '/metadata/.wp-core-base', 0700, true);
    $metadataPath = $root . '/metadata/.wp-core-base/framework.php';
    foreach (['source', 'asset', 'downgrade'] as $case) {
        $changed = $data;
        $requested = '99.0.0';
        if ($case === 'source') {
            $changed['release_source']['reference'] = 'unexpected/project';
        } elseif ($case === 'asset') {
            $changed['distribution']['asset_name'] = 'unexpected.zip';
        } else {
            $changed['version'] = $requested = '0.0.1';
        }
        file_put_contents($metadataPath, '<?php return ' . var_export($changed, true) . ';');
        $failure = null;
        try {
            FrameworkPayloadIdentity::assertMatches(FrameworkConfig::load($root . '/metadata'), $framework, $requested);
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
        $assert($failure !== null, 'Framework payload identity rejects ' . $case . ' changes.');
    }
}
