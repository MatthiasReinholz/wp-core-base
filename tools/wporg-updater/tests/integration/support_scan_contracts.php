<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\DependencyScanner;
use WpOrgPluginUpdater\GitHubReleaseClient;
use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\ManagedDependencySource;
use WpOrgPluginUpdater\ManagedSourceRegistry;
use WpOrgPluginUpdater\ManifestWriter;
use WpOrgPluginUpdater\PrBodyRenderer;
use WpOrgPluginUpdater\ReleaseClassifier;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\SupportForumClient;
use WpOrgPluginUpdater\SupportForumScanLimits;
use WpOrgPluginUpdater\SyncExecutionResult;
use WpOrgPluginUpdater\SyncReport;
use WpOrgPluginUpdater\TempWorkspace;
use WpOrgPluginUpdater\Updater;
use WpOrgPluginUpdater\WordPressOrgClient;

/** @param callable(bool,string):void $assert */
function run_support_scan_contract_tests(callable $assert): void
{
    run_support_scan_metadata_extraction_tests($assert);
    run_support_scan_topic_extraction_tests($assert);
    $repoRoot = dirname(__DIR__, 4);
    $workspace = TempWorkspace::create($repoRoot, 'support-scan-integration');
    $environment = [];
    foreach (['MAX_PAGES', 'MAX_REQUESTS', 'MAX_TOPICS', 'MAX_SECONDS', 'REQUEST_TIMEOUT_SECONDS'] as $suffix) {
        $name = 'WP_CORE_BASE_SUPPORT_FORUM_' . $suffix;
        $environment[$name] = getenv($name);
        putenv($name);
    }
    try {
        $configuration = Config::fromArray($workspace->path(), ['profile' => 'content-only']);
        $defaults = $configuration->supportForumScanLimits();
        $assert($defaults->maxPages === 10 && $defaults->maxRequests === 100 && $defaults->maxSeconds === 30, 'Support scan defaults are bounded.');
        $data = ['profile' => 'content-only', 'automation' => ['support_forum' => ['max_pages' => 3, 'max_topics' => 8]]];
        $configuration = Config::fromArray($workspace->path(), $data);
        $assert($configuration->supportForumScanLimits()->maxPages === 3, 'Manifest scan limits are honored.');
        putenv('WP_CORE_BASE_SUPPORT_FORUM_MAX_PAGES=2');
        $assert($configuration->supportForumScanLimits()->maxPages === 2, 'Environment scan limits override manifest values.');
        $assert($configuration->toArray()['automation']['support_forum']['max_pages'] === 3, 'Environment scan limits are never persisted.');
        foreach (['', '0', '-1', '1.5', 'many', '101', '99999999999999999999'] as $invalid) {
            putenv('WP_CORE_BASE_SUPPORT_FORUM_MAX_PAGES=' . $invalid);
            $rejected = false;
            try { $configuration->supportForumScanLimits(); } catch (RuntimeException) { $rejected = true; }
            $assert($rejected, 'Invalid environment scan limit rejected: ' . $invalid);
        }
        putenv('WP_CORE_BASE_SUPPORT_FORUM_MAX_PAGES');
        foreach ([['max_topics' => 0], ['max_requests' => '2'], ['max_seconds' => 301], ['typo' => 3]] as $invalid) {
            $rejected = false;
            try { Config::fromArray($workspace->path(), ['profile' => 'content-only', 'automation' => ['support_forum' => $invalid]]); } catch (RuntimeException) { $rejected = true; }
            $assert($rejected, 'Invalid manifest scan limits are rejected.');
        }

        $partial = static function (string $url, array $options): string {
            return str_ends_with($url, '/feed/') ? '<rss><channel></channel></rss>'
                : '<div class="bbp-pagination-links"><a class="page-numbers">50</a></div>';
        };
        $created = support_scan_fixture($workspace->path() . '/new', $partial);
        $assert($created['errors'] === [], 'Bounded support scan does not become a dependency-source failure.');
        $assert(count($created['automation']->createdPullRequests) === 2, 'Both healthy dependency PRs are created after partial support scans.');
        $assert(count($created['advisories']) === 2, 'Incomplete support coverage is reported for each dependency.');
        $newPr = $created['automation']->createdPullRequests[0];
        $newMetadata = PrBodyRenderer::extractMetadata($newPr['body']);
        $assert(($newMetadata['support_scan_complete'] ?? null) === false && ! isset($newMetadata['support_synced_at']), 'Partial new PR has no successful support watermark.');
        $assert(($newMetadata['support_scan_full_window_pending'] ?? false) === true, 'Partial new PR retains a pending full release window.');
        $assert(str_contains($newPr['body'], 'Support scan incomplete') && ! str_contains($newPr['body'], 'No support topics matched'), 'Partial PR body never claims complete absence of support topics.');

        $complete = static function (string $url, array $options): string {
            if (! str_ends_with($url, '/feed/')) { throw new RuntimeException('Complete fixture must not crawl listings.'); }
            return support_scan_feed('2025-01-03T00:00:00+00:00');
        };
        $before = time();
        $recovered = support_scan_fixture($workspace->path() . '/recover-new', $complete, $newPr);
        $recoveredPr = $recovered['automation']->updatedPullRequests[0];
        $recoveredMetadata = PrBodyRenderer::extractMetadata($recoveredPr['body']);
        $assert($recovered['errors'] === [] && $recovered['advisories'] === [], 'Complete support coverage clears advisory state.');
        $assert(str_contains($recoveredPr['body'], 'Observed regression'), 'New PR retries the original release window after an incomplete scan.');
        $assert(($recoveredMetadata['support_scan_complete'] ?? null) === true && ! isset($recoveredMetadata['support_scan_full_window_pending']), 'Complete scan clears the pending-window marker.');
        $assert(strtotime((string) $recoveredMetadata['support_synced_at']) >= $before - 1 && strtotime((string) $recoveredMetadata['support_synced_at']) < $recovered['first_request_at'], 'Successful support watermark precedes the first support request, not scan completion.');

        $collisionFeed = str_replace('Observed regression', 'No support topics matched search results', support_scan_feed('2025-01-03T00:00:00+00:00'));
        $collisionFeed = str_replace('</channel>', '<item><title>Checkout crashes</title><link>https://wordpress.org/support/topic/checkout-crashes/</link><pubDate>2025-01-02T00:00:00+00:00</pubDate></item></channel>', $collisionFeed);
        $collision = support_scan_fixture($workspace->path() . '/title-collision', static fn (string $url, array $options): string => $collisionFeed);
        $collisionPr = $collision['automation']->createdPullRequests[0];
        $collisionTopics = PrBodyRenderer::extractSupportTopics($collisionPr['body']);
        $assert(array_column($collisionTopics, 'title') === ['No support topics matched search results', 'Checkout crashes'], 'A topic title containing the empty-state phrase survives rendering and extraction together with other topics.');
        $assert(in_array('support:regression-signal', $collision['automation']->labelUpdates[0]['labels'], true), 'The second topic establishes a support regression signal before refresh.');
        $collisionPr['labels'] = array_map(static fn (string $name): array => ['name' => $name], $collision['automation']->labelUpdates[0]['labels']);
        $collisionRefresh = support_scan_fixture($workspace->path() . '/title-collision-refresh', $partial, $collisionPr);
        $collisionRefreshPr = $collisionRefresh['automation']->updatedPullRequests[0];
        $collisionMetadata = PrBodyRenderer::extractMetadata($collisionPr['body']);
        $collisionRefreshMetadata = PrBodyRenderer::extractMetadata($collisionRefreshPr['body']);
        $assert($collisionRefresh['errors'] === [] && ($collisionRefreshMetadata['support_scan_complete'] ?? true) === false, 'An incomplete refresh with a colliding title remains advisory.');
        $assert(PrBodyRenderer::extractSupportTopics($collisionRefreshPr['body']) === $collisionTopics, 'An incomplete refresh retains every prior topic when one title contains the empty-state phrase.');
        $assert($collisionRefreshMetadata['support_synced_at'] === $collisionMetadata['support_synced_at'], 'An incomplete refresh with a colliding title preserves its verified watermark.');
        $assert(in_array('support:new-topics', $collisionRefresh['automation']->labelUpdates[0]['labels'], true)
            && in_array('support:regression-signal', $collisionRefresh['automation']->labelUpdates[0]['labels'], true), 'An incomplete refresh with a colliding title retains both support labels.');

        $boundaryAt = gmdate(DATE_ATOM, strtotime((string) $recoveredMetadata['support_synced_at']) + 1);
        $boundaryFeed = support_scan_feed($boundaryAt);
        $boundaryFeed = str_replace('</channel>', '<item><title>Boundary topic</title><link>https://wordpress.org/support/topic/boundary/</link><pubDate>' . $boundaryAt . '</pubDate></item></channel>', $boundaryFeed);
        $boundary = support_scan_fixture($workspace->path() . '/second-boundary', static fn (string $url, array $options): string => $boundaryFeed, $recoveredPr);
        $boundaryTopics = PrBodyRenderer::extractSupportTopics($boundary['automation']->updatedPullRequests[0]['body']);
        $assert(count($boundaryTopics) === 2 && in_array('Boundary topic', array_column($boundaryTopics, 'title'), true), 'One-second watermark overlap keeps arrivals in the scan-start second and deduplicates prior URLs.');

        // Preserve a known signal even if the old body no longer contains a parseable topic list.
        $prior = $newPr;
        $priorMetadata = $newMetadata;
        $priorMetadata['support_synced_at'] = '2026-01-20T00:00:00+00:00';
        $priorMetadata['support_scan_complete'] = true;
        unset($priorMetadata['support_scan_full_window_pending']);
        $prior['body'] = '<!-- wporg-update-metadata: ' . json_encode($priorMetadata, JSON_THROW_ON_ERROR) . ' -->';
        $prior['labels'] = [['name' => 'automation:dependency-update'], ['name' => 'support:regression-signal']];
        $retargeted = support_scan_fixture($workspace->path() . '/retarget', $partial, $prior, '1.1.1', '2025-02-01T00:00:00+00:00');
        $retargetedPr = $retargeted['automation']->updatedPullRequests[0];
        $retargetedMetadata = PrBodyRenderer::extractMetadata($retargetedPr['body']);
        $assert($retargeted['errors'] === [] && $retargetedMetadata['target_version'] === '1.1.1', 'Patch PR refresh still completes after a bounded scan.');
        $assert($retargetedMetadata['support_synced_at'] === $priorMetadata['support_synced_at'], 'Incomplete retarget preserves the last successful support watermark.');
        $assert(in_array('support:regression-signal', $retargeted['automation']->labelUpdates[0]['labels'], true), 'Incomplete refresh retains prior support labels even without parsed topics.');
        $retargetedPr['head'] = $newPr['head'];
        $recoveredRetarget = support_scan_fixture($workspace->path() . '/recover-retarget', static fn (string $url, array $options): string => support_scan_feed('2025-02-03T00:00:00+00:00'), $retargetedPr, '1.1.1', '2025-02-01T00:00:00+00:00');
        $assert(str_contains($recoveredRetarget['automation']->updatedPullRequests[0]['body'], 'Observed regression'), 'Retargeted PR retries its whole new release window despite an older advanced watermark.');

        $withPriorTopics = $recoveredPr;
        $withPriorTopics['head'] = $newPr['head'];
        $preserved = support_scan_fixture($workspace->path() . '/preserve-topics', $partial, $withPriorTopics);
        $assert(str_contains($preserved['automation']->updatedPullRequests[0]['body'], 'Observed regression'), 'Partial refresh preserves previously observed support topics.');
        $preservedPr = $preserved['automation']->updatedPullRequests[0];
        $preservedMetadata = PrBodyRenderer::extractMetadata($preservedPr['body']);
        $assert($preservedMetadata['support_synced_at'] === $recoveredMetadata['support_synced_at'] && ! isset($preservedMetadata['support_scan_full_window_pending']), 'Incomplete incremental scan retains its verified watermark without widening the retry window.');
        $successfulBoundary = strtotime((string) $preservedMetadata['support_synced_at']);
        $recentFeed = str_replace('2024-12-01T00:00:00+00:00', gmdate(DATE_ATOM, $successfulBoundary - 1), support_scan_feed(gmdate(DATE_ATOM, $successfulBoundary + 1)));
        $incrementalRecovery = support_scan_fixture($workspace->path() . '/recover-incremental', static function (string $url, array $options) use ($recentFeed): string {
            if (! str_ends_with($url, '/feed/')) { throw new RuntimeException('Incremental recovery should fit RSS without crawling release history.'); }
            return str_contains($url, '/scan-plugin/') ? $recentFeed : support_scan_feed('2025-01-03T00:00:00+00:00');
        }, $preservedPr);
        $incrementalMetadata = PrBodyRenderer::extractMetadata($incrementalRecovery['automation']->updatedPullRequests[0]['body']);
        $assert($incrementalRecovery['advisories'] === [] && ($incrementalMetadata['support_scan_complete'] ?? false) === true, 'RSS-only incremental recovery succeeds even when the feed cannot cover the old release window.');

        foreach ([null, 'not-a-timestamp', '2025-02-31T00:00:00+00:00', '2999-01-01T00:00:00+00:00'] as $index => $unverifiedWatermark) {
            $unverifiedMetadata = $recoveredMetadata;
            $unverifiedMetadata['updated_at'] = gmdate(DATE_ATOM);
            if ($unverifiedWatermark === null) { unset($unverifiedMetadata['support_synced_at']); }
            else { $unverifiedMetadata['support_synced_at'] = $unverifiedWatermark; }
            $unverifiedPr = $recoveredPr;
            $unverifiedPr['body'] = '<!-- wporg-update-metadata: ' . json_encode($unverifiedMetadata, JSON_THROW_ON_ERROR) . ' -->';
            $unverified = support_scan_fixture($workspace->path() . '/unverified-' . $index, static function (string $url, array $options) use ($recentFeed): string {
                if (str_ends_with($url, '/feed/')) { return $recentFeed; }
                return '<div class="bbp-pagination-links"><a class="page-numbers">50</a></div>';
            }, $unverifiedPr);
            $unverifiedResult = PrBodyRenderer::extractMetadata($unverified['automation']->updatedPullRequests[0]['body']);
            $assert(($unverifiedResult['support_scan_complete'] ?? true) === false && ($unverifiedResult['support_scan_full_window_pending'] ?? false) === true,
                'Missing, invalid, or future support watermark requires full release coverage instead of trusting updated_at.');
        }
        $failedSupport = support_scan_fixture($workspace->path() . '/support-error', static function (string $url, array $options): string { throw new RuntimeException('Support endpoint unavailable'); });
        $assert($failedSupport['errors'] === [] && count($failedSupport['automation']->createdPullRequests) === 2 && count($failedSupport['advisories']) === 2, 'Support transport failure is advisory and does not interrupt healthy dependency PRs.');

        $result = new SyncExecutionResult([], [], [], $created['advisories']);
        $report = $result->toSyncReport();
        SyncReport::write($report, $workspace->path() . '/report.json');
        $report = SyncReport::read($workspace->path() . '/report.json');
        $assert($result->exitCode(true) === 0 && $report['status'] === 'success' && $report['warning_count'] === 0 && $report['advisory_warning_count'] === 2, 'Strict source handling permits advisory-only success and persists a separate advisory count.');
        $assert(str_contains(SyncReport::renderSummary($report), '### Advisory Support Warnings'), 'Sync summary makes incomplete advisory coverage visible.');
        $sourceFailure = new SyncExecutionResult([], ['Source failed'], [], ['Support incomplete']);
        $assert($sourceFailure->exitCode(true) === 3 && $sourceFailure->toSyncReport()['status'] === 'warning', 'Genuine source warnings retain strict exit status with advisory warnings.');
        $fatal = new SyncExecutionResult(['Fatal'], [], [], ['Support incomplete']);
        $assert($fatal->exitCode(true) === 1 && $fatal->toSyncReport()['status'] === 'failure', 'Fatal errors remain fatal with advisory warnings.');
        $issues = new FakeGitHubAutomationClient();
        $issues->openIssues = [['number' => 7, 'title' => 'wp-core-base dependency source failures']];
        SyncReport::syncIssue($issues, $report);
        $assert(count($issues->closedIssues) === 1 && $issues->createdIssues === [], 'Advisory-only success clears prior source failures without opening a source-failure issue.');
        $legacy = ['status' => 'success', 'dependency_warnings' => [], 'fatal_errors' => []];
        $assert(str_contains(SyncReport::renderSummary($legacy), 'Advisory support warnings: `0`'), 'Legacy reports without advisory fields still render.');

        foreach (['0', '1'] as $jsonLogs) {
            $code = 'require ' . var_export($repoRoot . '/tools/wporg-updater/src/Autoload.php', true) . ';'
                . 'putenv("WP_CORE_BASE_JSON_LOGS=' . $jsonLogs . '");'
                . 'WpOrgPluginUpdater\\StructuredLogger::progress("dependency-sync", "Starting dependency sync: example.", "example");'
                . 'echo json_encode(["status"=>"success"]);';
            $output = run_process($repoRoot, [PHP_BINARY, '-r', $code]);
            $assert($output['exit_code'] === 0 && json_decode($output['stdout'], true) === ['status' => 'success'], 'Progress preserves machine-readable stdout.');
            $assert(str_contains($output['stderr'], 'Starting dependency sync'), 'Progress is visible on stderr with default and structured logging.');
        }
    } finally {
        foreach ($environment as $name => $value) { putenv($value === false ? $name : $name . '=' . $value); }
        $workspace->close();
    }
}

