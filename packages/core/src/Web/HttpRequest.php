<?php
declare(strict_types=1);

namespace MCMA\Core\Web;

use JsonException;
use RuntimeException;

final class HttpRequest
{
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $headers = [],
        private readonly array $query = [],
        private readonly array $cookies = [],
        private readonly string $body = ''
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') $path = '/';

        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (!is_string($value)) continue;
            if (str_starts_with($name, 'HTTP_')) {
                $key = strtolower(str_replace('_', '-', substr($name, 5)));
                $headers[$key] = $value;
            } elseif ($name === 'CONTENT_TYPE') {
                $headers['content-type'] = $value;
            } elseif ($name === 'CONTENT_LENGTH') {
                $headers['content-length'] = $value;
            }
        }

        $body = file_get_contents('php://input');
        if ($body === false) $body = '';

        return new self($method, $path, $headers, $_GET, $_COOKIE, $body);
    }

    public function method(): string { return $this->method; }
    public function path(): string { return $this->path; }

    public function header(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? null;
        return is_string($value) ? $value : null;
    }

    public function query(string $name): ?string
    {
        $value = $this->query[$name] ?? null;
        return is_scalar($value) ? (string)$value : null;
    }

    public function cookie(string $name): ?string
    {
        $value = $this->cookies[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    public function body(): string { return $this->body; }

    public function json(int $maxBytes = 65536): array
    {
        if (strlen($this->body) > $maxBytes) throw new WebException(413, 'request_too_large', 'Request body is too large');

        $contentType = strtolower(trim(explode(';', $this->header('content-type') ?? '')[0]));
        if ($contentType !== 'application/json') {
            throw new WebException(415, 'unsupported_media_type', 'Content-Type must be application/json');
        }

        try {
            $value = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new WebException(400, 'invalid_json', 'Request body is not valid JSON', $e);
        }

        if (!is_array($value) || array_is_list($value)) {
            throw new WebException(400, 'invalid_json_object', 'Request JSON must be an object');
        }
        return $value;
    }
}
