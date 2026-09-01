<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Connectors\Aws\BedrockConverseGenerationProvider;
use MCMA\Core\Storage\AwsSigV4;

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
    if (!str_contains($url, 'amazon.nova-micro-v1%3A0/converse')) {
        throw new RuntimeException('Bedrock Converse encoded model URL mismatch: ' . $url);
    }
    $authorization = (string)($headers['authorization'] ?? '');
    if (!str_starts_with($authorization, 'AWS4-HMAC-SHA256 ')) throw new RuntimeException('Bedrock Converse SigV4 authorization missing');
    if (!str_contains($authorization, '/us-east-1/bedrock/aws4_request')) throw new RuntimeException('Bedrock Converse SigV4 scope mismatch');
    if (!isset($headers['x-amz-content-sha256'], $headers['x-amz-date'], $headers['x-amz-security-token'])) {
        throw new RuntimeException('Bedrock Converse SigV4 headers missing');
    }

    $expected = AwsSigV4::sign(
        'POST',
        'bedrock-runtime.us-east-1.amazonaws.com',
        '/model/amazon.nova-micro-v1%3A0/converse',
        [],
        ['content-type'=>'application/json','accept'=>'application/json'],
        $body,
        'AKIDEXAMPLE',
        'SECRETEXAMPLE',
        'us-east-1',
        'bedrock',
        (string)$headers['x-amz-date'],
        'SESSIONEXAMPLE'
    );
    if (($expected['headers']['authorization'] ?? null) !== $authorization) {
        throw new RuntimeException('Bedrock Converse canonical path signing mismatch');
    }
    if (!str_contains((string)$expected['canonical_request'], '/model/amazon.nova-micro-v1%253A0/converse')) {
        throw new RuntimeException('Bedrock Converse canonical path was not double encoded');
    }

    $sigSeen = true;
    return [200, json_encode([
        'output' => ['message' => ['role'=>'assistant','content'=>[['text'=>'Signed response.']]]],
        'stopReason' => 'end_turn',
    ], JSON_THROW_ON_ERROR), []];
};

$signed = new BedrockConverseGenerationProvider(
    'us-east-1',
    'amazon.nova-micro-v1:0',
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

$errorRequester = static function (): array {
    return [400, json_encode([
        '__type' => 'ValidationException',
        'message' => 'Invocation requires an inference profile.',
        'secret' => 'must-not-leak',
    ], JSON_THROW_ON_ERROR), []];
};
$errorProvider = new BedrockConverseGenerationProvider(
    'us-east-1',
    'example.model',
    64,
    0.0,
    null,
    'test-bedrock-key',
    null,
    null,
    null,
    $errorRequester
);
try {
    $errorProvider->generate('diagnostic test');
    throw new RuntimeException('Expected Bedrock Converse diagnostic failure');
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'Expected Bedrock Converse diagnostic failure') throw $e;
    if (!str_contains($e->getMessage(), 'HTTP 400 - ValidationException: Invocation requires an inference profile.')) {
        throw new RuntimeException('Bedrock Converse safe error detail missing: ' . $e->getMessage());
    }
    if (str_contains($e->getMessage(), 'must-not-leak')) throw new RuntimeException('Bedrock Converse diagnostic leaked unrelated response field');
}

echo "MCMA Bedrock Converse generation provider simulation passed.\n";
