<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Connectors\Aws\BedrockTitanEmbeddingProvider;

function embedding256(): array
{
    $v = array_fill(0, 256, 0.0);
    $v[0] = 1.0;
    return $v;
}

$bearerSeen = false;
$bearerRequester = function (string $method, string $url, array $headers, string $body) use (&$bearerSeen): array {
    if ($method !== 'POST') throw new RuntimeException('Bedrock method mismatch');
    if (!str_contains($url, 'bedrock-runtime.us-east-1.amazonaws.com/model/amazon.titan-embed-text-v2%3A0/invoke')) {
        throw new RuntimeException('Bedrock URL mismatch: ' . $url);
    }
    if (($headers['authorization'] ?? null) !== 'Bearer test-bedrock-key') throw new RuntimeException('Bedrock bearer auth missing');
    $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (($request['dimensions'] ?? null) !== 256 || ($request['normalize'] ?? null) !== true) throw new RuntimeException('Bedrock request options mismatch');
    $bearerSeen = true;
    return [200, json_encode(['embedding'=>embedding256(),'inputTextTokenCount'=>4], JSON_THROW_ON_ERROR), []];
};

$bearer = new BedrockTitanEmbeddingProvider(
    'us-east-1',
    256,
    'amazon.titan-embed-text-v2:0',
    'test-bedrock-key',
    null,
    null,
    null,
    $bearerRequester
);
$vector = $bearer->embed('semantic test');
if (!$bearerSeen || count($vector) !== 256 || abs($vector[0] - 1.0) > 1e-12) throw new RuntimeException('Bedrock bearer embedding test failed');
if (($bearer->lastUsage()['inputTokens'] ?? null) !== 4 || ($bearer->lastUsage()['method'] ?? null) !== 'provider') throw new RuntimeException('Bedrock embedding usage mismatch');

$sigSeen = false;
$sigRequester = function (string $method, string $url, array $headers, string $body) use (&$sigSeen): array {
    $authorization = (string)($headers['authorization'] ?? '');
    if (!str_starts_with($authorization, 'AWS4-HMAC-SHA256 ')) throw new RuntimeException('Bedrock SigV4 authorization missing');
    if (!str_contains($authorization, '/us-east-1/bedrock/aws4_request')) throw new RuntimeException('Bedrock SigV4 signing scope mismatch');
    if (!isset($headers['x-amz-content-sha256'], $headers['x-amz-date'])) throw new RuntimeException('Bedrock SigV4 headers missing');
    $sigSeen = true;
    return [200, json_encode(['embeddingsByType'=>['float'=>embedding256()],'inputTextTokenCount'=>5], JSON_THROW_ON_ERROR), []];
};

$signed = new BedrockTitanEmbeddingProvider(
    'us-east-1',
    256,
    'amazon.titan-embed-text-v2:0',
    null,
    'AKIDEXAMPLE',
    'SECRETEXAMPLE',
    'SESSIONEXAMPLE',
    $sigRequester
);
$vector2 = $signed->embed('signed semantic test');
if (!$sigSeen || count($vector2) !== 256 || abs($vector2[0] - 1.0) > 1e-12) throw new RuntimeException('Bedrock SigV4 embedding test failed');
if (($signed->lastUsage()['inputTokens'] ?? null) !== 5) throw new RuntimeException('Bedrock signed embedding usage mismatch');

if ($signed->id() !== 'bedrock:amazon.titan-embed-text-v2:0:dimensions=256:normalize=true') throw new RuntimeException('Bedrock provider id mismatch');

echo "MCMA Bedrock Titan embedding provider simulation passed.\n";
