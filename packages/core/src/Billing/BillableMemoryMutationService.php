<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

use MCMA\Core\Memory\MemoryMutationService;
use MCMA\Core\Library;
use MCMA\Core\Semantic\EmbeddingProvider;
use Throwable;

final class BillableMemoryMutationService
{
    public function __construct(
        private readonly Library $library,
        private readonly BillingService $billing,
        private readonly ?EmbeddingProvider $embeddingProvider
    ) {}

    public function execute(string $requestId,string $origin,string $text,array $metadata=[],?string $contextCanonicalRef=null): array
    {
        $this->billing->authorizeChannel($this->library,$origin);
        $context=new BillingRequestContext(
            $this->billing,$this->library,$requestId,$origin,$text,
            $this->embeddingProvider?->id(),null,0,0
        );
        $before=fn(string $kind,string $providerId,string $input)=>$context->beforeModelCall($kind,$providerId,$input);
        $embedding=$this->embeddingProvider!==null
            ?new MeteredEmbeddingProvider($this->embeddingProvider,$context->collector(),$before)
            :null;
        try{
            $result=(new MemoryMutationService($this->library,$embedding))->execute('owner',$text,$contextCanonicalRef);
            $result['billing']=$context->settle('success',['route'=>'memory-mutation']+$metadata);
            return $result;
        }catch(Throwable $e){
            try{$context->abort($e->getMessage());}catch(Throwable){}
            throw $e;
        }
    }
}
