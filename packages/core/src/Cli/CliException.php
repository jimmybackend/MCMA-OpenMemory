<?php
declare(strict_types=1);

namespace MCMA\Core\Cli;

use RuntimeException;

final class CliException extends RuntimeException
{
    public function __construct(
        string $message,
        int $exitCode = 1,
        private readonly bool $usage = false
    ) {
        parent::__construct($message, $exitCode);
    }

    public function exitCode(): int
    {
        return $this->getCode() > 0 ? $this->getCode() : 1;
    }

    public function isUsage(): bool
    {
        return $this->usage;
    }
}
