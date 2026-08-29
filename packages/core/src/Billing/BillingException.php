<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

final class BillingException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $reason = 'billing_error',
        private readonly int $httpStatus = 402
    ) {
        parent::__construct($message);
    }

    public function reason(): string { return $this->reason; }
    public function httpStatus(): int { return $this->httpStatus; }
}
