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
            foreach($this->library->listAs($actor) as $entry){
                foreach($entry['logical_refs']??[] as $logicalRef){
                    if(!is_string($logicalRef)||!str_starts_with($logicalRef,'memory://user/')) continue;
                    try{$stored=$this->library->readAs($actor,$logicalRef);}catch(Throwable){continue;}
                    $item=self::canonicalUserMemoryItem($logicalRef,$stored,$subject);
                    if($item!==null) $items[]=$item;
                }
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

    private static function canonicalUserMemoryItem(string $logicalRef,array $stored,string $subject): ?array
    {
        $payload=$stored['payload']??null;
        if(!is_array($payload)) return null;
        $content=$payload['content']??null;
        $metadata=is_array($payload['metadata']??null)?$payload['metadata']:[];

        if(!is_array($content)) return null;

        // Derived thematic summaries are intentionally not canonical memory.
        // They may live under memory://user/... for browsing, but broad recall
        // must resolve back to original user memories/interactions instead.
        if(
            isset($content['thematic_summary_version'])
            || preg_match('#^memory://user/temas/[^/]+/resumenes/#',$logicalRef)===1
        ){
            return null;
        }

        $answer=$content['content']??null;
        if(!is_string($answer)||trim($answer)==='') return null;

        $title=is_string($content['title']??null)?trim((string)$content['title']):'';
        $classification=is_array($content['classification']??null)?$content['classification']:[];
        $isExplicit=isset($content['explicit_memory_version']);
        $isLegacyCanonical=$title!==''||$classification!==[];
        if(!$isExplicit&&!$isLegacyCanonical) return null;

        $retrieval=is_array($content['retrieval']??null)?$content['retrieval']:[];
        $retrievalQuestion=is_string($retrieval['question']??null)?(string)$retrieval['question']:'';
        $categories=is_array($classification['category_path']??null)
            ?implode(' ',array_values(array_filter($classification['category_path'],'is_string')))
            :'';
        $source=is_array($content['source']??null)?$content['source']:[];
        $original=is_string($source['original']??null)?(string)$source['original']:'';

        $searchable=implode("\n",[$logicalRef,$title,$retrievalQuestion,$categories,$answer,$original]);
        if(!self::containsText($searchable,$subject)) return null;

        $confirmed=(string)($metadata['maturity']??'')==='confirmed';
        return [
            'kind'=>'canonical-user-memory',
            'logical_ref'=>$logicalRef,
            'canonical_memory_ref'=>$logicalRef,
            'question'=>$retrievalQuestion!==''?$retrievalQuestion:($title!==''?$title:'Memoria del usuario'),
            'answer'=>$answer,
            'validation_state'=>$confirmed?'verified':'unverified',
            'confidence'=>$confirmed?0.95:0.5,
            'stale'=>false,
            'at'=>(string)($metadata['updated_at']??$metadata['created_at']??''),
            'provenance'=>[[
                'source_type'=>'user',
                'reference'=>$logicalRef,
                'note'=>$isExplicit
                    ?'Canonical actor-visible explicit personal memory'
                    :'Canonical actor-visible legacy personal memory',
            ]],
        ];
    }

    private static function containsText(string $haystack,string $needle): bool
    {
        if(function_exists('mb_stripos')) return mb_stripos($haystack,$needle,0,'UTF-8')!==false;
        return stripos($haystack,$needle)!==false;
    }

    private static function subject(string $question): ?string
    {
        $question=trim($question);
        $patterns=[
            '/^¿?\s*(?:dime\s+)?(?:todo\s+)?(?:lo\s+que\s+)?(?:sabes|sabe|recuerdas|recuerda|tienes\s+(?:guardado|en\s+memoria)|tiene\s+(?:guardado|en\s+memoria))\s+(?:de|sobre)\s+(.+?)\s*[?.!]*$/iu',
            '/^¿?\s*(?:qué|que)\s+(?:es\s+lo\s+que\s+)?(?:sabes|sabe|recuerdas|recuerda|tienes\s+(?:guardado|en\s+memoria)|tiene\s+(?:guardado|en\s+memoria))\s+(?:de|sobre)\s+(.+?)\s*[?.!]*$/iu',
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
        if(($item['kind']??null)==='canonical-user-memory') $score+=40;
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
