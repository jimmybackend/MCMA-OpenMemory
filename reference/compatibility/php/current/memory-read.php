<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');

function fail(int $status, string $error, ?string $detail = null): never
{
    http_response_code($status);

    $response = [
        'ok' => false,
        'error' => $error,
    ];

    if ($detail !== null) {
        $response['detail'] = $detail;
    }

    echo json_encode(
        $response,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

function envRequired(string $name): string
{
    $value = getenv($name);

    if ($value === false || trim($value) === '') {
        fail(500, 'missing_environment_variable', $name);
    }

    return trim($value);
}

function githubRequest(
    string $owner,
    string $repo,
    string $branch,
    string $path,
    string $token
): array {
    $encodedPath = implode(
        '/',
        array_map(
            static fn(string $part): string => rawurlencode($part),
            explode('/', $path)
        )
    );

    $url =
        'https://api.github.com/repos/' .
        rawurlencode($owner) . '/' .
        rawurlencode($repo) .
        '/contents/' .
        $encodedPath .
        '?ref=' .
        rawurlencode($branch);

    $ch = curl_init($url);

    if ($ch === false) {
        fail(500, 'curl_init_failed');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $token,
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: MCMA-OpenMemory',
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);

        fail(502, 'github_connection_failed', $error);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($status !== 200) {
        fail(
            502,
            'github_read_failed',
            'GitHub HTTP ' . $status
        );
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        fail(502, 'invalid_github_response');
    }

    return $data;
}

function decryptEnvelope(
    string $cryptoUrl,
    string $apiToken,
    array $envelope
): string {
    $logicalPath = $envelope['logical_path'] ?? null;
    $file = $envelope['file'] ?? null;

    if (!is_string($logicalPath) || $logicalPath === '') {
        fail(400, 'missing_logical_path');
    }

    if (!is_string($file) || $file === '') {
        fail(400, 'missing_mcma_file');
    }

    $payload = [
        'action' => 'decrypt',
        'path' => $logicalPath,
        'file' => $file,
        'envelope' => $envelope,
    ];

    $jsonPayload = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if ($jsonPayload === false) {
        fail(500, 'crypto_payload_encode_failed');
    }

    $ch = curl_init($cryptoUrl);

    if ($ch === false) {
        fail(500, 'curl_init_failed');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);

        fail(502, 'crypto_connection_failed', $error);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $data = json_decode($response, true);

    if (
        $status !== 200 ||
        !is_array($data) ||
        ($data['ok'] ?? false) !== true
    ) {
        fail(502, 'crypto_decryption_failed');
    }

    $plaintextB64 = $data['plaintext_b64'] ?? null;

    if (!is_string($plaintextB64)) {
        fail(502, 'missing_plaintext');
    }

    $plaintext = base64_decode($plaintextB64, true);

    if ($plaintext === false) {
        fail(502, 'invalid_plaintext_base64');
    }

    return $plaintext;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail(405, 'post_required');
}

$bridgeToken = envRequired('MCMA_BRIDGE_TOKEN');

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (
    !preg_match('/^Bearer\\s+(.+)$/i', $authorization, $matches) ||
    !hash_equals($bridgeToken, $matches[1])
) {
    fail(401, 'unauthorized');
}

$rawBody = file_get_contents('php://input');

$body = json_decode($rawBody ?: '', true);

if (!is_array($body)) {
    fail(400, 'invalid_json');
}

$githubPath = trim((string) ($body['github_path'] ?? ''));

if ($githubPath === '') {
    fail(400, 'github_path_required');
}

$githubPath = trim(
    str_replace('\\', '/', $githubPath),
    '/'
);

if (
    str_contains($githubPath, '..') ||
    !preg_match('#^[A-Za-z0-9/_\\-.]+\\.mcma$#', $githubPath)
) {
    fail(400, 'invalid_github_path');
}

$githubOwner = envRequired('MCMA_GITHUB_OWNER');
$githubRepo = envRequired('MCMA_GITHUB_REPO');
$githubBranch = getenv('MCMA_GITHUB_BRANCH') ?: 'main';
$githubToken = envRequired('MCMA_GITHUB_TOKEN');
$cryptoUrl = envRequired('MCMA_CRYPTO_URL');
$cryptoToken = envRequired('MCMA_API_TOKEN');

$githubData = githubRequest(
    $githubOwner,
    $githubRepo,
    $githubBranch,
    $githubPath,
    $githubToken
);

if (($githubData['type'] ?? '') !== 'file') {
    fail(400, 'github_object_is_not_file');
}

$contentB64 = $githubData['content'] ?? null;

if (!is_string($contentB64)) {
    fail(502, 'github_content_missing');
}

$contentB64 = str_replace(["\r", "\n"], '', $contentB64);

$mcmaRaw = base64_decode($contentB64, true);

if ($mcmaRaw === false) {
    fail(502, 'invalid_github_base64');
}

$envelope = json_decode($mcmaRaw, true);

if (!is_array($envelope)) {
    fail(400, 'invalid_mcma_json');
}

if (($envelope['format'] ?? '') !== 'mcma-v2') {
    fail(400, 'unsupported_mcma_format');
}

$plaintext = decryptEnvelope(
    $cryptoUrl,
    $cryptoToken,
    $envelope
);

echo json_encode(
    [
        'ok' => true,
        'github_path' => $githubPath,
        'memory' => [
            'format' => $envelope['format'] ?? null,
            'logical_path' => $envelope['logical_path'] ?? null,
            'file' => $envelope['file'] ?? null,
            'temperature' => $envelope['temperature'] ?? null,
            'created_at' => $envelope['created_at'] ?? null,
            'text' => $plaintext,
        ],
    ],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
