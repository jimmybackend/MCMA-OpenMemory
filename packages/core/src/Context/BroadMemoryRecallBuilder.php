<?php
declare(strict_types=1);

namespace MCMA\Core\Context;

use MCMA\Core\Interaction\InteractionArchiveService;
use MCMA\Core\Knowledge\KnowledgeService;
use MCMA\Core\Library;
use Throwable;

final class BroadMemoryRecallBuilder
{
    public function __construct(
        private readonly Library $library,
        private readonly int $maxItems = 8,
        private readonly int $maxBytes = 16000
    ) {
        if($this->maxItems<1||$this->maxItems>32) throw new \RuntimeException('Broad recall max items must be between 1 and 32');
        if($this->maxBytes<1024||$this->maxBytes>131072) throw new \RuntimeException('Broad recall max bytes must be between 1024 and 131072');
    }

    public function byteBudgetUpperBound(): int { return $this->maxBytes; }

    public static function isBroadRecallRequest(string $question): bool
    {
        return self::subject($question)!==null;
    }

    public function build(string $actor,string $question,float $minConfidence=0.75): ?array
    {
        $subject=self::subject($question);
        if($subject===null) return null;

        $items=[];
        $knowledge=new KnowledgeService($this->library);
        try{
            $browse=$knowledge->browse($actor,$subject,null,null,1,100,$minConfidence);
            foreach($browse['items']??[] as $summary){
                if(!is_array($summary)||!is_string($summary['id']??null)) continue;
                try{$detail=$knowledge->inspectId($actor,$summary['id'],$minConfidence);}catch(Throwable){continue;}
                $state=(string)($detail['validation_state']??'unverified');
                if(in_array($state,['retracted','disputed'],true)) continue;
                $answer=$detail['answer']['value']??null;
                $answerText=is_string($answer)?$answer:json_encode($answer,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                if(!is_string($answerText)||trim($answerText)==='') continue;
                $items[]=[
                    'kind'=>'knowledge',
                    'logical_ref'=>(string)$detail['logical_ref'],
                    'question'=>(string)$detail['question'],
                    'answer'=>$answerText,
                    'validation_state'=>$state,
                    'confidence'=>(float)($detail['confidence']??0.0),
                    'stale'=>(bool)($detail['stale']??false),
                    'at'=>(string)($detail['last_validated_at']??$detail['captured_at']??''),
                    'provenance'=>is_array($detail['provenance']??null)?$detail['provenance']:[],
                ];
            }
        }catch(Throwable){}

        try{
            $archive=(new InteractionArchiveService($this->library))->search($actor,$subject,max(12,$this->maxItems*2));
            foreach($archive['interactions']??[] as $interaction){
                if(!is_array($interaction)) continue;
                $validation=is_array($interaction['validation']??null)?$interaction['validation']:[];
                $state=(string)($validation['state']??'unverified');
                if(in_array($state,['retracted','disputed'],true)) continue;
                $answer=$interaction['answer']['value']??null;
                $answerText=is_string($answer)?$answer:json_encode($answer,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                if(!is_string($answerText)||trim($answerText)==='') continue;
                $items[]=[
                    'kind'=>'interaction',
                    'logical_ref'=>(string)($interaction['logical_ref']??''),
                    'question'=>(string)($interaction['question']??''),
                    'answer'=>$answerText,
                    'validation_state'=>$state,
                    'confidence'=>(float)($validation['confidence']??0.5),
                    'stale'=>false,
                    'at'=>(string)($interaction['at']??''),
                    'provenance'=>[['source_type'=>'interaction','reference'=>(string)($interaction['logical_ref']??'')]],
                ];
            }
        }catch(Throwable){}

        if($items===[]) return null;

        $dedup=[];
        foreach($items as $item){
            $key=hash('sha256',self::normalize((string)$item['question'])."\n".self::normalize((string)$item['answer']));
            if(!isset($dedup[$key])||self::quality($item)>self::quality($dedup[$key])) $dedup[$key]=$item;
        }
        $items=array_values($dedup);
        usort($items,static function(array $a,array $b): int {
            $q=self::quality($b)<=>self::quality($a);
            if($q!==0) return $q;
            return (strtotime((string)($b['at']??''))?:0)<=>(strtotime((string)($a['at']??''))?:0);
        });

        $selected=[];$bytes=0;
        foreach($items as $item){
            if(count($selected)>=$this->maxItems) break;
            $encoded=json_encode($item,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $cost=is_string($encoded)?strlen($encoded):0;
            if($cost<1||$bytes+$cost>$this->maxBytes) continue;
            $selected[]=$item;$bytes+=$cost;
        }
        if($selected===[]) return null;

        return [
            'subject'=>$subject,
            'items'=>$selected,
            'selection'=>[
                'strategy'=>'broad-entity-recall',
                'selected_items'=>count($selected),
                'estimated_bytes_upper_bound'=>$bytes,
                'max_items'=>$this->maxItems,
                'byte_budget'=>$this->maxBytes,
            ],
        ];
    }

    private static function subject(string $question): ?string
    {
        $question=trim($question);
        $patterns=[
            '/^(?:dime\s+)?(?:todo\s+)?(?:lo\s+que\s+)?(?:sabes|recuerdas|tienes\s+(?:guardado|en\s+memoria))\s+(?:de|sobre)\s+(.+?)\s*[?.!]*$/iu',
            '/^(?:qué|que)\s+(?:es\s+lo\s+que\s+)?(?:sabes|recuerdas|tienes\s+(?:guardado|en\s+memoria))\s+(?:de|sobre)\s+(.+?)\s*[?.!]*$/iu',
            '/^(?:tell\s+me\s+)?(?:everything\s+)?(?:you\s+)?(?:know|remember)\s+about\s+(.+?)\s*[?.!]*$/iu',
            '/^what\s+do\s+you\s+(?:know|remember)\s+about\s+(.+?)\s*[?.!]*$/iu',
        ];
        foreach($patterns as $pattern){
            if(preg_match($pattern,$question,$m)!==1) continue;
            $subject=trim((string)$m[1]," \t\n\r\0\x0B\"'¿?¡!.,:;");
            if(strlen($subject)>=2&&strlen($subject)<=200) return $subject;
        }
        return null;
    }

    private static function quality(array $item): int
    {
        $state=(string)($item['validation_state']??'unverified');
        $score=match($state){'verified'=>400,'supported'=>300,'unverified'=>100,default=>0};
        if(($item['kind']??null)==='knowledge') $score+=20;
        if(($item['stale']??false)===true) $score-=80;
        $score+=(int)round(max(0.0,min(1.0,(float)($item['confidence']??0.0)))*50);
        return $score;
    }

    private static function normalize(string $value): string
    {
        $value=preg_replace('/\s+/u',' ',trim($value))??trim($value);
        return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);
    }
}
