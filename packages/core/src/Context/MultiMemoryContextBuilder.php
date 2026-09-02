<?php
declare(strict_types=1);

namespace MCMA\Core\Context;

use MCMA\Core\Knowledge\KnowledgeRecord;
use MCMA\Core\Library;
use RuntimeException;
use Throwable;

final class MultiMemoryContextBuilder
{
    public function __construct(
        private readonly Library $library,
        private readonly int $tokenBudget = 8000,
        private readonly int $maxMemories = 4,
        private readonly int $candidateLimit = 8,
        private readonly float $candidateSimilarity = 0.55,
        private readonly float $minRagScore = 0.50,
        private readonly int $maxAnswerBytes = 4500,
        private readonly int $maxProvenanceEntries = 4
    ) {
        if($this->tokenBudget<512||$this->tokenBudget>65536){
            throw new RuntimeException('Multi-memory RAG token budget must be between 512 and 65536');
        }
        if($this->maxMemories<1||$this->maxMemories>12){
            throw new RuntimeException('Multi-memory RAG max memories must be between 1 and 12');
        }
        if($this->candidateLimit<$this->maxMemories||$this->candidateLimit>32){
            throw new RuntimeException('Multi-memory RAG candidate limit must be between max memories and 32');
        }
        foreach([
            'candidate similarity'=>$this->candidateSimilarity,
            'minimum RAG score'=>$this->minRagScore,
        ] as $name=>$value){
            if(!is_finite($value)||$value<0.0||$value>1.0){
                throw new RuntimeException('Multi-memory RAG '.$name.' must be between 0 and 1');
            }
        }
        if($this->maxAnswerBytes<256||$this->maxAnswerBytes>20000){
            throw new RuntimeException('Multi-memory RAG max answer bytes must be between 256 and 20000');
        }
        if($this->maxProvenanceEntries<1||$this->maxProvenanceEntries>12){
            throw new RuntimeException('Multi-memory RAG max provenance entries must be between 1 and 12');
        }
    }

    public function tokenBudgetUpperBound(): int
    {
        return $this->tokenBudget;
    }

    public function candidateLimit(): int
    {
        return $this->candidateLimit;
    }

    public function candidateSimilarityFloor(): float
    {
        return $this->candidateSimilarity;
    }

