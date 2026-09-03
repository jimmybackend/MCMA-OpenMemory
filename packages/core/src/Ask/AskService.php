<?php
declare(strict_types=1);

namespace MCMA\Core\Ask;

use MCMA\Core\Agent\Librarian;
use MCMA\Core\Context\BroadMemoryRecallBuilder;
use MCMA\Core\Context\ConversationContextBuilder;
use MCMA\Core\Context\MultiMemoryContextBuilder;
use MCMA\Core\Knowledge\KnowledgeRecord;
use MCMA\Core\Knowledge\KnowledgeService;
use MCMA\Core\Semantic\EmbeddingProvider;
use MCMA\Core\Semantic\SemanticIndexService;
use RuntimeException;

final class AskService
{
    public function __construct(
        private readonly KnowledgeService $knowledge,
        private readonly ?SemanticIndexService $semantic = null,
        private readonly ?EmbeddingProvider $embeddingProvider = null,
        private readonly ?GenerationProvider $generationProvider = null,
        private readonly ?Librarian $librarian = null,
        private readonly ?ConversationContextBuilder $conversationContextBuilder = null,
        private readonly ?MultiMemoryContextBuilder $multiMemoryContextBuilder = null,
        private readonly ?BroadMemoryRecallBuilder $broadMemoryRecallBuilder = null
    ) {
        if (($this->semantic === null) !== ($this->embeddingProvider === null)) {
            throw new RuntimeException('Ask semantic retrieval requires both SemanticIndexService and EmbeddingProvider');
        }
    }

