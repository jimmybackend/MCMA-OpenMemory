<?php
declare(strict_types=1);

namespace MCMA\Connectors\Local;

use JsonException;
use MCMA\Core\Semantic\EmbeddingProvider;
use MCMA\Core\Billing\UsageAwareEmbeddingProvider;
use MCMA\Core\Semantic\VectorMath;
use RuntimeException;

final class LlamaCppEmbeddingProvider implements EmbeddingProvider, UsageAwareEmbeddingProvider
{
    /** @var null|callable */
    private $requester;
    private array $lastUsage = ['inputTokens'=>0,'totalTokens'=>0,'method'=>'unavailable'];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly ?string $apiKey = null,
        private readonly string $inputPrefix = '',
        private readonly ?string $compatibilityId = null,
        ?callable $requester = null
    ) {
        self::validateBaseUrl($this->baseUrl);
        if (trim($this->model) === '' || strlen($this->model) > 512) {
            throw new RuntimeException('llama.cpp embedding model alias is required');
        }
        if (strlen($this->inputPrefix) > 256) throw new RuntimeException('llama.cpp embedding input prefix is too long');
        if ($this->compatibilityId !== null && !preg_match('/^[A-Za-z0-9._:-]{1,256}$/', $this->compatibilityId)) {
            throw new RuntimeException('Invalid llama.cpp embedding compatibility id');
        }
        $this->requester = $requester;
    }

    public static function fromEnvironment(): self
    {
        $baseUrl = self::firstEnv(['MCMA_LLAMACPP_EMBED_URL']) ?? 'http://127.0.0.1:8081';
        $model = self::firstEnv(['MCMA_LLAMACPP_EMBED_MODEL']);
        if ($model === null) throw new RuntimeException('MCMA_LLAMACPP_EMBED_MODEL is required for llama.cpp embeddings');

        return new self(
            $baseUrl,
            $model,
            self::firstEnv(['MCMA_LLAMACPP_EMBED_API_KEY','MCMA_LLAMACPP_API_KEY']),
            self::firstEnv(['MCMA_LLAMACPP_EMBED_PREFIX']) ?? '',
            self::firstEnv(['MCMA_LLAMACPP_EMBED_ID'])
        );
    }

    public function id(): string
    {
        $identity = $this->compatibilityId ?? $this->model;
        return 'llamacpp:' . $identity . ':embed:l2:prefix-' . substr(hash('sha256', $this->inputPrefix), 0, 16);
    }

    public function embed(string $text): array
    {
        $text = trim($text);
        if ($text === '') throw new RuntimeException('Embedding text must not be empty');

        try {
            $body = json_encode([
                'model' => $this->model,
                'input' => $this->inputPrefix . $text,
                'encoding_format' => 'float',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Unable to encode llama.cpp embedding request', 0, $e);
        }

        [$status, $responseBody] = $this->request(
            'POST',
            rtrim($this->baseUrl, '/') . '/v1/embeddings',
            $this->headers(),
            $body
        );
        if ($status !== 200) throw new RuntimeException('llama.cpp embedding request failed: HTTP ' . $status);

        try {
            $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('llama.cpp embedding response is not valid JSON', 0, $e);
        }

        $vector = $response['data'][0]['embedding'] ?? null;
        if (!is_array($vector)) throw new RuntimeException('llama.cpp embedding response did not contain data[0].embedding');

        $count = $response['usage']['prompt_tokens'] ?? null;
        if (is_int($count) && $count >= 0) {
            $this->lastUsage = ['inputTokens'=>$count,'totalTokens'=>$count,'method'=>'provider'];
        } else {
            $estimate = max(1, strlen($text));
            $this->lastUsage = ['inputTokens'=>$estimate,'totalTokens'=>$estimate,'method'=>'estimated-bytes-upper-bound'];
        }

        return VectorMath::normalize($vector);
    }

    public function lastUsage(): array
    {
        return $this->lastUsage;
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

        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for llama.cpp embeddings');

        $wireHeaders = [];
        foreach ($headers as $name => $value) $wireHeaders[] = $name . ': ' . $value;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $wireHeaders,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 120,
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
