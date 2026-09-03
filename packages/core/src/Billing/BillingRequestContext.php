<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

use MCMA\Core\Library;

final class BillingRequestContext
{
    private const GENERATION_CONTEXT_RESERVE_BYTES=16384;
    private readonly UsageCollector $collector;
    private ?array $reservation = null;
    private bool $closed = false;

    public function __construct(
        private readonly BillingService $billing,
        private readonly Library $library,
        private readonly string $requestId,
        private readonly string $origin,
        private readonly string $question,
        private readonly ?string $embeddingProviderId,
        private readonly ?string $generationProviderId,
        private readonly int $maxOutputTokens,
        private readonly int $additionalGenerationContextReserveBytes = 0
    ) {
        if($this->additionalGenerationContextReserveBytes<0||$this->additionalGenerationContextReserveBytes>131072){
            throw new BillingException('Invalid additional generation context reserve','invalid_context_reserve',500);
        }
        $this->collector = new UsageCollector();
    }

    public function collector(): UsageCollector
    {
        return $this->collector;
    }

    public function beforeModelCall(string $kind,string $providerId,string $input): void
    {
        if($this->closed) throw new BillingException('Billing request is already closed','billing_request_closed',409);
        if($this->reservation!==null) return;

        $estimateQuestion=$kind==='generation'?$input:$this->question;
        $contextReserve=($kind!=='generation'&&$this->generationProviderId!==null)
            ?self::GENERATION_CONTEXT_RESERVE_BYTES+$this->additionalGenerationContextReserveBytes
            :0;
        $estimate=$this->billing->estimateReservation(
            $estimateQuestion,
            $this->embeddingProviderId,
            $this->generationProviderId,
            $this->maxOutputTokens,
            $contextReserve
        );
        $providers=array_values(array_filter([
            $this->embeddingProviderId,
            $this->generationProviderId,
        ],static fn($v):bool=>is_string($v)&&$v!==''));

        $this->reservation=$this->billing->reserve(
            $this->library,
            $this->requestId,
            $this->origin,
            $providers,
            (int)$estimate['credit_units'],
            (int)($estimate['estimated_tokens']??0)
        );
    }

    public function settle(string $status='success',array $metadata=[]): array
    {
        if($this->closed) throw new BillingException('Billing request is already closed','billing_request_closed',409);
        $this->closed=true;

        if($this->reservation===null){
            return [
                'ai_billed'=>false,
                'credit_units_charged'=>0,
                'usage'=>$this->collector->summary(),
                'provider_usage'=>$this->collector->components(),
                'reason'=>'no-ai-provider-called',
            ];
        }

        $result=$this->billing->settle(
            $this->library,
            $this->requestId,
            (string)$this->reservation['reservation_id'],
            $this->origin,
            $this->collector,
            $status,
            $metadata
        );

        return [
            'ai_billed'=>true,
            'credit_units_charged'=>(int)($result['usage']['credit_units_charged']??0),
            'cost_micros'=>(int)($result['usage']['cost_micros']??0),
            'currency'=>(string)($result['usage']['currency']??'USD'),
            'usage'=>$this->collector->summary(),
            'provider_usage'=>$this->collector->components(),
            'pricing_snapshots'=>is_array($result['usage']['pricing_snapshots']??null)?$result['usage']['pricing_snapshots']:[],
            'balance_units'=>(int)($result['balance']['balance_units']??0),
            'reserved_units'=>(int)($result['balance']['reserved_units']??0),
        ];
    }

    public function abort(string $reason): array
    {
        if($this->closed) return ['closed'=>true];
        if($this->reservation===null){
            $this->closed=true;
            return ['released'=>false,'reason'=>'no-reservation'];
        }

        if($this->collector->components()!==[]){
            return $this->settle('failed',['failure_reason'=>substr($reason,0,512)]);
        }

        $this->closed=true;
        return $this->billing->release(
            $this->library,
            $this->requestId,
            (string)$this->reservation['reservation_id'],
            $reason
        );
    }
}
