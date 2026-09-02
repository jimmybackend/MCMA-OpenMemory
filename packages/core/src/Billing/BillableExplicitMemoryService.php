<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

use MCMA\Core\Ask\GenerationProvider;
use MCMA\Core\Library;
use MCMA\Core\Memory\ExplicitMemoryService;
use MCMA\Core\Semantic\EmbeddingProvider;
use Throwable;

final class BillableExplicitMemoryService
{
    public function __construct(
        private readonly Library $library,
        private readonly BillingService $billing,
        private readonly ?EmbeddingProvider $embeddingProvider,
        private readonly ?GenerationProvider $generationProvider,
        private readonly int $maxOutputTokens = 1024
    ) {}

    public function capture(
        string $requestId,
        string $origin,
        string $text,
        array $billingMetadata = []
    ): array {
        $this->billing->authorizeChannel($this->library, $origin);

        $context = new BillingRequestContext(
            $this->billing,
            $this->library,
            $requestId,
            $origin,
            $text,
            $this->embeddingProvider?->id(),
            $this->generationProvider?->id(),
            $this->maxOutputTokens
        );

        $before = fn(string $kind,string $providerId,string $input) =>
            $context->beforeModelCall($kind,$providerId,$input);

        $embedding = $this->embeddingProvider !== null
            ? new MeteredEmbeddingProvider($this->embeddingProvider,$context->collector(),$before)
            : null;
        $generation = $this->generationProvider !== null
            ? new MeteredGenerationProvider($this->generationProvider,$context->collector(),$before)
            : null;

        try {
            $result = (new ExplicitMemoryService($this->library,$generation,$embedding))->capture('owner',$text);
            $result['billing'] = $context->settle('success',[
                'route'=>'memory-capture',
            ] + $billingMetadata);
            return $result;
        } catch (Throwable $e) {
            try { $context->abort($e->getMessage()); } catch (Throwable) {}
            throw $e;
        }
    }
}
