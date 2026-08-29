<?php
declare(strict_types=1);

namespace MCMA\Core\Web;

use Throwable;

final class WebException extends \RuntimeException
{
    public function __construct(
        private readonly int $status,
        private readonly string $error,
        string $message,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int { return $this->status; }
    public function error(): string { return $this->error; }
}