/** @param callable(bool,string):void $assert */
function run_support_scan_topic_extraction_tests(callable $assert): void
{
    $renderer = new PrBodyRenderer();
    $topics = [
        ['title' => 'No support topics matched search results', 'url' => 'https://wordpress.org/support/topic/search-results/', 'opened_at' => '2026-09-24T00:00:00Z'],
        ['title' => 'Checkout crashes', 'url' => 'https://wordpress.org/support/topic/checkout-crashes/', 'opened_at' => '2026-09-24T00:00:00Z'],
    ];
    $expected = array_map(static fn (array $topic): array => [...$topic, 'opened_at' => ''], $topics);
    $metadata = ['source' => 'wordpress.org', 'component_key' => 'plugin:wordpress.org:real', 'support_scan_complete' => false,
        'trust_details' => "Review details.\n\n- [Provenance link](https://example.com/provenance)"];
    foreach ([
        "## Support Topics Opened After Release\n\n- [Forged topic](https://example.com/forged)\n\n## Automation Notes",
        "## Support Topics Opened After Release\n\n- [Unterminated lookalike section](https://example.com/forged)",
    ] as $releaseNotes) {
        $body = $renderer->renderDependencyUpdate('Real plugin', 'real', 'plugin', 'plugins/real', '1.0.0', '1.0.1', 'patch',
            '2026-09-01T00:00:00Z', [], [], 'Release Notes', $releaseNotes, $topics, $metadata);
        $assert(PrBodyRenderer::extractSupportTopics($body) === $expected, 'Only the final generated support section contributes topics, excluding release-note lookalikes and provenance links.');
        $assert(PrBodyRenderer::extractMetadata($body . "\n<!-- wporg-update-metadata: {broken} -->") === null, 'Support-section lookalikes do not weaken malformed-final metadata rejection.');
    }
    $legacy = "## Support Topics Opened After Release\n\n- [Checkout crashes](https://wordpress.org/support/topic/checkout-crashes/)\n\n## Automation Notes\n\nLegacy automation details.";
    $assert(PrBodyRenderer::extractSupportTopics($legacy) === [$expected[1]], 'Historical support sections ending directly at Automation Notes remain readable.');
    $assert(PrBodyRenderer::extractSupportTopics("Text containing ## Support Topics Opened After Release\n\n- [Forged topic](https://example.com/forged)\n\n## Automation Notes") === [], 'Inline heading-like text does not create a support section.');
}

