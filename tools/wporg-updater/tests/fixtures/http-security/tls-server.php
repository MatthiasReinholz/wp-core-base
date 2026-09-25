<?php

declare(strict_types=1);

// Isolated test server. The client trusts its generated CA and still verifies TLS.
[$script, $directory] = $argv;
$context = stream_context_create(['ssl' => [
    'local_cert' => $directory . '/certificate.pem',
    'local_pk' => $directory . '/private.pem',
    'verify_peer' => false, // This server does not request client certificates.
]]);
$servers = [];
$ports = [];
for ($index = 0; $index < 2; $index++) {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
    if ($server === false) {
        throw new RuntimeException($errorMessage);
    }
    $servers[] = $server;
    $ports[] = (int) substr((string) stream_socket_get_name($server, false), strlen('127.0.0.1:'));
}
file_put_contents($directory . '/ready.json', json_encode($ports, JSON_THROW_ON_ERROR));
$retryCount = 0;
while (true) {
    $read = $servers;
    $write = $except = [];
    if (stream_select($read, $write, $except, 10) === 0) {
        continue;
    }
    foreach ($read as $server) {
        $connection = stream_socket_accept($server, 1);
        if ($connection === false) {
            continue;
        }
        stream_set_timeout($connection, 3);
        if (@stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
            fclose($connection);
            continue;
        }
        $request = fgets($connection);
        $headers = [];
        while (is_string($line = fgets($connection)) && trim($line) !== '') {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }
        $target = explode(' ', (string) $request)[1] ?? '/';
        $path = (string) parse_url($target, PHP_URL_PATH);
        $port = $ports[array_search($server, $servers, true)];
        file_put_contents($directory . '/requests.jsonl', json_encode(['path' => $target, 'port' => $port, 'headers' => $headers], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
        $status = 200;
        $responseHeaders = [];
        $body = str_repeat('a', 64) . "  package.zip\n";
        switch ($path) {
            case '/repos/owner/project/pulls':
                $responseHeaders['Content-Type'] = 'application/json';
                $body = json_encode([['number' => 42, 'state' => 'open'], 'invalid-entry'], JSON_THROW_ON_ERROR);
                break;
            case '/api/v4/projects/42/merge_requests':
                $responseHeaders['Content-Type'] = 'application/json';
                $body = json_encode([['iid' => 42, 'state' => 'opened', 'source_project_id' => 42, 'target_project_id' => 42,
                    'source_branch' => 'codex/wporg-fixture', 'target_branch' => 'main'], 'invalid-entry'], JSON_THROW_ON_ERROR);
                break;
            case '/start':
                $status = 302;
                $responseHeaders['Location'] = '/nested/../hop';
                break;
            case '/hop':
                $status = 302;
                $responseHeaders['Location'] = '//127.0.0.1:' . $ports[1] . '/checksums?step=1';
                break;
            case '/checksums':
                if ($retryCount++ === 0) {
                    $status = 503;
                    $responseHeaders['Retry-After'] = '0';
                }
                break;
            case '/cross-host':
                $status = 302;
                $responseHeaders['Location'] = 'https://localhost:' . $ports[1] . '/bounce';
                break;
            case '/bounce':
                $status = 302;
                $responseHeaders['Location'] = 'https://127.0.0.1:' . $ports[0] . '/final';
                break;
            case '/query':
                if (! str_contains($target, '?')) {
                    $status = 302;
                    $responseHeaders['Location'] = '?selected=1';
                }
                break;
            case '/loop':
                $status = 302;
                $responseHeaders['Location'] = '/loop';
                break;
            case '/missing-location':
                $status = 302;
                break;
            case '/bad-scheme':
                $status = 302;
                $responseHeaders['Location'] = 'http://127.0.0.1:' . $ports[1] . '/final';
                break;
            case '/userinfo':
                $status = 302;
                $responseHeaders['Location'] = 'https://user:pass@localhost:' . $ports[1] . '/final';
                break;
            case '/disallowed-host':
                $status = 302;
                $responseHeaders['Location'] = 'https://example.invalid/should-not-request';
                break;
            case '/large':
                $body = str_repeat('x', 1024 * 1024 + 1);
                break;
        }
        $response = 'HTTP/1.1 ' . $status . " Test\r\nConnection: close\r\nContent-Length: " . strlen($body) . "\r\n";
        foreach ($responseHeaders as $name => $value) {
            $response .= $name . ': ' . $value . "\r\n";
        }
        $response .= "\r\n" . $body;
        while ($response !== '') {
            $written = @fwrite($connection, $response);
            if ($written === false || $written === 0) {
                break;
            }
            $response = substr($response, $written);
        }
        fclose($connection);
    }
}
