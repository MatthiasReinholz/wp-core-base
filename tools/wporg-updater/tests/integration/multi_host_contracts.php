<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\DownstreamScaffolder;
use WpOrgPluginUpdater\FrameworkConfig;
use WpOrgPluginUpdater\FrameworkReleaseArtifactBuilder;
use WpOrgPluginUpdater\FrameworkInstaller;
use WpOrgPluginUpdater\FrameworkWriter;
use WpOrgPluginUpdater\RuntimeInspector;

/**
 * @param callable(bool,string):void $assert
 * @param callable(string):string $normalizeWorkflowExample
 */
function run_multi_host_contract_tests(
    callable $assert,
    string $repoRoot,
    string $tempRoot,
    callable $normalizeWorkflowExample,
): void {
    $scaffoldRoot = sys_get_temp_dir() . '/wporg-scaffold-gitlab-' . bin2hex(random_bytes(4));
    mkdir($scaffoldRoot, 0777, true);

    $scaffolder = new DownstreamScaffolder($repoRoot, $scaffoldRoot);
    $scaffolder->scaffold('vendor/wp-core-base', 'content-only-default', 'cms', true, false, 'gitlab');

    $manifest = (string) file_get_contents($scaffoldRoot . '/.wp-core-base/manifest.php');
    $pipeline = (string) file_get_contents($scaffoldRoot . '/.gitlab-ci.yml');
    $documentedPipeline = (string) file_get_contents($repoRoot . '/docs/examples/downstream-gitlab-ci.yml');
    $framework = FrameworkConfig::load($scaffoldRoot);
    $config = Config::load($scaffoldRoot);
    $inspector = new RuntimeInspector($config->runtime);

    $assert(str_contains($manifest, "'provider' => 'gitlab'"), 'Expected GitLab scaffolded manifest to set automation.provider=gitlab.');
    $assert(str_contains($manifest, "CI_API_V4_URL"), 'Expected GitLab scaffolded manifest to derive automation.api_base from CI_API_V4_URL.');
    $assert(is_file($scaffoldRoot . '/.gitlab-ci.yml'), 'Expected GitLab scaffold to create .gitlab-ci.yml.');
    $assert(! is_file($scaffoldRoot . '/.github/workflows/wporg-updates.yml'), 'Expected GitLab scaffold to omit GitHub workflow files.');
    $assert($config->automationProvider() === 'gitlab', 'Expected GitLab scaffolded config to normalize automation.provider.');
    $assert($config->automationApiBase() === 'https://gitlab.com/api/v4', 'Expected GitLab scaffolded config to default automation.api_base to gitlab.com.');
    $previousCiApiV4Url = getenv('CI_API_V4_URL');
    putenv('CI_API_V4_URL=https://gitlab.example.com/api/v4');
    $selfManagedConfig = Config::load($scaffoldRoot);
    $assert($selfManagedConfig->automationApiBase() === 'https://gitlab.example.com/api/v4', 'Expected GitLab scaffolds to honor self-managed CI_API_V4_URL values.');
    putenv($previousCiApiV4Url === false ? 'CI_API_V4_URL' : 'CI_API_V4_URL=' . $previousCiApiV4Url);
    $assert(isset($framework->managedFiles()['.gitlab-ci.yml']), 'Expected framework metadata to track the GitLab pipeline file.');
    $assert(! isset($framework->managedFiles()['.github/workflows/wporg-updates.yml']), 'Expected GitLab framework metadata to omit GitHub workflow files.');
    $assert(
        $normalizeWorkflowExample($pipeline) === $normalizeWorkflowExample($documentedPipeline),
        'Expected documented GitLab pipeline example to match scaffolded output.'
    );
    $assert(str_contains($pipeline, 'wporg-updater.php sync'), 'Expected GitLab scaffold to include sync automation.');
    $assert(str_contains($pipeline, 'wporg-updater.php pr-blocker'), 'Expected GitLab scaffold to include blocker automation.');
    $assert(str_contains($pipeline, 'wporg-updater.php framework-sync'), 'Expected GitLab scaffold to include framework-sync automation.');
    $assert(str_contains($pipeline, 'resource_group: wp-core-base-dependency-sync'), 'Expected GitLab scaffold to serialize dependency sync jobs.');

    $compactRoot = sys_get_temp_dir() . '/wporg-scaffold-gitlab-compact-' . bin2hex(random_bytes(4));
    mkdir($compactRoot, 0777, true);
    (new DownstreamScaffolder($repoRoot, $compactRoot))->scaffold('vendor/wp-core-base', 'content-only-image-first-compact', 'cms', true, false, 'gitlab');
    $compactPipeline = (string) file_get_contents($compactRoot . '/.gitlab-ci.yml');
    $assert(! str_contains($compactPipeline, 'wp_core_base_validate_runtime:'), 'Expected compact GitLab scaffold to omit the standalone runtime-validation job.');

    $frameworkInstallRoot = sys_get_temp_dir() . '/wporg-framework-install-gitlab-' . bin2hex(random_bytes(4));
    mkdir($frameworkInstallRoot, 0777, true);
    (new DownstreamScaffolder($repoRoot, $frameworkInstallRoot))->scaffold('vendor/wp-core-base', 'content-only-default', 'cms', true, false, 'gitlab');
    $payloadRoot = sys_get_temp_dir() . '/wporg-framework-gitlab-payload-' . bin2hex(random_bytes(4));
    mkdir($payloadRoot, 0777, true);
    $inspector->copyPath($repoRoot, $payloadRoot, FrameworkReleaseArtifactBuilder::excludedPaths());
    $inspector->clearPath($payloadRoot . '/.git');
    $payloadFramework = FrameworkConfig::load($payloadRoot)->withInstalledRelease(
        version: '9.9.9',
        wordPressCoreVersion: FrameworkConfig::load($payloadRoot)->baseline['wordpress_core'],
        managedComponents: FrameworkConfig::load($payloadRoot)->baseline['managed_components'],
        managedFiles: [],
        distributionPath: '.'
    );
    (new FrameworkWriter())->write($payloadFramework);
    $payloadTemplatePath = $payloadRoot . '/tools/wporg-updater/templates/downstream-gitlab-ci.yml.tpl';
    file_put_contents(
        $payloadTemplatePath,
        str_replace(
            'GITLAB_TOKEN is required for wp-core-base GitLab automation jobs.',
            'GITLAB_TOKEN is required for wp-core-base GitLab automation jobs from a newer framework release.',
            (string) file_get_contents($payloadTemplatePath)
        )
    );
    $installResult = (new FrameworkInstaller($frameworkInstallRoot, new RuntimeInspector(Config::load($frameworkInstallRoot)->runtime)))
        ->apply($payloadRoot, 'vendor/wp-core-base');
    $updatedFramework = FrameworkConfig::load($frameworkInstallRoot);
    $updatedPipeline = (string) file_get_contents($frameworkInstallRoot . '/.gitlab-ci.yml');
    $assert($updatedFramework->version === '9.9.9', 'Expected framework installer to update the pinned version for GitLab downstreams.');
    $assert(isset($updatedFramework->managedFiles()['.gitlab-ci.yml']), 'Expected GitLab downstream framework installs to keep tracking .gitlab-ci.yml.');
    $assert(! isset($updatedFramework->managedFiles()['.github/workflows/wporg-updates.yml']), 'Expected GitLab downstream framework installs to avoid GitHub workflow metadata.');
    $assert(in_array('.gitlab-ci.yml', $installResult['refreshed_files'], true), 'Expected GitLab framework installs to refresh .gitlab-ci.yml.');
    $assert(! file_exists($frameworkInstallRoot . '/.github/workflows/wporg-updates.yml'), 'Expected GitLab framework installs to avoid creating GitHub workflow files.');
    $assert(str_contains($updatedPipeline, 'from a newer framework release'), 'Expected GitLab framework installs to refresh the pipeline from the payload template.');

    $providerSwitchRoot = sys_get_temp_dir() . '/wporg-framework-provider-switch-' . bin2hex(random_bytes(4));
    mkdir($providerSwitchRoot, 0777, true);
    (new DownstreamScaffolder($repoRoot, $providerSwitchRoot))->scaffold('vendor/wp-core-base', 'content-only-default', 'cms', true, false, 'gitlab');
    $providerSwitchManifest = str_replace(
        ["'provider' => 'gitlab'", "getenv('CI_API_V4_URL') ?: 'https://gitlab.com/api/v4'"],
        ["'provider' => 'github'", "getenv('GITHUB_API_URL') ?: 'https://api.github.com'"],
        (string) file_get_contents($providerSwitchRoot . '/.wp-core-base/manifest.php')
    );
    file_put_contents($providerSwitchRoot . '/.wp-core-base/manifest.php', $providerSwitchManifest);
    $providerSwitchPayloadRoot = sys_get_temp_dir() . '/wporg-framework-provider-switch-payload-' . bin2hex(random_bytes(4));
    mkdir($providerSwitchPayloadRoot, 0777, true);
    $inspector->copyPath($repoRoot, $providerSwitchPayloadRoot, FrameworkReleaseArtifactBuilder::excludedPaths());
    $inspector->clearPath($providerSwitchPayloadRoot . '/.git');
    $providerSwitchPayloadFramework = FrameworkConfig::load($providerSwitchPayloadRoot)->withInstalledRelease(
        version: '9.9.10',
        wordPressCoreVersion: FrameworkConfig::load($providerSwitchPayloadRoot)->baseline['wordpress_core'],
        managedComponents: FrameworkConfig::load($providerSwitchPayloadRoot)->baseline['managed_components'],
        managedFiles: [],
        distributionPath: '.'
    );
    (new FrameworkWriter())->write($providerSwitchPayloadFramework);
    $providerSwitchTemplatePath = $providerSwitchPayloadRoot . '/tools/wporg-updater/templates/downstream-workflow.yml.tpl';
    file_put_contents(
        $providerSwitchTemplatePath,
        str_replace(
            'name: wp-core-base Updates',
            'name: wp-core-base Updates After Provider Switch',
            (string) file_get_contents($providerSwitchTemplatePath)
        )
    );
    $providerSwitchInstall = (new FrameworkInstaller($providerSwitchRoot, new RuntimeInspector(Config::load($providerSwitchRoot)->runtime)))
        ->apply($providerSwitchPayloadRoot, 'vendor/wp-core-base');
    $providerSwitchFramework = FrameworkConfig::load($providerSwitchRoot);
    $providerSwitchWorkflow = (string) file_get_contents($providerSwitchRoot . '/.github/workflows/wporg-updates.yml');
    $assert(in_array('.gitlab-ci.yml', $providerSwitchInstall['removed_files'], true), 'Expected provider switches to remove stale GitLab pipeline files when framework-managed files change.');
    $assert(! file_exists($providerSwitchRoot . '/.gitlab-ci.yml'), 'Expected provider switches to clean the stale GitLab pipeline file from disk.');
    $assert(isset($providerSwitchFramework->managedFiles()['.github/workflows/wporg-updates.yml']), 'Expected provider switches to track the new GitHub workflow file.');
    $assert(! isset($providerSwitchFramework->managedFiles()['.gitlab-ci.yml']), 'Expected provider switches to drop stale GitLab pipeline metadata.');
    $assert(str_contains($providerSwitchWorkflow, 'Updates After Provider Switch'), 'Expected provider switches to refresh GitHub workflows from the new payload.');

    $dirtyRoot = sys_get_temp_dir() . '/wporg-gitlab-runtime-' . bin2hex(random_bytes(4));
    mkdir($dirtyRoot . '/.gitea/workflows', 0777, true);
    file_put_contents($dirtyRoot . '/.gitea/workflows/ci.yml', "name: test\n");
    file_put_contents($dirtyRoot . '/.gitlab-ci.yml', "stages: []\n");

    $giteaRejected = false;
    $gitLabFileRejected = false;

    try {
        $inspector->assertPathIsClean($dirtyRoot);
    } catch (RuntimeException $exception) {
        $giteaRejected = str_contains($exception->getMessage(), '.gitea');
    }

    $inspector->clearPath($dirtyRoot);
    mkdir($dirtyRoot, 0777, true);
    file_put_contents($dirtyRoot . '/.gitlab-ci.yml', "stages: []\n");

    try {
        $inspector->assertPathIsClean($dirtyRoot);
    } catch (RuntimeException $exception) {
        $gitLabFileRejected = str_contains($exception->getMessage(), '.gitlab-ci.yml');
    }

    $inspector->clearPath($dirtyRoot);
    $inspector->clearPath($scaffoldRoot);
    $inspector->clearPath($compactRoot);
    $inspector->clearPath($frameworkInstallRoot);
    $inspector->clearPath($payloadRoot);
    $inspector->clearPath($providerSwitchRoot);
    $inspector->clearPath($providerSwitchPayloadRoot);

    $assert($giteaRejected, 'Expected runtime inspection to reject .gitea workflow metadata.');
    $assert($gitLabFileRejected, 'Expected runtime inspection to reject root .gitlab-ci.yml files in runtime payloads.');
}