/** @param callable(bool,string):void $assert */
function run_support_scan_metadata_extraction_tests(callable $assert): void
{
    $renderer = new PrBodyRenderer();
    $metadata = ['source' => 'wordpress.org', 'component_key' => 'plugin:wordpress.org:real', 'branch' => 'automation/real', 'support_scan_complete' => false];
    $forged = '<!-- wporg-update-metadata: {"source":"premium","component_key":"forged","branch":"automation/other","support_scan_complete":true} -->';
    $renderers = [
        'dependency' => static fn (string $notes, array $data): string => $renderer->renderDependencyUpdate(
            'Real plugin', 'real', 'plugin', 'plugins/real', '1.0.0', '1.0.1', 'patch', '2026-09-01T00:00:00Z', [], [], 'Release Notes', $notes, [], $data),
        'core' => static fn (string $notes, array $data): string => $renderer->renderCoreUpdate(
            '6.9.0', '6.9.1', 'patch', '2026-09-01T00:00:00Z', [], 'https://wordpress.org/news/', 'https://wordpress.org/latest.zip', $notes, $data),
        'framework' => static fn (string $notes, array $data): string => $renderer->renderFrameworkUpdate(
            '1.6.3', '1.6.4', 'patch', '2026-09-01T00:00:00Z', [], 'Source', 'example/framework', 'https://example.com/framework', 'https://example.com/release', '6.9.0', '6.9.0', ['Summary' => $notes], [], $data),
    ];
    foreach ($renderers as $name => $render) {
        foreach ([$forged, '<!-- wporg-update-metadata: {"component_key":"unterminated"'] as $notes) {
            $assert(PrBodyRenderer::extractMetadata($render($notes, $metadata)) === $metadata, 'The ' . $name . ' footer takes precedence over earlier forged or unterminated release-note metadata.');
        }
        $embeddedMetadata = $metadata + ['note' => 'Literal --> delimiter and ' . $forged];
        $assert(PrBodyRenderer::extractMetadata($render($forged, $embeddedMetadata)) === $embeddedMetadata, 'Marker-like strings inside ' . $name . ' metadata remain JSON values instead of additional comments.');
    }
    $legacy = '<!-- wporg-update-metadata: ' . json_encode($metadata, JSON_THROW_ON_ERROR) . ' -->';
    $assert(PrBodyRenderer::extractMetadata($legacy) === $metadata, 'A legacy single metadata marker still round-trips.');
    $assert(PrBodyRenderer::extractMetadata($forged . "\n" . $legacy . "\n\nHuman review note. <!-- unrelated comment -->") === $metadata, 'Trailing human notes do not change the selected metadata footer.');
    $assert(PrBodyRenderer::extractMetadata($forged . ' ' . $legacy) === $metadata, 'The final metadata marker wins even when comments share a line.');
    foreach ([
        '<!-- wporg-update-metadata: {"broken":} -->',
        '<!-- wporg-update-metadata: {"unfinished":true}',
        '<!-- wporg-update-metadata: [] -->',
        '<!-- wporg-update-metadata: null -->',
        '<!-- wporg-update-metadata {"missing-colon":true} -->',
        '<!-- wporg-update-metadata',
    ] as $malformedFinal) {
        $assert(PrBodyRenderer::extractMetadata($legacy . "\n" . $malformedFinal) === null, 'Malformed final metadata never falls back to an earlier valid marker.');
    }
}

