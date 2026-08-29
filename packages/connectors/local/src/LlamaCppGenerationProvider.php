<?php
declare(strict_types=1);

namespace MCMA\Connectors\Local;

use JsonException;
use MCMA\Core\Ask\GenerationProvider;
use RuntimeException;

final class LlamaCppGenerationProvider implements GenerationProvider
{
    /** @var null|callable */
    private $requester;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $maxTokens = 1024,
        private readonly float $temperature = 0.2,
        private readonly ?string $systemPrompt = null,
        private readonly ?string $apiKey = null,
        ?callable $requester = null
    ) {
        self::validateBaseUrl($this->baseUrl);
        if (trim($this->model) === '' || strlen($this->model) > 512) throw new RuntimeException('llama.cpp chat model alias is required');
        if ($this->maxTokens < 1 || $this->maxTokens > 200000) throw new RuntimeException('llama.cpp max tokens must be between 1 and 200000');
        if (!is_finite($this->temperature) || $this->temperature < 0.0 || $this->temperature > 2.0) {
            throw new RuntimeException('llama.cpp temperature must be between 0 and 2');
        }
        $this->requester = $requester;
    }

    public static function fromEnvironment(): self
    {
        $baseUrl = self::firstEnv(['MCMA_LLAMACPP_CHAT_URL']) ?? 'http://127.0.0.1:8080';
        $model = self::firstEnv(['MCMA_LLAMACPP_CHAT_MODEL']);
        if ($model === null) throw new RuntimeException('MCMA_LLAMACPP_CHAT_MODEL is required for llama.cpp generation');

        return new self(
            $baseUrl,
            $model,
            (int)(self::firstEnv(['MCMA_LLAMACPP_MAX_TOKENS']) ?? '1024'),
            (float)(self::firstEnv(['MCMA_LLAMACPP_TEMPERATURE']) ?? '0.2'),
            self::firstEnv(['MCMA_LLAMACPP_SYSTEM_PROMPT']),
            self::firstEnv(['MCMA_LLAMACPP_CHAT_API_KEY','MCMA_LLAMACPP_API_KEY'])
        );
    }

    public function id(): string
    {
        return 'llamacpp-chat:' . $this->model;
    }

    public function generate(string $question, array $context = []): array
    {
        $question = trim($question);
        if ($question === '') throw new RuntimeException('Generation question must not be empty');

        $messages = [];
        if ($this->systemPrompt !== null && trim($this->systemPrompt) !== '') {
            $messages[] = ['role'=>'system','content'=>trim($this->systemPrompt)];
        }
        $messages[] = ['role'=>'user','content'=>$question];

        try {
            $body = json_encode([
                'model' => $this->model,
                'messages' => $messages,
                'stream' => false,
                'max_tokens' => $this->maxTokens,
                'temperature' => $this->temperature,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Unable to encode llama.cpp chat request', 0, $e);
        }

        [$status, $responseBody] = $this->request(
            'POST',
            rtrim($this->baseUrl, '/') . '/v1/chat/completions',
            $this->headers(),
            $body
        );
        if ($status !== 200) throw new RuntimeException('llama.cpp chat request failed: HTTP ' . $status);

        try {
            $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('llama.cpp chat response is not valid JSON', 0, $e);
        }

        $text = trim((string)($response['choices'][0]['message']['content'] ?? ''));
        if ($text === '') throw new RuntimeException('llama.cpp chat response did not contain choices[0].message.content');

        $result = [
            'text' => $text,
            'stop_reason' => isset($response['choices'][0]['finish_reason']) ? (string)$response['choices'][0]['finish_reason'] : null,
        ];

        if (is_array($response['usage'] ?? null)) {
            $usage = [];
            if (isset($response['usage']['prompt_tokens']) && is_int($response['usage']['prompt_tokens'])) {
                $usage['inputTokens'] = $response['usage']['prompt_tokens'];
            }
            if (isset($response['usage']['completion_tokens']) && is_int($response['usage']['completion_tokens'])) {
                $usage['outputTokens'] = $response['usage']['completion_tokens'];
            }
            if (isset($response['usage']['total_tokens']) && is_int($response['usage']['total_tokens'])) {
                $usage['totalTokens'] = $response['usage']['total_tokens'];
            }
            if ($usage !== []) $result['usage'] = $usage;
        }

        return $result;
    }

    private function headers(): array
    {
        $headers = ['content-type'=>'application/json','accept'=>'application/json'];
        if ($this->apiKey !== null && $this->apiKey !== '') $headers['authorization'] = 'Bearer ' . $this->apiKey;
        return $headers;
    }

    /** @return array{0:int,1:string,2:array<string,string>} */
    private function request(string $method, string $url, array $headers, string $body): array
    {
        if ($this->requester !== null) {
            $result = ($this->requester)($method, $url, $headers, $body);
            if (!is_array($result) || count($result) < 2) throw new RuntimeException('Invalid llama.cpp requester result');
            return [(int)$result[0], (string)$result[1], is_array($result[2] ?? null) ? $result[2] : []];
        }

        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for llama.cpp generation');

        $wireHeaders = [];
        foreach ($headers as $name => $value) $wireHeaders[] = $name . ': ' . $value;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $wireHeaders,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 300,
        ]);
        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('llama.cpp HTTP error: ' . $error);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, (string)$responseBody, []];
    }

    private static function validateBaseUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http','https'], true) || trim((string)($parts['host'] ?? '')) === '') {
            throw new RuntimeException('Invalid llama.cpp base URL');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('llama.cpp base URL must not contain credentials, query or fragment');
        }
    }

    private static function firstEnv(array $names): ?string
    {
        foreach ($names as $name) {
            $value = getenv($name);
            if (is_string($value) && trim($value) !== '') return trim($value);
        }
        return null;
    }
}
