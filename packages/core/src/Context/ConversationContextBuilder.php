<?php
declare(strict_types=1);

namespace MCMA\Core\Context;

use MCMA\Core\Interaction\InteractionArchiveService;
use MCMA\Core\Library;
use RuntimeException;

final class ConversationContextBuilder
{
    public function __construct(
        private readonly Library $library,
        private readonly int $tokenBudget = 6000,
        private readonly int $maxTurns = 6,
        private readonly int $candidateLimit = 12,
        private readonly float $minRelevance = 0.08,
        private readonly int $recentAnchors = 2
    ) {
        if($this->tokenBudget<256||$this->tokenBudget>65536){
            throw new RuntimeException('Conversation context token budget must be between 256 and 65536');
        }
        if($this->maxTurns<1||$this->maxTurns>16){
            throw new RuntimeException('Conversation context max turns must be between 1 and 16');
        }
        if($this->candidateLimit<1||$this->candidateLimit>32){
            throw new RuntimeException('Conversation context candidate limit must be between 1 and 32');
        }
        if($this->candidateLimit<$this->maxTurns){
            throw new RuntimeException('Conversation context candidate limit must be >= max turns');
        }
        if(!is_finite($this->minRelevance)||$this->minRelevance<0.0||$this->minRelevance>1.0){
            throw new RuntimeException('Conversation context minimum relevance must be between 0 and 1');
        }
        if($this->recentAnchors<0||$this->recentAnchors>$this->maxTurns){
            throw new RuntimeException('Conversation context recent anchors must be between 0 and max turns');
        }
    }

    public function tokenBudgetUpperBound(): int
    {
        return $this->tokenBudget;
    }

    public function build(string $actor,string $conversationId,string $question): ?array
    {
        $question=trim($question);
        if($question==='') return null;

        $archive=new InteractionArchiveService($this->library);
        $source=$archive->contextCandidates($actor,$conversationId,$this->candidateLimit);
        $candidates=is_array($source['candidates']??null)?$source['candidates']:[];
        if($candidates===[]) return null;

        $queryTerms=self::terms($question);
        $eligible=[];

        foreach($candidates as $rank=>$candidate){
            if(!is_array($candidate)) continue;

            $state=(string)($candidate['validation']['state']??'unverified');
            if(in_array($state,['retracted','disputed'],true)) continue;

            $candidateQuestion=self::clip((string)($candidate['question']??''),1500);
            $answer=self::answerText($candidate['answer']['value']??null);
            if($answer===null) continue;
            $answer=self::clip($answer,3500);
            if(trim($candidateQuestion)===''&&trim($answer)==='') continue;

            $lexical=self::lexicalRelevance(
                $queryTerms,
                self::terms($candidateQuestion.' '.$answer)
            );
            $anchor=$rank<$this->recentAnchors;
            if(!$anchor&&$lexical<$this->minRelevance) continue;

            $recency=1.0/(1.0+$rank);
            $score=min(1.0,(0.75*$lexical)+(0.25*$recency)+($anchor?0.15:0.0));

            $eligible[]=[
                'logical_ref'=>(string)($candidate['logical_ref']??''),
                'at'=>(string)($candidate['at']??''),
                'question'=>$candidateQuestion,
                'answer'=>$answer,
                'route'=>(string)($candidate['route']??'unknown'),
                'validation_state'=>$state,
                'confidence'=>(float)($candidate['validation']['confidence']??0.5),
                'relevance_score'=>round($lexical,6),
                'selection_score'=>round($score,6),
                'selection_reason'=>$anchor?'recent-anchor':'relevance',
                '_rank'=>$rank,
            ];
        }

        if($eligible===[]) return null;

        usort($eligible,static function(array $a,array $b): int {
            $score=((float)$b['selection_score'])<=>((float)$a['selection_score']);
            if($score!==0) return $score;
            return ((int)$a['_rank'])<=>((int)$b['_rank']);
        });

        $selected=[];
        $used=0;
        foreach($eligible as $turn){
            if(count($selected)>=$this->maxTurns) break;
            unset($turn['_rank']);
            $encoded=json_encode($turn,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if(!is_string($encoded)) continue;

            // MCMA uses bytes as a conservative upper bound when a provider
            // tokenizer is unavailable. This guarantees the selection never
            // exceeds the configured context budget under that same policy.
            $cost=max(1,strlen($encoded));
            if($used+$cost>$this->tokenBudget) continue;
            $turn['estimated_tokens_upper_bound']=$cost;
            $used+=$cost;
            $selected[]=$turn;
        }

        if($selected===[]) return null;

        usort($selected,static function(array $a,array $b): int {
            $time=(strtotime((string)$a['at'])?:0)<=>(strtotime((string)$b['at'])?:0);
            if($time!==0) return $time;
            return (string)$a['logical_ref']<=>(string)$b['logical_ref'];
        });

        return [
            'conversation_id'=>$conversationId,
            'selection'=>[
                'strategy'=>'recent-plus-lexical-v1',
                'candidate_limit'=>$this->candidateLimit,
                'candidates_considered'=>count($candidates),
                'eligible_candidates'=>count($eligible),
                'selected_turns'=>count($selected),
                'max_turns'=>$this->maxTurns,
                'recent_anchors'=>$this->recentAnchors,
                'min_relevance'=>$this->minRelevance,
                'token_budget'=>$this->tokenBudget,
                'estimated_tokens_upper_bound'=>$used,
                'token_estimate_method'=>'estimated-bytes-upper-bound',
            ],
            'turns'=>$selected,
        ];
    }

    private static function lexicalRelevance(array $queryTerms,array $candidateTerms): float
    {
        if($queryTerms===[]||$candidateTerms===[]) return 0.0;
        $candidate=array_fill_keys($candidateTerms,true);
        $matches=0;
        foreach($queryTerms as $term){
            if(isset($candidate[$term])) $matches++;
        }
        return min(1.0,$matches/max(1,count($queryTerms)));
    }

    private static function terms(string $text): array
    {
        $text=function_exists('mb_strtolower')?mb_strtolower($text,'UTF-8'):strtolower($text);
        $parts=preg_split('/[^\p{L}\p{N}._-]+/u',$text,-1,PREG_SPLIT_NO_EMPTY);
        if(!is_array($parts)) return [];

        $stop=array_fill_keys([
            'a','al','algo','and','are','as','at','con','como','de','del','el','ella','en','es','esta','este',
            'for','from','hay','i','is','it','la','las','lo','los','me','mi','my','of','o','para','por','que',
            'se','si','sin','su','the','to','un','una','unos','unas','what','with','y','ya','you'
        ],true);

        $terms=[];
        foreach($parts as $term){
            $term=trim((string)$term);
            if($term===''||isset($stop[$term])) continue;
            if(strlen($term)<2) continue;
            $terms[$term]=true;
            if(count($terms)>=64) break;
        }
        return array_keys($terms);
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