    public function ask(
        string $actor,
        string $question,
        bool $currentRequired = false,
        float $minConfidence = 0.75,
        float $minSimilarity = 0.78,
        int $topK = 5,
        bool $rememberGenerated = true,
        array $captureOptions = [],
        ?float $candidateSimilarity = null,
        ?float $minRerankScore = null,
        ?string $conversationId = null
    ): array {
        $normalized = KnowledgeRecord::normalizeIntent($question);

        $exact = $this->knowledge->directAnswer($actor, $question, $currentRequired, $minConfidence);
        if (($exact['reusable'] ?? false) === true && isset($exact['answer'])) {
            $exact['route'] = 'memory-exact';
            $exact['provider_called'] = false;
            return $exact;
        }

        $memoryAttempt = $exact;
        $rankedSemantic = null;
        if (($exact['reusable'] ?? false) !== true && $this->semantic !== null && $this->embeddingProvider !== null) {
            $discoveryTopK=$topK;
            $discoveryCandidateSimilarity=$candidateSimilarity;
            if($this->multiMemoryContextBuilder!==null){
                $discoveryTopK=max($topK,$this->multiMemoryContextBuilder->candidateLimit());
                $ragFloor=$this->multiMemoryContextBuilder->candidateSimilarityFloor();
                $discoveryCandidateSimilarity=$discoveryCandidateSimilarity===null
                    ?min($minSimilarity,$ragFloor)
                    :min($discoveryCandidateSimilarity,$ragFloor);
            }

            // Embed the current question exactly once. The same ranked pool is
            // reused both for the strict direct-semantic decision and, only if
            // generation is still required, for multi-memory RAG assembly.
            $rankedSemantic=$this->semantic->topK(
                $actor,
                $question,
                $this->embeddingProvider,
                $currentRequired,
                $minConfidence,
                $minSimilarity,
                $discoveryTopK,
                null,
                $discoveryCandidateSimilarity
            );
            $memoryAttempt=$this->semantic->answerFromTopK(
                $actor,
                $question,
                $rankedSemantic,
                $minSimilarity,
                $candidateSimilarity,
                $minRerankScore
            );

            if (($memoryAttempt['reusable'] ?? false) === true && isset($memoryAttempt['answer'])) {
                $memoryAttempt['route'] = 'memory-semantic';
                $memoryAttempt['provider_called'] = false;
                return $memoryAttempt;
            }
        }

        if ($this->generationProvider === null) {
            return [
                'found' => false,
                'reusable' => false,
                'decision' => 'provider-required',
                'route' => 'ask',
                'provider_called' => false,
                'logical_ref' => KnowledgeRecord::logicalRef($question),
                'normalized_intent' => $normalized,
                'memory_attempt' => self::memorySummary($memoryAttempt),
                'reasons' => ['no-reusable-memory', 'generation-provider-not-configured'],
            ];
        }

        $multiMemoryContext=null;
        if($this->multiMemoryContextBuilder!==null&&is_array($rankedSemantic)){
            try{
                $multiMemoryContext=$this->multiMemoryContextBuilder->build(
                    $actor,$question,$rankedSemantic,$currentRequired,$minConfidence
                );
            }catch(\Throwable $e){
                error_log('MCMA multi-memory RAG builder error: '.$e->getMessage());
                $multiMemoryContext=null;
            }
        }

        $contextAttempt=(($memoryAttempt['found']??false)===true)?$memoryAttempt:$exact;
        // Keep the original single-memory revalidation context as a fallback
        // when no semantic multi-memory context could be assembled.
        $memoryContext=$multiMemoryContext===null
            ?$this->generationMemoryContext($actor,$question,$contextAttempt,$minConfidence)
            :null;

        $conversationContext=null;
        if(
            $this->conversationContextBuilder!==null
            && is_string($conversationId)
            && preg_match('/^conv_[0-9a-f]{32}$/',$conversationId)
        ){
            try{
                $conversationContext=$this->conversationContextBuilder->build($actor,$conversationId,$question);
            }catch(\Throwable $e){
                error_log('MCMA conversation context builder error: '.$e->getMessage());
                $conversationContext=null;
            }
        }

        $broadRecallContext=null;
        if($this->broadMemoryRecallBuilder!==null){
            try{
                $broadRecallContext=$this->broadMemoryRecallBuilder->build($actor,$question,$minConfidence);
            }catch(\Throwable $e){
                error_log('MCMA broad memory recall builder error: '.$e->getMessage());
                $broadRecallContext=null;
            }
        }

        $generationContext = [
            'actor' => $actor,
            'current_required' => $currentRequired,
            'memory_attempt' => self::memorySummary($memoryAttempt),
        ];
        if ($memoryContext !== null) $generationContext['memory_context'] = $memoryContext;
        if ($multiMemoryContext !== null) $generationContext['multi_memory_context'] = $multiMemoryContext;
        if ($conversationContext !== null) $generationContext['conversation_context'] = $conversationContext;
        if ($broadRecallContext !== null) $generationContext['broad_recall_context'] = $broadRecallContext;

        $generated = $this->generationProvider->generate($question, $generationContext);
        $text = trim((string)($generated['text'] ?? ''));
        if ($text === '') throw new RuntimeException('Generation provider returned an empty answer');

        $storeResult = null;
        $storeReason = null;
        $exactExists = ($exact['found'] ?? false) === true;

        if (!$rememberGenerated) {
            $storeReason = 'remember-generated-disabled';
        } elseif ($exactExists) {
            // Never overwrite an exact disputed/stale/unverified record merely
            // because a model produced fresh text. Preserve its history so a
            // Librarian or validation workflow can decide what replaces it.
            $storeReason = 'existing-exact-record-preserved';
        } elseif ($this->librarian === null) {
            $storeReason = 'librarian-not-configured';
        } else {
            $provenance = [[
                'source_type' => 'model',
                'reference' => $this->generationProvider->id(),
                'note' => 'Generated by mcma ask',
            ]];
            if($multiMemoryContext!==null){
                foreach($multiMemoryContext['memories']??[] as $memory){
                    if(!is_array($memory)||!is_string($memory['logical_ref']??null)) continue;
                    $provenance[]=[
                        'source_type'=>'memory',
                        'reference'=>$memory['logical_ref'],
                        'note'=>'Selected by multi-memory RAG',
                    ];
                }
            }
            if($broadRecallContext!==null){
                foreach($broadRecallContext['items']??[] as $memory){
                    if(!is_array($memory)||!is_string($memory['logical_ref']??null)||$memory['logical_ref']==='') continue;
                    $provenance[]=[
                        'source_type'=>'memory',
                        'reference'=>$memory['logical_ref'],
                        'note'=>'Selected by broad entity recall',
                    ];
                }
            }
            if (is_array($captureOptions['provenance'] ?? null)) {
                $provenance = array_values(array_merge($provenance, $captureOptions['provenance']));
            }

            $freshness = (string)($captureOptions['freshness_class'] ?? 'stable');
            $maxAge = array_key_exists('max_age_seconds', $captureOptions)
                ? ($captureOptions['max_age_seconds'] === null ? null : (int)$captureOptions['max_age_seconds'])
                : ($freshness === 'immutable' ? null : 2592000);

            $relations=is_array($captureOptions['relations']??null)?$captureOptions['relations']:[];
            if($multiMemoryContext!==null){
                foreach($multiMemoryContext['memories']??[] as $memory){
                    if(!is_array($memory)) continue;
                    foreach([$memory['canonical_memory_ref']??null,$memory['logical_ref']??null] as $relation){
                        if(is_string($relation)&&str_starts_with($relation,'memory://')) $relations[]=$relation;
                    }
                }
                $relations=array_values(array_unique($relations));
            }
            if($broadRecallContext!==null){
                foreach($broadRecallContext['items']??[] as $memory){
                    $relation=is_array($memory)?($memory['logical_ref']??null):null;
                    if(is_string($relation)&&str_starts_with($relation,'memory://')) $relations[]=$relation;
                }
                $relations=array_values(array_unique($relations));
            }

            $storeResult = $this->librarian->remember($question, $text, [
                'answer_format' => 'text',
                'confidence' => (float)($captureOptions['confidence'] ?? 0.5),
                'validation_state' => (string)($captureOptions['validation_state'] ?? 'unverified'),
                'provenance' => $provenance,
                'freshness_class' => $freshness,
                'max_age_seconds' => $maxAge,
                'reuse_policy' => (string)($captureOptions['reuse_policy'] ?? 'reuse-unless-stale'),
                'relations' => $relations,
            ]);
        }

        $result = [
            'found' => true,
            'reusable' => false,
            'decision' => 'generated',
            'route' => 'provider',
            'provider_called' => true,
            'provider_id' => $this->generationProvider->id(),
            'logical_ref' => KnowledgeRecord::logicalRef($question),
            'normalized_intent' => $normalized,
            'memory_attempt' => self::memorySummary($memoryAttempt),
            'answer' => ['format' => 'text', 'value' => $text],
            'stored' => $storeResult !== null,
        ];

        if ($storeResult !== null) {
            $result['storage'] = [
                'object_id' => $storeResult['object_id'],
                'storage_hash' => $storeResult['storage_hash'],
                'created' => $storeResult['created'] ?? null,
                'validation_state' => (string)($captureOptions['validation_state'] ?? 'unverified'),
                'confidence' => (float)($captureOptions['confidence'] ?? 0.5),
                'semantic_index' => $storeResult['semantic_index'] ?? null,
            ];
        } else {
            $result['store_reason'] = $storeReason;
        }

        if ($memoryContext !== null) {
            $result['context_used'] = [
                'memory' => true,
                'logical_ref' => $memoryContext['logical_ref'],
                'matched_question' => $memoryContext['question'],
                'answer' => [
                    'format' => $memoryContext['answer_format'],
                    'value' => $memoryContext['answer'],
                ],
                'validation_state' => $memoryContext['validation_state'],
                'confidence' => $memoryContext['confidence'],
                'freshness_class' => $memoryContext['freshness_class'],
                'stale' => $memoryContext['stale'],
                'reasons' => $memoryContext['reasons'],
            ];
        } else {
            $result['context_used'] = ['memory' => false];
        }

        $result['context_used']['multi_memory']=$multiMemoryContext!==null;
        if($multiMemoryContext!==null){
            $result['context_used']['multi_memory_context']=$multiMemoryContext;
        }

        $result['context_used']['conversation']=$conversationContext!==null;
        if($conversationContext!==null){
            // Context transparency intentionally records exactly which selected
            // historical turns were supplied to generation.
            $result['context_used']['conversation_context']=$conversationContext;
        }

        $result['context_used']['broad_recall']=$broadRecallContext!==null;
        if($broadRecallContext!==null){
            $result['context_used']['broad_recall_context']=$broadRecallContext;
        }

        if (isset($generated['usage']) && is_array($generated['usage'])) $result['usage'] = $generated['usage'];
        if (array_key_exists('stop_reason', $generated)) $result['stop_reason'] = $generated['stop_reason'];

        return $result;
    }

