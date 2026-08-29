<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Connectors\Local\LlamaCppEmbeddingProvider;
use MCMA\Connectors\Local\LlamaCppGenerationProvider;

$embedSeen = false;
$embedRequester = function (string $method, string $url, array $headers, string $body) use (&$embedSeen): array {
    if ($method !== 'POST') throw new RuntimeException('llama.cpp embed method mismatch');
    if ($url !== 'http://127.0.0.1:8081/v1/embeddings') throw new RuntimeException('llama.cpp embed URL mismatch: ' . $url);
    if (($headers['authorization'] ?? null) !== 'Bearer embed-key') throw new RuntimeException('llama.cpp embed API key missing');

    $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (($request['model'] ?? null) !== 'mcma-embed') throw new RuntimeException('llama.cpp embed model mismatch');
    if (($request['input'] ?? null) !== 'query: hello memory') throw new RuntimeException('llama.cpp embed prefix/input mismatch');
    if (($request['encoding_format'] ?? null) !== 'float') throw new RuntimeException('llama.cpp embed encoding mismatch');

    $embedSeen = true;
    return [200, json_encode([
        'object'=>'list',
        'data'=>[[
            'object'=>'embedding',
            'index'=>0,
            'embedding'=>[3.0,4.0,0.0],
        ]],
        'model'=>'mcma-embed',
        'usage'=>['prompt_tokens'=>3,'total_tokens'=>3],
    ], JSON_THROW_ON_ERROR), []];
};

$embedding = new LlamaCppEmbeddingProvider(
    'http://127.0.0.1:8081',
    'mcma-embed',
    'embed-key',
    'query: ',
    'multilingual-e5-small-mean-v1',
    $embedRequester
);
$vector = $embedding->embed('hello memory');
if (!$embedSeen) throw new RuntimeException('llama.cpp embedding requester was not called');
if (count($vector) !== 3 || abs($vector[0]-0.6) > 1e-12 || abs($vector[1]-0.8) > 1e-12) {
    throw new RuntimeException('llama.cpp embedding normalization mismatch');
}

$expectedId = 'llamacpp:multilingual-e5-small-mean-v1:embed:l2:prefix-' . substr(hash('sha256', 'query: '), 0, 16);
if ($embedding->id() !== $expectedId) throw new RuntimeException('llama.cpp embedding provider id mismatch');

$otherPrefix = new LlamaCppEmbeddingProvider(
    'http://127.0.0.1:8081',
    'mcma-embed',
    null,
    '',
    'multilingual-e5-small-mean-v1',
    $embedRequester
);
if ($otherPrefix->id() === $embedding->id()) throw new RuntimeException('llama.cpp embedding prefix did not change provider identity');

$chatSeen = false;
$chatRequester = function (string $method, string $url, array $headers, string $body) use (&$chatSeen): array {
    if ($method !== 'POST') throw new RuntimeException('llama.cpp chat method mismatch');
    if ($url !== 'http://127.0.0.1:8080/v1/chat/completions') throw new RuntimeException('llama.cpp chat URL mismatch: ' . $url);
    if (($headers['authorization'] ?? null) !== 'Bearer chat-key') throw new RuntimeException('llama.cpp chat API key missing');

    $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (($request['model'] ?? null) !== 'mcma-chat') throw new RuntimeException('llama.cpp chat model mismatch');
    if (($request['stream'] ?? null) !== false) throw new RuntimeException('llama.cpp chat stream must be false');
    if (($request['messages'][0]['role'] ?? null) !== 'system') throw new RuntimeException('llama.cpp system message missing');
    if (($request['messages'][0]['content'] ?? null) !== 'Be concise.') throw new RuntimeException('llama.cpp system prompt mismatch');
    if (($request['messages'][1]['content'] ?? null) !== 'What is MCMA?') throw new RuntimeException('llama.cpp user content mismatch');
    if (($request['max_tokens'] ?? null) !== 384) throw new RuntimeException('llama.cpp max_tokens mismatch');
    if (abs((float)($request['temperature'] ?? -1)-0.25) > 1e-12) throw new RuntimeException('llama.cpp temperature mismatch');

    $chatSeen = true;
    return [200, json_encode([
        'id'=>'chatcmpl-test',
        'object'=>'chat.completion',
        'choices'=>[[
            'index'=>0,
            'message'=>['role'=>'assistant','content'=>'MCMA keeps memory portable.'],
            'finish_reason'=>'stop',
        ]],
        'usage'=>[
            'prompt_tokens'=>12,
            'completion_tokens'=>8,
            'total_tokens'=>20,
        ],
    ], JSON_THROW_ON_ERROR), []];
};

$generation = new LlamaCppGenerationProvider(
    'http://127.0.0.1:8080',
    'mcma-chat',
    384,
    0.25,
    'Be concise.',
    'chat-key',
    $chatRequester
);
$result = $generation->generate('What is MCMA?');
if (!$chatSeen) throw new RuntimeException('llama.cpp chat requester was not called');
if (($result['text'] ?? null) !== 'MCMA keeps memory portable.') throw new RuntimeException('llama.cpp generated text mismatch');
if (($result['stop_reason'] ?? null) !== 'stop') throw new RuntimeException('llama.cpp finish reason mismatch');
if (($result['usage']['inputTokens'] ?? null) !== 12 || ($result['usage']['outputTokens'] ?? null) !== 8 || ($result['usage']['totalTokens'] ?? null) !== 20) {
    throw new RuntimeException('llama.cpp token usage mismatch');
}
if ($generation->id() !== 'llamacpp-chat:mcma-chat') throw new RuntimeException('llama.cpp generation provider id mismatch');

$invalidBaseRejected = false;
try {
    new LlamaCppGenerationProvider('file:///tmp/llama.sock', 'mcma-chat', 128, 0.2, null, null, $chatRequester);
} catch (RuntimeException $e) {
    $invalidBaseRejected = true;
}
if (!$invalidBaseRejected) throw new RuntimeException('llama.cpp invalid base URL was accepted');

echo "MCMA llama.cpp local AI providers simulation passed.\n";
