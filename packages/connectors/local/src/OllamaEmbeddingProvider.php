<?php
declare(strict_types=1);

namespace MCMA\Connectors\Local;

use JsonException;
use MCMA\Core\Semantic\EmbeddingProvider;
use MCMA\Core\Semantic\VectorMath;
use RuntimeException;

final class OllamaEmbeddingProvider implements EmbeddingProvider
{
    /** @var null|callable */
    private $requester;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        ?callable $requester = null
    ) {
        self::validateBaseUrl($this->baseUrl);
        if (trim($this->model) === '' || strlen($this->model) > 512) {
            throw new RuntimeException('Ollama embedding model is required');
        }
        $this->requester = $requester;
    }

    public static function fromEnvironment(): self
    {
        $baseUrl = self::firstEnv(['MCMA_OLLAMA_BASE_URL']) ?? 'http://127.0.0.1:11434';
        $model = self::firstEnv(['MCMA_OLLAMA_EMBED_MODEL']);
        if ($model === null) throw new RuntimeException('MCMA_OLLAMA_EMBED_MODEL is required for local embeddings');
        return new self($baseUrl, $model);
    }

    public function id(): string
    {
        return 'ollama:' . $this->model . ':embed';
    }

    public function embed(string $text): array
    {
        $text = trim($text);
        if ($text === '') throw new RuntimeException('Embedding text must not be empty');

        try {
            $body = json_encode([
                'model' => $this->model,
                'input' => $text,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Unable to encode Ollama embedding request', 0, $e);
        }

        [$status, $responseBody] = $this->request(
            'POST',
            rtrim($this->baseUrl, '/') . '/api/embed',
            ['content-type'=>'application/json','accept'=>'application/json'],
            $body
        );
        if ($status !== 200) throw new RuntimeException('Ollama embedding request failed: HTTP ' . $status);

        try {
            $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Ollama embedding response is not valid JSON', 0, $e);
        }

        $embeddings = $response['embeddings'] ?? null;
        if (!is_array($embeddings) || !isset($embeddings[0]) || !is_array($embeddings[0])) {
            throw new RuntimeException('Ollama embedding response did not contain embeddings');
        }

        return VectorMath::normalize($embeddings[0]);
    }

    /** @return array{0:int,1:string,2:array<string,string>} */
    private function request(string $method, string $url, array $headers, string $body): array
    {
        if ($this->requester !== null) {
            $result = ($this->requester)($method, $url, $headers, $body);
            if (!is_array($result) || count($result) < 2) throw new RuntimeException('Invalid Ollama requester result');
            return [(int)$result[0], (string)$result[1], is_array($result[2] ?? null) ? $result[2] : []];
        }

        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for Ollama embeddings');

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
            throw new RuntimeException('Ollama HTTP error: ' . $error);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, (string)$responseBody, []];
    }

    private static function validateBaseUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http','https'], true) || trim((string)($parts['host'] ?? '')) === '') {
            throw new RuntimeException('Invalid Ollama base URL');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Ollama base URL must not contain credentials, query or fragment');
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