    private function generationMemoryContext(string $actor, string $question, array $memoryAttempt, float $minConfidence): ?array
    {
        if (($memoryAttempt['found'] ?? false) !== true) return null;
        if (($memoryAttempt['decision'] ?? null) !== 'revalidate') return null;

        $state=(string)($memoryAttempt['validation_state']??'');
        $confidence=(float)($memoryAttempt['confidence']??-1);
        if(!in_array($state,['supported','verified'],true)||$confidence<$minConfidence) return null;

        $reasons=is_array($memoryAttempt['reasons']??null)?array_values($memoryAttempt['reasons']):[];
        foreach(['validation-insufficient','confidence-below-threshold','validation-state-disputed','validation-state-retracted','reuse-policy-never-direct'] as $blocked){
            if(in_array($blocked,$reasons,true)) return null;
        }

        $matchedQuestion=(string)($memoryAttempt['matched_question']??$question);
        try{
            $stored=$this->knowledge->inspect($actor,$matchedQuestion);
        }catch(\Throwable){
            return null;
        }
        $record=$stored['record']??null;
        if(!is_array($record)) return null;

        $answer=$record['answer']['value']??null;
        $format=(string)($record['answer']['format']??'text');
        if(is_array($answer)||is_object($answer)){
            $answer=json_encode($answer,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        }elseif(!is_string($answer)){
            return null;
        }
        if(!is_string($answer)||trim($answer)==='') return null;
        if(strlen($answer)>12000) $answer=substr($answer,0,12000)."\n[context truncated]";

        return [
            'logical_ref'=>(string)($stored['logical_ref']??KnowledgeRecord::logicalRef($matchedQuestion)),
            'question'=>$matchedQuestion,
            'answer_format'=>$format,
            'answer'=>$answer,
            'validation_state'=>$state,
            'confidence'=>$confidence,
            'freshness_class'=>(string)($memoryAttempt['freshness_class']??'stable'),
            'stale'=>(bool)($memoryAttempt['stale']??false),
            'reasons'=>$reasons,
        ];
    }

    private static function memorySummary(array $memory): array
    {
        $keys = [
            'found','reusable','decision','route','reasons','logical_ref',
            'matched_logical_ref','matched_question','object_id','storage_hash',
            'similarity','rerank_score','validation_state','confidence',
            'freshness_class','reuse_policy','stale','stale_index_entries'
        ];

        $summary = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $memory)) $summary[$key] = $memory[$key];
        }
        return $summary;
    }
}
