<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

use RuntimeException;

final class ManualPaymentProvider implements PaymentProvider
{
    public function id(): string { return 'manual'; }

    public function normalizeVerifiedPayment(array $payment): array
    {
        $reference = trim((string)($payment['provider_payment_id'] ?? ''));
        $amountMicros = (int)($payment['amount_micros'] ?? 0);
        $currency = strtoupper(trim((string)($payment['currency'] ?? 'USD')));
        $creditUnits = (int)($payment['credit_units'] ?? 0);

        if ($reference === '' || strlen($reference) > 256) throw new RuntimeException('Manual payment reference is required');
        if ($amountMicros < 0 || $creditUnits < 1) throw new RuntimeException('Manual payment amount/credits are invalid');
        if (!preg_match('/^[A-Z]{3}$/', $currency)) throw new RuntimeException('Invalid payment currency');

        return [
            'provider'=>'manual',
            'provider_payment_id'=>$reference,
            'amount_micros'=>$amountMicros,
            'currency'=>$currency,
            'credit_units'=>$creditUnits,
            'status'=>'confirmed',
        ];
    }
}
