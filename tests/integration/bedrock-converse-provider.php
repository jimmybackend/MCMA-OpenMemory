<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Connectors\Aws\BedrockConverseGenerationProvider;

$bearerSeen = false;
$bearerRequester = function (string $method, string $url, array $headers, string $body) use (&$bearerSeen): array {
    if ($method !== 'POST') throw new RuntimeException('Bedrock Converse method mismatch');
    if (!str_contains($url, 'bedrock-runtime.us-east-1.amazonaws.com/model/us.anthropic.claude-sonnet-4-6/converse')) {
        throw new RuntimeException('Bedrock Converse URL mismatch: ' . $url);
    }
    if (($headers['authorization'] ?? null) !== 'Bearer test-bedrock-key') throw new RuntimeException('Bedrock Converse bearer auth missing');

    $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (($request['messages'][0]['role'] ?? null) !== 'user') throw new RuntimeException('Bedrock Converse role mismatch');
    if (($request['messages'][0]['content'][0]['text'] ?? null) !== 'Hello MCMA') throw new RuntimeException('Bedrock Converse question mismatch');
    if (($request['inferenceConfig']['maxTokens'] ?? null) !== 512) throw new RuntimeException('Bedrock Converse maxTokens mismatch');
    if (abs((float)($request['inferenceConfig']['temperature'] ?? -1) - 0.2) > 1e-12) throw new RuntimeException('Bedrock Converse temperature mismatch');
    if (($request['system'][0]['text'] ?? null) !== 'Answer concisely.') throw new RuntimeException('Bedrock Converse system prompt mismatch');

    $bearerSeen = true;
    return [200, json_encode([
        'output' => ['message' => ['role'=>'assistant','content'=>[['text'=>'Hello from Bedrock.']]]],
        'stopReason' => 'end_turn',
        'usage' => ['inputTokens'=>12,'outputTokens'=>5,'totalTokens'=>17],
    ], JSON_THROW_ON_ERROR), []];
};

$bearer = new BedrockConverseGenerationProvider(
    'us-east-1',
    'us.anthropic.claude-sonnet-4-6',
    512,
    0.2,
    'Answer concisely.',
    'test-bedrock-key',
    null,
    null,
    null,
    $bearerRequester
);
$result = $bearer->generate('Hello MCMA');
if (!$bearerSeen) throw new RuntimeException('Bedrock Converse bearer requester was not called');
if (($result['text'] ?? null) !== 'Hello from Bedrock.') throw new RuntimeException('Bedrock Converse text mismatch');
if (($result['stop_reason'] ?? null) !== 'end_turn') throw new RuntimeException('Bedrock Converse stop reason mismatch');
if (($result['usage']['totalTokens'] ?? null) !== 17) throw new RuntimeException('Bedrock Converse usage mismatch');
if ($bearer->id() !== 'bedrock-converse:us.anthropic.claude-sonnet-4-6') throw new RuntimeException('Bedrock Converse provider id mismatch');

$sigSeen = false;
$sigRequester = function (string $method, string $url, array $headers, string $body) use (&$sigSeen): array {
    if (!str_contains($url, 'anthropic.claude-3-sonnet-20240229-v1%3A0/converse')) {
        throw new RuntimeException('Bedrock Converse encoded model URL mismatch: ' . $url);
    }
    $authorization = (string)($headers['authorization'] ?? '');
    if (!str_starts_with($authorization, 'AWS4-HMAC-SHA256 ')) throw new RuntimeException('Bedrock Converse SigV4 authorization missing');
    if (!str_contains($authorization, '/us-east-1/bedrock/aws4_request')) throw new RuntimeException('Bedrock Converse SigV4 scope mismatch');
    if (!isset($headers['x-amz-content-sha256'], $headers['x-amz-date'], $headers['x-amz-security-token'])) {
        throw new RuntimeException('Bedrock Converse SigV4 headers missing');
    }

    $sigSeen = true;
    return [200, json_encode([
        'output' => ['message' => ['role'=>'assistant','content'=>[['text'=>'Signed response.']]]],
        'stopReason' => 'end_turn',
    ], JSON_THROW_ON_ERROR), []];
};

$signed = new BedrockConverseGenerationProvider(
    'us-east-1',
    'anthropic.claude-3-sonnet-20240229-v1:0',
    256,
    0.0,
    null,
    null,
    'AKIDEXAMPLE',
    'SECRETEXAMPLE',
    'SESSIONEXAMPLE',
    $sigRequester
);
$signedResult = $signed->generate('Signed request');
if (!$sigSeen || ($signedResult['text'] ?? null) !== 'Signed response.') {
    throw new RuntimeException('Bedrock Converse SigV4 simulation failed');
}

echo "MCMA Bedrock Converse generation provider simulation passed.\n";
