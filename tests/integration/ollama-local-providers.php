<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Connectors\Local\OllamaEmbeddingProvider;
use MCMA\Connectors\Local\OllamaGenerationProvider;

$embedSeen = false;
$embedRequester = function (string $method, string $url, array $headers, string $body) use (&$embedSeen): array {
    if ($method !== 'POST') throw new RuntimeException('Ollama embed method mismatch');
    if ($url !== 'http://127.0.0.1:11434/api/embed') throw new RuntimeException('Ollama embed URL mismatch: ' . $url);
    $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (($request['model'] ?? null) !== 'embedding-model') throw new RuntimeException('Ollama embed model mismatch');
    if (($request['input'] ?? null) !== 'hello memory') throw new RuntimeException('Ollama embed input mismatch');
    $embedSeen = true;
    return [200, json_encode([
        'model'=>'embedding-model',
        'embeddings'=>[[3.0,4.0,0.0]],
        'prompt_eval_count'=>3,
    ], JSON_THROW_ON_ERROR), []];
};

$embedding = new OllamaEmbeddingProvider(
    'http://127.0.0.1:11434',
    'embedding-model',
    $embedRequester
);
$vector = $embedding->embed('hello memory');
if (!$embedSeen) throw new RuntimeException('Ollama embedding requester was not called');
if (count($vector) !== 3 || abs($vector[0]-0.6) > 1e-12 || abs($vector[1]-0.8) > 1e-12) {
    throw new RuntimeException('Ollama embedding normalization mismatch');
}
if ($embedding->id() !== 'ollama:embedding-model:embed') throw new RuntimeException('Ollama embedding provider id mismatch');
if (($embedding->lastUsage()['inputTokens'] ?? null) !== 3 || ($embedding->lastUsage()['method'] ?? null) !== 'provider') throw new RuntimeException('Ollama embedding usage mismatch');

$chatSeen = false;
$chatRequester = function (string $method, string $url, array $headers, string $body) use (&$chatSeen): array {
    if ($method !== 'POST') throw new RuntimeException('Ollama chat method mismatch');
    if ($url !== 'http://127.0.0.1:11434/api/chat') throw new RuntimeException('Ollama chat URL mismatch: ' . $url);
    $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (($request['model'] ?? null) !== 'chat-model') throw new RuntimeException('Ollama chat model mismatch');
    if (($request['stream'] ?? null) !== false) throw new RuntimeException('Ollama chat stream must be false');
    if (($request['messages'][0]['role'] ?? null) !== 'system') throw new RuntimeException('Ollama system message missing');
    if (($request['messages'][0]['content'] ?? null) !== 'Be concise.') throw new RuntimeException('Ollama system content mismatch');
    if (($request['messages'][1]['role'] ?? null) !== 'user') throw new RuntimeException('Ollama user message missing');
    if (($request['messages'][1]['content'] ?? null) !== 'What is MCMA?') throw new RuntimeException('Ollama user content mismatch');
    if (($request['options']['num_predict'] ?? null) !== 512) throw new RuntimeException('Ollama num_predict mismatch');
    if (abs((float)($request['options']['temperature'] ?? -1)-0.3) > 1e-12) throw new RuntimeException('Ollama temperature mismatch');
    $chatSeen = true;
    return [200, json_encode([
        'model'=>'chat-model',
        'message'=>['role'=>'assistant','content'=>'MCMA keeps memory portable.'],
        'done'=>true,
        'done_reason'=>'stop',
        'prompt_eval_count'=>11,
        'eval_count'=>7,
    ], JSON_THROW_ON_ERROR), []];
};

