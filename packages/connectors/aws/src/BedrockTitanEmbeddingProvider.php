<?php
declare(strict_types=1);

namespace MCMA\Connectors\Aws;

use JsonException;
use MCMA\Core\Semantic\EmbeddingProvider;
use MCMA\Core\Billing\UsageAwareEmbeddingProvider;
use MCMA\Core\Semantic\VectorMath;
use MCMA\Core\Storage\AwsSigV4;
use RuntimeException;

final class BedrockTitanEmbeddingProvider implements EmbeddingProvider, UsageAwareEmbeddingProvider
{
    /** @var null|callable */
    private $requester;
    private array $lastUsage = ['inputTokens'=>0,'totalTokens'=>0,'method'=>'unavailable'];

    public function __construct(
        private readonly string $region = 'us-east-1',
        private readonly int $dimensions = 256,
        private readonly string $modelId = 'amazon.titan-embed-text-v2:0',
        private readonly ?string $bearerToken = null,
        private readonly ?string $accessKey = null,
        private readonly ?string $secretKey = null,
        private readonly ?string $sessionToken = null,
        ?callable $requester = null
    ) {
        if (!preg_match('/^[a-z0-9-]+$/', $this->region)) throw new RuntimeException('Invalid Bedrock region');
        if (!in_array($this->dimensions, [256, 512, 1024], true)) throw new RuntimeException('Titan Text Embeddings V2 dimensions must be 256, 512 or 1024');
        if ($this->modelId === '') throw new RuntimeException('Bedrock embedding model id is required');
        if ($this->bearerToken === null && (($this->accessKey ?? '') === '' || ($this->secretKey ?? '') === '')) {
            throw new RuntimeException('Bedrock credentials are required');
        }
        $this->requester = $requester;
    }

    public static function fromEnvironment(?int $dimensions = null): self
    {
        $region = self::firstEnv(['MCMA_BEDROCK_REGION', 'AWS_REGION', 'AWS_DEFAULT_REGION']) ?? 'us-east-1';
        $model = self::firstEnv(['MCMA_BEDROCK_EMBED_MODEL']) ?? 'amazon.titan-embed-text-v2:0';
        $dimensions ??= (int)(self::firstEnv(['MCMA_BEDROCK_DIMENSIONS']) ?? '256');

        return new self(
            $region,
            $dimensions,
            $model,
            self::firstEnv(['MCMA_BEDROCK_BEARER_TOKEN', 'AWS_BEARER_TOKEN_BEDROCK']),
            self::firstEnv(['MCMA_BEDROCK_ACCESS_KEY_ID', 'AWS_ACCESS_KEY_ID']),
            self::firstEnv(['MCMA_BEDROCK_SECRET_ACCESS_KEY', 'AWS_SECRET_ACCESS_KEY']),
            self::firstEnv(['MCMA_BEDROCK_SESSION_TOKEN', 'AWS_SESSION_TOKEN'])
        );
    }

    public function id(): string
    {
        return 'bedrock:' . $this->modelId . ':dimensions=' . $this->dimensions . ':normalize=true';
    }

    public function embed(string $text): array
    {
        $text = trim($text);
        if ($text === '') throw new RuntimeException('Embedding text must not be empty');

        try {
            $body = json_encode([
                'inputText' => $text,
                'dimensions' => $this->dimensions,
                'normalize' => true,
                'embeddingTypes' => ['float'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Unable to encode Bedrock embedding request', 0, $e);
        }

        $host = 'bedrock-runtime.' . $this->region . '.amazonaws.com';
        $rawPath = '/model/' . $this->modelId . '/invoke';
        $path = AwsSigV4::canonicalUri($rawPath);
        $url = 'https://' . $host . $path;
        $headers = ['content-type' => 'application/json', 'accept' => 'application/json'];

        if ($this->bearerToken !== null && $this->bearerToken !== '') {
            $headers['authorization'] = 'Bearer ' . $this->bearerToken;
        } else {
            $signed = AwsSigV4::sign(
                'POST',
                $host,
                $path,
                [],
                $headers,
                $body,
                (string)$this->accessKey,
                (string)$this->secretKey,
                $this->region,
                'bedrock',
                null,
                $this->sessionToken
            );
            $headers = $signed['headers'];
        }

        [$status, $responseBody] = $this->request('POST', $url, $headers, $body);
        if ($status !== 200) {
            $detail = self::safeAwsErrorDetail($responseBody);
            throw new RuntimeException('Bedrock embedding request failed: HTTP ' . $status . ($detail !== '' ? ' - ' . $detail : ''));
        }

        try {
            $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Bedrock embedding response is not valid JSON', 0, $e);
        }

        $embedding = $response['embedding'] ?? ($response['embeddingsByType']['float'] ?? null);
        if (!is_array($embedding)) throw new RuntimeException('Bedrock embedding response did not contain a float embedding');

        $count = $response['inputTextTokenCount'] ?? null;
        if (is_int($count) && $count >= 0) {
            $this->lastUsage = ['inputTokens'=>$count,'totalTokens'=>$count,'method'=>'provider'];
        } else {
            $estimate = max(1, strlen($text));
            $this->lastUsage = ['inputTokens'=>$estimate,'totalTokens'=>$estimate,'method'=>'estimated-bytes-upper-bound'];
        }

        return VectorMath::normalize($embedding, $this->dimensions);
    }

    /** @return array{0:int,1:string,2:array<string,string>} */
    public function lastUsage(): array
    {
        return $this->lastUsage;
    }

    private function request(string $method, string $url, array $headers, string $body): array
    {
        if ($this->requester !== null) {
            $result = ($this->requester)($method, $url, $headers, $body);
            if (!is_array($result) || count($result) < 2) throw new RuntimeException('Invalid Bedrock requester result');
            return [(int)$result[0], (string)$result[1], is_array($result[2] ?? null) ? $result[2] : []];
        }

        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for Bedrock embeddings');

        $wireHeaders = [];
        foreach ($headers as $name => $value) $wireHeaders[] = $name . ': ' . $value;

        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $wireHeaders,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $pos = strpos($line, ':');
                if ($pos !== false) $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                return $length;
            },
        ]);

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Bedrock HTTP error: ' . $error);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, (string)$responseBody, $responseHeaders];
    }

    private static function safeAwsErrorDetail(string $responseBody): string
    {
        try {
            $decoded = json_decode($responseBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '';
        }
        if (!is_array($decoded)) return '';

        $type = $decoded['__type'] ?? $decoded['code'] ?? $decoded['Code'] ?? null;
        $message = $decoded['message'] ?? $decoded['Message'] ?? null;
        $parts = [];
        foreach ([$type, $message] as $part) {
            if (!is_string($part) || trim($part) === '') continue;
            $clean = preg_replace('/[\\x00-\\x1F\\x7F]+/u', ' ', trim($part));
            if (!is_string($clean) || $clean === '') continue;
            $parts[] = substr($clean, 0, 400);
        }
        return implode(': ', array_values(array_unique($parts)));
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