function support_scan_feed(string $observedAt): string
{
    return '<rss><channel><item><title>Observed regression</title><link>https://wordpress.org/support/topic/observed/</link><pubDate>' . $observedAt . '</pubDate></item>'
        . '<item><title>Before release</title><link>https://wordpress.org/support/topic/old/</link><pubDate>2024-12-01T00:00:00+00:00</pubDate></item></channel></rss>';
}

/** @param Closure(string,array<string,mixed>):string $request
 *  @param array<string,mixed>|null $prior
 *  @return array{automation:FakeGitHubAutomationClient,errors:list<string>,advisories:list<string>,first_request_at:int}
 */
function support_scan_fixture(string $root, Closure $request, ?array $prior = null, string $latest = '1.1.0', string $releaseAt = '2025-01-01T00:00:00+00:00'): array
{
    mkdir($root, 0700, true);
    $dependencies = [];
    $inspector = new RuntimeInspector(Config::fromArray($root, ['profile' => 'content-only'])->runtime);
    foreach (['scan-plugin', 'healthy-plugin'] as $slug) {
        mkdir($root . '/cms/plugins/' . $slug, 0700, true);
        file_put_contents($root . '/cms/plugins/' . $slug . '/' . $slug . '.php', "<?php\n/*\nPlugin Name: Test Plugin\nVersion: 1.0.0\n*/\n");
        $dependencies[] = ['kind' => 'plugin', 'slug' => $slug, 'source' => 'wordpress.org', 'management' => 'managed',
            'path' => 'cms/plugins/' . $slug, 'main_file' => $slug . '.php', 'version' => '1.0.0',
            'checksum' => $inspector->computeChecksum($root . '/cms/plugins/' . $slug)];
    }
    $config = Config::fromArray($root, ['profile' => 'content-only', 'dependencies' => $dependencies]);
    (new ManifestWriter())->write($config);
    $source = new class($latest, $releaseAt) implements ManagedDependencySource {
        public function __construct(private readonly string $latest, private readonly string $releaseAt) {}
        public function key(): string { return 'wordpress.org'; }
        public function fetchCatalog(array $dependency): array { return ['latest_version' => $this->latest, 'latest_release_at' => $this->releaseAt]; }
        public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
        { return ['version' => $targetVersion, 'release_at' => $fallbackReleaseAt, 'notes_text' => 'Bug fix.', 'notes_markup' => '<p>Bug fix.</p>']; }
        public function supportsForumSync(array $dependency): bool { return true; }
        public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void
        {
            $zip = new ZipArchive();
            if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Cannot write fixture archive.'); }
            $slug = (string) $dependency['slug'];
            $zip->addFromString($slug . '/' . $slug . '.php', "<?php\n/*\nPlugin Name: Test Plugin\nVersion: " . $releaseData['version'] . "\n*/\n");
            $zip->close();
        }
    };
    $automation = new FakeGitHubAutomationClient();
    $git = new FakeGitRunner();
    if ($prior !== null) {
        $metadata = PrBodyRenderer::extractMetadata((string) $prior['body']);
        $branch = (string) $metadata['branch'];
        $prior['head'] = ['ref' => $branch, 'sha' => 'prior-sha', 'repo' => ['full_name' => 'example/site']];
        $prior['base'] = ['ref' => 'main', 'repo' => ['full_name' => 'example/site']];
        $prior['labels'] ??= [['name' => 'automation:dependency-update']];
        $prior['state'] = 'open';
        $automation->openPullRequests = [$prior];
        $git->remoteBranches[$branch] = 'prior-sha';
    }
    $firstRequestAt = PHP_INT_MAX;
    $transport = static function (string $url, array $options) use ($request, &$firstRequestAt): string {
        $firstRequestAt = min($firstRequestAt, time());
        return $request($url, $options);
    };
    $http = new HttpClient();
    $updater = new Updater($config, new DependencyScanner(), new WordPressOrgClient($http), new GitHubReleaseClient($http),
        new ManagedSourceRegistry($source), new SupportForumClient($http, 1, new SupportForumScanLimits(maxPages: 1), request: $transport),
        new ReleaseClassifier(), new PrBodyRenderer(), $automation, $git, new RuntimeInspector($config->runtime), new ManifestWriter(), $http);
    $errors = $updater->sync();
    return ['automation' => $automation, 'errors' => $errors, 'advisories' => $updater->lastRunAdvisoryWarnings(), 'first_request_at' => $firstRequestAt];
}
