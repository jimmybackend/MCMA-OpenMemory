<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

interface PaymentProvider
{
    public function id(): string;

    /**
     * Normalize an already verified provider payment into MCMA's payment ledger.
     * Provider-specific webhook verification belongs in the connector.
     */
    public function normalizeVerifiedPayment(array $payment): array;
}
