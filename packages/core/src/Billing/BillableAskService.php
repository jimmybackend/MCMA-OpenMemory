<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

use MCMA\Core\Agent\Librarian;
use MCMA\Core\Ask\AskService;
use MCMA\Core\Ask\GenerationProvider;
use MCMA\Core\Context\ConversationContextBuilder;
use MCMA\Core\Knowledge\KnowledgeService;
use MCMA\Core\Library;
use MCMA\Core\Semantic\EmbeddingProvider;
use MCMA\Core\Semantic\SemanticIndexService;
use Throwable;

final class BillableAskService
{
    public function __construct(
        private readonly Library $library,
        private readonly BillingService $billing,
        private readonly ?EmbeddingProvider $embeddingProvider,
        private readonly ?GenerationProvider $generationProvider,
        private readonly int $maxOutputTokens = 1024,
        private readonly ?ConversationContextBuilder $conversationContextBuilder = null
    ) {
    }

    public function ask(
        string $requestId,
        string $origin,
        string $question,
        bool $currentRequired=false,
        float $minConfidence=0.75,
        float $minSimilarity=0.78,
        int $topK=5,
        bool $rememberGenerated=true,
        array $captureOptions=[],
        array $billingMetadata=[],
        ?float $candidateSimilarity=null,
        ?float $minRerankScore=null,
        ?string $conversationId=null
    ): array {
        $this->billing->authorizeChannel($this->library,$origin);

        $context=new BillingRequestContext(
            $this->billing,
            $this->library,
            $requestId,
            $origin,
            $question,
            $this->embeddingProvider?->id(),
            $this->generationProvider?->id(),
            $this->maxOutputTokens
        );

        $before=fn(string $kind,string $providerId,string $input)=>$context->beforeModelCall($kind,$providerId,$input);
        $embedding=$this->embeddingProvider!==null
            ? new MeteredEmbeddingProvider($this->embeddingProvider,$context->collector(),$before)
            : null;
        $generation=$this->generationProvider!==null
            ? new MeteredGenerationProvider($this->generationProvider,$context->collector(),$before)
            : null;

        $knowledge=new KnowledgeService($this->library);
        $semantic=$embedding!==null?new SemanticIndexService($this->library):null;
        $librarian=$embedding!==null
            ? new Librarian($knowledge,$semantic,$embedding)
            : new Librarian($knowledge);

        $ask=new AskService($knowledge,$semantic,$embedding,$generation,$librarian,$this->conversationContextBuilder);

        try{
            $result=$ask->ask(
                'ai',
                $question,
                $currentRequired,
                $minConfidence,
                $minSimilarity,
                $topK,
                $rememberGenerated,
                $captureOptions,
                $candidateSimilarity,
                $minRerankScore,
                $conversationId
            );
            $result['billing']=$context->settle('success',[
                'route'=>(string)($result['route']??'unknown'),
            ] + $billingMetadata);
            return $result;
        }catch(Throwable $e){
            try{$context->abort($e->getMessage());}catch(Throwable){}
            throw $e;
        }
    }
}
