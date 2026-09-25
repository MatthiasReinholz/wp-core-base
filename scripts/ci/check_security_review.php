<?php

declare(strict_types=1);

require __DIR__ . '/security_review_policy.php';
require __DIR__ . '/security_review_github.php';

use WpCoreBaseCi\SecurityReviewGitHub;
use WpCoreBaseCi\SecurityReviewPolicy;

try {
    $options = ['commit' => '', 'wait-seconds' => '0', 'repo' => ''];
    $live = false;
    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
        if ($argument === '--github') {
            $live = true;
        } elseif (preg_match('/^--(commit|wait-seconds|repo)=(.+)$/D', $argument, $match) === 1) {
            $options[$match[1]] = $match[2];
        } else {
            throw new RuntimeException('Unsupported security-review argument: ' . $argument);
        }
    }
    if (! ctype_digit($options['wait-seconds']) || (int) $options['wait-seconds'] > 3600) {
        throw new RuntimeException('--wait-seconds must be an integer from 0 to 3600.');
    }
    $root = dirname(__DIR__, 2);
    $file = $root . '/.github/security-review.json';
    $contents = file_get_contents($file);
    if (! is_string($contents)) {
        throw new RuntimeException('The source-repository security review register is missing.');
    }
    $register = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($register)) {
        throw new RuntimeException('Invalid security review register.');
    }
    $policy = new SecurityReviewPolicy();
    $register = $policy->validateRegister($register, $root);
    $report = ['status' => 'baseline_matches_review', 'scope' => 'offline_source_review',
        'open_scanner_findings' => count($register['findings']), 'open_manual_findings' => count($register['manual_findings']),
        'runtime_security_cleared' => false];
    if ($live) {
        $environmentRepository = getenv('GITHUB_REPOSITORY');
        if (getenv('GITHUB_ACTIONS') === 'true' && (! is_string($environmentRepository) || $environmentRepository === '')) {
            throw new RuntimeException('GITHUB_REPOSITORY is required in GitHub Actions.');
        }
        if (is_string($environmentRepository) && $environmentRepository !== '') {
            if ($options['repo'] !== '' && $options['repo'] !== $environmentRepository) {
                throw new RuntimeException('--repo conflicts with GITHUB_REPOSITORY.');
            }
            $expectedRepository = $environmentRepository;
        } else {
            $expectedRepository = $options['repo'];
        }
        if (preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $expectedRepository) !== 1) {
            throw new RuntimeException('Local --github review requires --repo=owner/repository.');
        }
        if ($register['repository'] !== $expectedRepository) {
            throw new RuntimeException('The security review register does not match the publishing repository.');
        }
        $sha = $options['commit'];
        if (preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1) {
            throw new RuntimeException('--github requires --commit=<full SHA>.');
        }
        if (trim(securityReviewCommand(['git', '-C', $root, 'rev-parse', 'HEAD'])) !== $sha) {
            throw new RuntimeException('Checkout HEAD differs from the requested scanner revision.');
        }
        // Compare every review input to the immutable revision, including new,
        // untracked policy files; a dirty policy must never authorize release.
        $paths = array_unique(['.github/security-review.json', '.wp-core-base/framework.php',
            'scripts/ci/check_security_review.php', 'scripts/ci/security_review_policy.php', 'scripts/ci/security_review_github.php',
            ...array_keys($register['sources']),
            ...array_column($register['findings'], 'review_document'), ...array_column($register['manual_findings'], 'review_document')]);
        foreach ($paths as $path) {
            if (securityReviewCommand(['git', '-C', $root, 'show', $sha . ':' . $path]) !== file_get_contents($root . '/' . $path)) {
                throw new RuntimeException('Security review input differs from the requested commit: ' . $path);
            }
        }
        $api = getenv('GITHUB_API_URL') ?: 'https://api.github.com';
        $parts = parse_url($api);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/', '/api/v3', '/api/v3/'], true)) {
            throw new RuntimeException('GITHUB_API_URL must name a GitHub HTTPS API origin.');
        }
        $host = $parts['host'] === 'api.github.com' ? 'github.com' : $parts['host'];
        $host .= isset($parts['port']) ? ':' . $parts['port'] : '';
        $fetch = static function (string $endpoint) use ($host): array {
            $result = json_decode(securityReviewCommand(['gh', 'api', '--hostname', $host,
                '-H', 'Accept: application/vnd.github+json', '-H', 'X-GitHub-Api-Version: 2022-11-28', $endpoint]), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($result)) {
                throw new RuntimeException('GitHub returned invalid security-review JSON.');
            }
            return $result;
        };
        $github = new SecurityReviewGitHub($fetch);
        $prefix = 'repos/' . $register['repository'];
        $repository = $fetch($prefix);
        $branch = $repository['default_branch'] ?? null;
        if (($repository['full_name'] ?? null) !== $register['repository'] || ! is_string($branch) || $branch === '') {
            throw new RuntimeException('GitHub repository identity or default branch is missing.');
        }
        $ref = 'refs/heads/' . $branch;
        $workflows = $github->inventory($prefix . '/actions/workflows', 'workflows');
        $matching = array_values(array_filter($workflows, static fn (array $workflow): bool => ($workflow['path'] ?? null) === $register['workflow_path']));
        if (count($matching) !== 1 || ($matching[0]['state'] ?? null) !== 'active') {
            throw new RuntimeException('The reviewed scanner workflow is not uniquely active.');
        }
        $deadline = microtime(true) + (int) $options['wait-seconds'];
        do {
            $runs = $github->inventory($prefix . '/actions/workflows/' . $matching[0]['id'] . '/runs?head_sha=' . $sha . '&branch=' . rawurlencode($branch), 'workflow_runs');
            $scan = $github->completedScan($runs, $register['workflow_path'], $sha, $branch);
            if ($scan !== null) {
                $analyses = $github->inventory($prefix . '/code-scanning/analyses?ref=' . rawurlencode($ref));
                $alerts = $github->inventory($prefix . '/code-scanning/alerts?state=open&ref=' . rawurlencode($ref));
                $report = $policy->evaluate($register, $analyses, $alerts, $sha, $ref, $register['repository']);
                $github->assertFreshAnalyses($report['analyses'], $scan);
                $report['scanner_run_id'] = $scan['id'];
                break;
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('No completed successful scanner run is available for the requested revision.');
            }
            fwrite(STDERR, "Waiting for the exact-revision scanner run to complete.\n");
            usleep((int) (min(20, $remaining) * 1_000_000));
        } while (true);
    } elseif ($options['commit'] !== '' || $options['wait-seconds'] !== '0' || $options['repo'] !== '') {
        throw new RuntimeException('--commit, --wait-seconds and --repo require --github.');
    }
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Security review failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

/** @param list<string> $command */
function securityReviewCommand(array $command): string
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start security-review reader.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $deadline = microtime(true) + 120;
    while (true) {
        $output .= stream_get_contents($pipes[1]);
        // Do not echo transport stderr: it can contain credential-bearing URLs.
        stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (! $status['running']) {
            $output .= stream_get_contents($pipes[1]);
            break;
        }
        if (strlen($output) > 32 * 1024 * 1024 || microtime(true) > $deadline) {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw new RuntimeException('Security-review reader exceeded its response/time limit.');
        }
        usleep(10_000);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closed = proc_close($process);
    if ($status['exitcode'] !== 0 || ($closed !== -1 && $closed !== 0) || strlen($output) > 32 * 1024 * 1024) {
        throw new RuntimeException('Security-review reader failed; refusing incomplete evidence.');
    }
    return $output;
}