    public function build(
        string $actor,
        string $question,
        array $rankedSemantic,
        bool $currentRequired,
        float $minConfidence
    ): ?array {
        $question=trim($question);
        if($question==='') return null;

        $candidates=is_array($rankedSemantic['candidates']??null)
            ?array_slice(array_values($rankedSemantic['candidates']),0,$this->candidateLimit)
            :[];
        if($candidates===[]) return null;

        $eligible=[];
        foreach($candidates as $candidate){
            if(!is_array($candidate)) continue;

            $logicalRef=$candidate['logical_ref']??null;
            if(!is_string($logicalRef)||!preg_match('#^memory://knowledge/q-[0-9a-f]{64}$#',$logicalRef)) continue;

            // Top-K visibility is already actor-aware, but canonical data is
            // deliberately re-read through the same actor so the RAG builder
            // cannot turn a derived semantic index into an authorization path.
            try{
                $stored=$this->library->readAs($actor,$logicalRef);
            }catch(Throwable){
                continue;
            }

            $record=$stored['payload']['content']??null;
            if(!is_array($record)) continue;
            try{
                KnowledgeRecord::validate($record);
                $assessment=KnowledgeRecord::assess($record,$currentRequired,$minConfidence);
            }catch(Throwable){
                continue;
            }

            $state=(string)($record['epistemic']['validation_state']??'');
            $confidence=(float)($record['epistemic']['confidence']??0.0);
            if(!in_array($state,['supported','verified'],true)) continue;
            if($confidence<$minConfidence) continue;
            if(in_array((string)($assessment['decision']??''),['reject'],true)) continue;

            $reasons=is_array($assessment['reasons']??null)?array_values($assessment['reasons']):[];
            foreach(['validation-state-disputed','validation-state-retracted','reuse-policy-never-direct','validation-insufficient','confidence-below-threshold'] as $blocked){
                if(in_array($blocked,$reasons,true)) continue 2;
            }

            $answer=self::answerText($record['answer']['value']??null);
            if($answer===null) continue;
            $answer=self::clip($answer,$this->maxAnswerBytes);
            $memoryQuestion=self::clip((string)($record['intent']['question']??''),1400);

            $similarity=(float)($candidate['similarity']??0.0);
            $similarityFactor=max(0.0,min(1.0,($similarity+1.0)/2.0));
            $freshnessFactor=self::freshnessFactor(
                (string)($record['freshness']['class']??'stable'),
                (bool)($assessment['stale']??false)
            );
            [$provenance,$provenanceFactor]=self::provenanceSummary(
                is_array($record['provenance']??null)?$record['provenance']:[],
                $this->maxProvenanceEntries
            );
            $validationFactor=$state==='verified'?1.0:0.90;

            $ragScore=
                (0.42*$similarityFactor)+
                (0.22*$confidence)+
                (0.14*$freshnessFactor)+
                (0.17*$provenanceFactor)+
                (0.05*$validationFactor);

            if($ragScore<$this->minRagScore) continue;

            $canonicalRef=null;
            foreach($record['relations']??[] as $relation){
                if(is_string($relation)&&str_starts_with($relation,'memory://user/')){
                    $canonicalRef=$relation;
                    break;
                }
            }

            $eligible[]=[
                'logical_ref'=>$logicalRef,
                'canonical_memory_ref'=>$canonicalRef,
                'question'=>$memoryQuestion,
                'answer_format'=>(string)($record['answer']['format']??'text'),
                'answer'=>$answer,
                'similarity'=>round($similarity,6),
                'rerank_score'=>round((float)($candidate['rerank_score']??0.0),6),
                'rag_score'=>round($ragScore,6),
                'validation_state'=>$state,
                'confidence'=>$confidence,
                'freshness_class'=>(string)($record['freshness']['class']??'stable'),
                'stale'=>(bool)($assessment['stale']??false),
                'reuse_policy'=>(string)($record['freshness']['reuse_policy']??'reuse-unless-stale'),
                'provenance_score'=>round($provenanceFactor,6),
                'provenance'=>$provenance,
                'reasons'=>$reasons,
            ];
        }

        if($eligible===[]) return null;

        usort($eligible,static function(array $a,array $b): int {
            $cmp=((float)$b['rag_score'])<=>((float)$a['rag_score']);
            if($cmp!==0) return $cmp;
            $cmp=((float)$b['similarity'])<=>((float)$a['similarity']);
            if($cmp!==0) return $cmp;
            $cmp=((float)$b['confidence'])<=>((float)$a['confidence']);
            if($cmp!==0) return $cmp;
            return (string)$a['logical_ref']<=>(string)$b['logical_ref'];
        });

        $selected=[];
        $used=0;
        foreach($eligible as $memory){
            if(count($selected)>=$this->maxMemories) break;

            $memory['estimated_tokens_upper_bound']=0;
            $encoded=json_encode($memory,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if(!is_string($encoded)) continue;
            $cost=max(1,strlen($encoded));
            $memory['estimated_tokens_upper_bound']=$cost;
            $encoded=json_encode($memory,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if(!is_string($encoded)) continue;
            $cost=max(1,strlen($encoded));
            $memory['estimated_tokens_upper_bound']=$cost;

            if($used+$cost>$this->tokenBudget) continue;
            $used+=$cost;
            $selected[]=$memory;
        }

        if($selected===[]) return null;

        return [
            'query'=>$question,
            'selection'=>[
                'strategy'=>'multi-memory-rag-v1',
                'candidate_limit'=>$this->candidateLimit,
                'candidates_considered'=>count($candidates),
                'eligible_candidates'=>count($eligible),
                'selected_memories'=>count($selected),
                'max_memories'=>$this->maxMemories,
                'candidate_similarity_floor'=>$this->candidateSimilarity,
                'min_rag_score'=>$this->minRagScore,
                'token_budget'=>$this->tokenBudget,
                'estimated_tokens_upper_bound'=>$used,
                'token_estimate_method'=>'estimated-bytes-upper-bound',
                'score_weights'=>[
                    'similarity'=>0.42,
                    'confidence'=>0.22,
                    'freshness'=>0.14,
                    'provenance'=>0.17,
                    'validation'=>0.05,
                ],
            ],
            'memories'=>$selected,
        ];
    }

    private static function freshnessFactor(string $class,bool $stale): float
    {
        $base=match($class){
            'immutable'=>1.0,
            'stable'=>0.90,
            'dynamic'=>0.75,
            'volatile'=>0.60,
            default=>0.40,
        };
        return $stale?$base*0.45:$base;
    }

    private static function provenanceSummary(array $entries,int $limit): array
    {
        $normalized=[];
        $best=0.0;
        $types=[];

        foreach($entries as $entry){
            if(!is_array($entry)) continue;
            $type=(string)($entry['source_type']??'other');
            $reference=(string)($entry['reference']??'');
            if($reference==='') continue;

            $quality=self::sourceQuality($type);
            $best=max($best,$quality);
            $types[$type]=true;

            if(count($normalized)<$limit){
                $item=[
                    'source_type'=>$type,
                    'reference'=>self::clip($reference,800),
                ];
                if(is_string($entry['captured_at']??null)) $item['captured_at']=$entry['captured_at'];
                if(is_string($entry['content_hash']??null)) $item['content_hash']=$entry['content_hash'];
                $normalized[]=$item;
            }
        }

        if($entries===[]) return [[],0.0];
        $diversity=min(1.0,count($types)/3.0);
        $evidence=min(1.0,count($entries)/5.0);
        $score=min(1.0,(0.75*$best)+(0.15*$diversity)+(0.10*$evidence));
        return [$normalized,$score];
    }

    private static function sourceQuality(string $type): float
    {
        return match($type){
            'user'=>1.00,
            'documentation'=>0.95,
            'api','database','file'=>0.92,
            'memory'=>0.88,
            'observation'=>0.82,
            'web'=>0.78,
            'migration'=>0.72,
            'model'=>0.58,
            'working-test'=>0.55,
            'other'=>0.50,
            default=>0.45,
        };
    }

    private static function answerText(mixed $value): ?string
    {
        if(is_string($value)){
            $value=trim($value);
            return $value===''?null:$value;
        }
        if(is_array($value)||is_object($value)){
            $encoded=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            return is_string($encoded)&&$encoded!==''?$encoded:null;
        }
        if(is_int($value)||is_float($value)||is_bool($value)) return (string)$value;
        return null;
    }

    private static function clip(string $text,int $maxBytes): string
    {
        if(strlen($text)<=$maxBytes) return $text;
        if(function_exists('mb_strcut')) return rtrim(mb_strcut($text,0,$maxBytes,'UTF-8'))."\n[context truncated]";
        return rtrim(substr($text,0,$maxBytes))."\n[context truncated]";
    }
}
