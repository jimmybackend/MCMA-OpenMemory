<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

use RuntimeException;

final class RecordedPaymentProvider implements PaymentProvider
{
    public function __construct(private readonly string $provider)
    {
        if(!in_array($this->provider,['stripe','paypal','mercadopago','bank-transfer','manual'],true)){
            throw new RuntimeException('Unsupported recorded payment provider');
        }
    }

    public function id(): string { return $this->provider; }

    public function normalizeVerifiedPayment(array $payment): array
    {
        $reference=trim((string)($payment['provider_payment_id']??''));
        $amount=(int)($payment['amount_micros']??0);
        $currency=strtoupper(trim((string)($payment['currency']??'USD')));
        $credits=(int)($payment['credit_units']??0);
        if($reference===''||strlen($reference)>256) throw new RuntimeException('Payment reference is required');
        if($amount<0||$credits<1) throw new RuntimeException('Payment amount/credits are invalid');
        if(!preg_match('/^[A-Z]{3}$/',$currency)) throw new RuntimeException('Invalid payment currency');

        return [
            'provider'=>$this->provider,
            'provider_payment_id'=>$reference,
            'amount_micros'=>$amount,
            'currency'=>$currency,
            'credit_units'=>$credits,
            'status'=>'confirmed',
        ];
    }
}
