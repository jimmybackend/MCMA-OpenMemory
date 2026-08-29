<?php
declare(strict_types=1);

namespace MCMA\Core\Web;

final class HttpResponse
{
    /** @param array<string,string|array<int,string>> $headers */
    public function __construct(
        private readonly int $status,
        private readonly string $body = '',
        private readonly array $headers = []
    ) {
    }

    public static function json(array $payload, int $status = 200, array $headers = []): self
    {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;

        return new self($status, $body, ['content-type'=>'application/json; charset=utf-8'] + $headers);
    }

    public static function redirect(string $location, array $headers = [], int $status = 302): self
    {
        return new self($status, '', ['location'=>$location] + $headers);
    }

    public function send(): never
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) header($name . ': ' . $item, false);
            } else {
                header($name . ': ' . $value, true);
            }
        }
        echo $this->body;
        exit;
    }

    public function status(): int { return $this->status; }
    public function body(): string { return $this->body; }
    public function headers(): array { return $this->headers; }
}