$generation = new OllamaGenerationProvider(
    'http://127.0.0.1:11434',
    'chat-model',
    512,
    0.3,
    'Be concise.',
    $chatRequester
);
$result = $generation->generate('What is MCMA?');
if (!$chatSeen) throw new RuntimeException('Ollama chat requester was not called');
if (($result['text'] ?? null) !== 'MCMA keeps memory portable.') throw new RuntimeException('Ollama generated text mismatch');
if (($result['stop_reason'] ?? null) !== 'stop') throw new RuntimeException('Ollama stop reason mismatch');
if (($result['usage']['inputTokens'] ?? null) !== 11 || ($result['usage']['outputTokens'] ?? null) !== 7 || ($result['usage']['totalTokens'] ?? null) !== 18) {
    throw new RuntimeException('Ollama token usage mismatch');
}
if ($generation->id() !== 'ollama-chat:chat-model') throw new RuntimeException('Ollama generation provider id mismatch');

$conversationSeen=false;
$conversationRequester=function(string $method,string $url,array $headers,string $body)use(&$conversationSeen):array{
    $request=json_decode($body,true,512,JSON_THROW_ON_ERROR);
    if(($request['messages'][0]['role']??null)!=='system'||!str_contains((string)($request['messages'][0]['content']??''),'untrusted reference data')){
        throw new RuntimeException('Ollama conversation safety instruction missing');
    }
    $user=(string)($request['messages'][1]['content']??'');
    if(!str_contains($user,'"conversation_id":"conv_'.str_repeat('e',32).'"')||!str_contains($user,'"question":"Earlier turn"')){
        throw new RuntimeException('Ollama selected conversation context missing');
    }
    if(!str_contains($user,'"strategy":"multi-memory-rag-v1"')||!str_contains($user,'"question":"Selected RAG memory"')){
        throw new RuntimeException('Ollama multi-memory RAG context missing');
    }
    $conversationSeen=true;
    return [200,json_encode([
        'message'=>['role'=>'assistant','content'=>'Conversation-aware local response.'],
        'done'=>true,'done_reason'=>'stop','prompt_eval_count'=>20,'eval_count'=>5,
    ],JSON_THROW_ON_ERROR),[]];
};
$conversationGeneration=new OllamaGenerationProvider(
    'http://127.0.0.1:11434','chat-model',128,0.2,null,$conversationRequester
);
$conversationResult=$conversationGeneration->generate('Follow up',[
    'multi_memory_context'=>[
        'query'=>'Combined context query',
        'selection'=>[
            'strategy'=>'multi-memory-rag-v1',
            'selected_memories'=>1,
            'token_budget'=>8000,
            'estimated_tokens_upper_bound'=>300,
        ],
        'memories'=>[[
            'logical_ref'=>'memory://knowledge/q-'.str_repeat('b',64),
            'question'=>'Selected RAG memory',
            'answer'=>'Selected RAG answer.',
            'similarity'=>0.91,
            'rag_score'=>0.88,
            'validation_state'=>'verified',
            'confidence'=>0.95,
            'freshness_class'=>'stable',
            'stale'=>false,
            'provenance'=>[['source_type'=>'documentation','reference'=>'docs/test.md']],
        ]],
    ],
    'conversation_context'=>[
        'conversation_id'=>'conv_'.str_repeat('e',32),
        'selection'=>['strategy'=>'recent-plus-lexical-v1','selected_turns'=>1],
        'turns'=>[[
            'logical_ref'=>'memory://interactions/2026/09/02/conv_'.str_repeat('e',32).'/req_'.str_repeat('1',32),
            'at'=>'2026-09-02T19:00:00Z','question'=>'Earlier turn','answer'=>'Earlier answer',
            'validation_state'=>'unverified','confidence'=>0.5,'relevance_score'=>0.8,'selection_reason'=>'relevance',
        ]],
    ],
]);
if(!$conversationSeen||($conversationResult['text']??null)!=='Conversation-aware local response.'){
    throw new RuntimeException('Ollama conversation context simulation failed');
}

$invalidBaseRejected = false;
try {
    new OllamaEmbeddingProvider('file:///tmp/ollama.sock', 'model', $embedRequester);
} catch (RuntimeException $e) {
    $invalidBaseRejected = true;
}
if (!$invalidBaseRejected) throw new RuntimeException('Ollama invalid base URL was accepted');

echo "MCMA Ollama local AI providers simulation passed.\n";
