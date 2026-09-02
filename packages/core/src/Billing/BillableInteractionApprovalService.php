<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

use MCMA\Core\Ask\GenerationProvider;
use MCMA\Core\Interaction\InteractionArchiveService;
use MCMA\Core\Interaction\InteractionCatalogService;
use MCMA\Core\Library;
use MCMA\Core\Semantic\EmbeddingProvider;
use Throwable;

final class BillableInteractionApprovalService
{
    public function __construct(
        private readonly Library $library,
        private readonly BillingService $billing,
        private readonly ?EmbeddingProvider $embeddingProvider,
        private readonly ?GenerationProvider $generationProvider,
        private readonly int $maxOutputTokens=1024
    ) {}

    public function validate(
        string $requestId,
        string $origin,
        string $interactionRef,
        string $action,
        array $billingMetadata=[]
    ): array {
        $this->billing->authorizeChannel($this->library,$origin);

        if($action==='discard'){
            $result=(new InteractionArchiveService($this->library))->validate(
                'owner',$interactionRef,'discard',null,$this->embeddingProvider
            );
            $result['billing']=[
                'credit_units_charged'=>0,
                'usage'=>['total_tokens'=>0],
                'route'=>'interaction-discard',
            ];
            return $result;
        }

        $context=new BillingRequestContext(
            $this->billing,$this->library,$requestId,$origin,$interactionRef,
            $this->embeddingProvider?->id(),$this->generationProvider?->id(),$this->maxOutputTokens
        );
        $before=fn(string $kind,string $providerId,string $input)=>$context->beforeModelCall($kind,$providerId,$input);
        $embedding=$this->embeddingProvider!==null
            ?new MeteredEmbeddingProvider($this->embeddingProvider,$context->collector(),$before):null;
        $generation=$this->generationProvider!==null
            ?new MeteredGenerationProvider($this->generationProvider,$context->collector(),$before):null;

        try{
            $result=(new InteractionArchiveService($this->library))->validate(
                'owner',$interactionRef,'approve',new InteractionCatalogService($generation),$embedding
            );
            $result['billing']=$context->settle('success',[
                'route'=>'interaction-approval',
            ]+$billingMetadata);
            return $result;
        }catch(Throwable $e){
            try{$context->abort($e->getMessage());}catch(Throwable){}
            throw $e;
        }
    }
}
