<?php
declare(strict_types=1);

namespace MCMA\Core\Interaction;

use MCMA\Core\Library;
use RuntimeException;
use Throwable;

final class ThematicSummaryService
{
    public const VERSION='1.0';

    public function __construct(private readonly Library $library) {}

    /**
     * Build/update portable thematic summaries from one canonical interaction.
     * This service is deterministic and never calls an AI provider.
     */
    public function sync(
        string $actor,
        string $interactionRef,
        array $interaction,
        ?string $sourceStorageHash=null
    ): array {
        self::assertInteractionRef($interactionRef);

        $interactionId=(string)($interaction['interaction_id']??'');
        $conversationId=(string)($interaction['conversation_id']??'');
        if(!preg_match('/^req_[0-9a-f]{32}$/',$interactionId)) throw new RuntimeException('Invalid thematic summary interaction id');
        if(!preg_match('/^conv_[0-9a-f]{32}$/',$conversationId)) throw new RuntimeException('Invalid thematic summary conversation id');

        [$topics,$source]=$this->topics($actor,$interaction);
        if($topics===[]){
            return [
                'ok'=>true,
                'relevant'=>false,
                'reason'=>'no-deterministic-topic-signal',
                'summaries'=>[],
                'usage'=>self::zeroUsage(),
                'provider_usage'=>[],
                'ai_tokens_used'=>0,
                'credit_units_charged'=>0,
            ];
        }

        $desired=[];
        foreach($topics as $topic){
            $slug=self::slugify($topic);
            $desired['memory://user/temas/'.$slug.'/resumenes/'.$interactionId]=[
                'topic'=>$topic,
                'topic_slug'=>$slug,
            ];
        }

        $results=[];
        foreach($desired as $logicalRef=>$topicInfo){
            $payload=$this->payload(
                $interactionRef,
                $interaction,
                $topicInfo['topic'],
                $topicInfo['topic_slug'],
                $source,
                $sourceStorageHash
            );
            $results[]=$this->persist($actor,$logicalRef,$payload);
        }

        // If a later, better classification changes the thematic paths, keep
        // the old derived objects as versioned history but make them unusable
        // for future conclusions instead of silently deleting them.
        $desiredRefs=array_keys($desired);
        foreach($this->existingRefs($actor,$interactionId) as $existingRef){
            if(in_array($existingRef,$desiredRefs,true)) continue;
            try{
                $stored=$this->library->readAs($actor,$existingRef);
                $content=$stored['payload']['content']??null;
                if(!is_array($content)||($content['thematic_summary_version']??null)!==self::VERSION) continue;
                $content['status']='superseded';
                $content['superseded_by']=$desiredRefs;
                $validation=is_array($content['validation']??null)?$content['validation']:[];
                $validation['trusted_for_conclusions']=false;
                $content['validation']=$validation;
                $results[]=$this->persist($actor,$existingRef,$content);
            }catch(Throwable){
                continue;
            }
        }

        return [
            'ok'=>true,
            'relevant'=>true,
            'classification_source'=>$source,
            'topics'=>$topics,
            'summaries'=>$results,
            'usage'=>self::zeroUsage(),
            'provider_usage'=>[],
            'ai_tokens_used'=>0,
            'credit_units_charged'=>0,
        ];
    }

    private function topics(string $actor,array $interaction): array
    {
        $catalog=is_array($interaction['catalog']??null)?$interaction['catalog']:[];

        $topics=self::labels($catalog['topics']??[]);
        if($topics!==[]) return [$topics,'catalog.topics'];

        $fallback=[];
        foreach(self::labels($catalog['projects']??[]) as $label) $fallback[]=$label;
        foreach(self::labels($catalog['entities']??[]) as $label) $fallback[]=$label;

        $canonicalRef=$interaction['canonical_memory_ref']??null;
        if(is_string($canonicalRef)&&str_starts_with($canonicalRef,'memory://user/')){
            try{
                $stored=$this->library->readAs($actor,$canonicalRef);
                $content=$stored['payload']['content']??null;
                if(is_array($content)){
                    $classification=is_array($content['classification']??null)?$content['classification']:[];
                    foreach(self::labels($classification['category_path']??[]) as $label){
                        if(!self::genericCategory($label)) $fallback[]=$label;
                    }
                }
            }catch(Throwable){
                // Actor-aware canonical-memory fallback is optional. Failure to
                // read it must not bypass permissions or block archiving.
            }
        }

        $fallback=self::uniqueLabels($fallback,6);
        return [$fallback,$fallback===[]?'none':'deterministic-fallback'];
    }

    private function payload(
        string $interactionRef,
        array $interaction,
        string $topic,
        string $topicSlug,
        string $classificationSource,
        ?string $sourceStorageHash
    ): array {
        $validation=is_array($interaction['validation']??null)?$interaction['validation']:[];
        $state=(string)($validation['state']??'unverified');
        $confidence=(float)($validation['confidence']??0.5);
        $catalog=is_array($interaction['catalog']??null)?$interaction['catalog']:[];
        $relations=array_values(array_filter(
            array_unique(array_merge(
                [$interactionRef],
                is_array($interaction['relations']??null)?$interaction['relations']:[]
            )),
            static fn($value): bool => is_string($value)&&str_starts_with($value,'memory://')
        ));

        return [
            'thematic_summary_version'=>self::VERSION,
            'status'=>'active',
            'topic'=>$topic,
            'topic_slug'=>$topicSlug,
            'title'=>self::title($catalog,$interaction,$topic),
            'summary'=>self::summaryText($interaction,$topic),
            'interaction_ref'=>$interactionRef,
            'conversation_id'=>(string)$interaction['conversation_id'],
            'interaction_id'=>(string)$interaction['interaction_id'],
            'request_id'=>(string)$interaction['interaction_id'],
            'at'=>(string)($interaction['at']??''),
            'validation'=>[
                'state'=>$state,
                'confidence'=>$confidence,
                'last_validated_at'=>$validation['last_validated_at']??null,
                'trusted_for_conclusions'=>$state==='verified'&&$confidence>=0.75,
            ],
            'catalog'=>[
                'projects'=>self::labels($catalog['projects']??[]),
                'entities'=>self::labels($catalog['entities']??[]),
            ],
            'classification'=>[
                'source'=>$classificationSource,
            ],
            'relations'=>$relations,
            'provenance'=>[[
                'source_type'=>'interaction',
                'reference'=>$interactionRef,
                'note'=>'Derived thematic summary; canonical question/answer remain only in the interaction archive',
            ]],
            'source_storage_hash'=>$sourceStorageHash,
        ];
    }

    private function persist(string $actor,string $logicalRef,array $payload): array
    {
        $existing=null;
        try{
            $existing=$this->library->readAs($actor,$logicalRef);
        }catch(Throwable $e){
            if(!str_contains($e->getMessage(),'Memory not found:')) throw $e;
        }

        $maturity=(($payload['validation']['trusted_for_conclusions']??false)===true)?'confirmed':'observed';

        if($existing===null){
            $stored=$this->library->writeAs(
                $actor,$logicalRef,$payload,'json','warm','40-semantic','user',$maturity
            );
            return [
                'logical_ref'=>$logicalRef,
                'action'=>'created',
                'object_id'=>$stored['object_id'],
                'storage_hash'=>$stored['storage_hash'],
                'revision'=>(int)($stored['revision']??1),
                'previous_storage_hash'=>$stored['previous_storage_hash']??null,
            ];
        }

        $current=$existing['payload']['content']??null;
        if(is_array($current)&&self::samePayload($current,$payload)){
            return [
                'logical_ref'=>$logicalRef,
                'action'=>'unchanged',
                'object_id'=>$existing['object_id'],
                'storage_hash'=>$existing['storage_hash'],
                'revision'=>(int)($existing['payload']['metadata']['revision']??1),
                'previous_storage_hash'=>$existing['payload']['metadata']['previous_storage_hash']??null,
            ];
        }

        $stored=$this->library->updateAs(
            $actor,$logicalRef,$payload,'json','warm','40-semantic','user',$maturity
        );
        return [
            'logical_ref'=>$logicalRef,
            'action'=>'updated',
            'object_id'=>$stored['object_id'],
            'storage_hash'=>$stored['storage_hash'],
            'revision'=>(int)($stored['revision']??1),
            'previous_storage_hash'=>$stored['previous_storage_hash']??null,
        ];
    }

    private function existingRefs(string $actor,string $interactionId): array
    {
        $suffix='/resumenes/'.$interactionId;
        $refs=[];
        foreach($this->library->listAs($actor) as $entry){
            foreach($entry['logical_refs']??[] as $ref){
                if(
                    is_string($ref)
                    && str_starts_with($ref,'memory://user/temas/')
                    && str_ends_with($ref,$suffix)
                ){
                    $refs[]=$ref;
                }
            }
        }
        sort($refs,SORT_STRING);
        return array_values(array_unique($refs));
    }

    private static function summaryText(array $interaction,string $topic): string
    {
        $question=self::excerpt((string)($interaction['question']??''),180);
        $answer=$interaction['answer']['value']??null;
        $answerText=is_string($answer)?$answer:(json_encode($answer,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'');
        $answerText=self::excerpt($answerText,260);

        $parts=['Tema: '.$topic.'.'];
        if($question!=='') $parts[]='Se trató: '.$question;
        if($answerText!=='') $parts[]='Resultado resumido: '.$answerText;
        return self::excerpt(implode(' ',$parts),520);
    }

    private static function title(array $catalog,array $interaction,string $topic): string
    {
        $title=self::clean((string)($catalog['title']??''),120);
        if($title!=='') return $title;
        $question=self::clean((string)($interaction['question']??''),100);
        return $question!==''?$question:'Resumen sobre '.$topic;
    }

    private static function excerpt(string $value,int $max): string
    {
        $value=self::clean($value,$max);
        if($value==='') return '';
        $sentences=preg_split('/(?<=[.!?])\s+/u',$value,2);
        $first=trim((string)($sentences[0]??$value));
        return $first!==''?$first:$value;
    }

    private static function labels(mixed $value): array
    {
        if(!is_array($value)) return [];
        $out=[];
        foreach($value as $item){
            if(!is_string($item)) continue;
            $item=self::clean($item,80);
            if($item!==''&&!in_array($item,$out,true)) $out[]=$item;
            if(count($out)>=8) break;
        }
        return $out;
    }

    private static function uniqueLabels(array $labels,int $limit): array
    {
        $out=[];
        foreach($labels as $label){
            $label=self::clean((string)$label,80);
            if($label!==''&&!in_array($label,$out,true)) $out[]=$label;
            if(count($out)>=$limit) break;
        }
        return $out;
    }

    private static function genericCategory(string $label): bool
    {
        $slug=self::slugify($label);
        return in_array($slug,[
            'user','memoria','memory','knowledge','conocimiento','temas','topics',
            'proyectos','projects','configuraciones','configurations'
        ],true);
    }

    private static function slugify(string $value): string
    {
        $original=trim($value);
        $value=$original;
        if(function_exists('iconv')){
            $ascii=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);
            if(is_string($ascii)&&$ascii!=='') $value=$ascii;
        }
        $value=strtolower($value);
        $value=preg_replace('/[^a-z0-9]+/','-',$value)??'';
        $value=trim($value,'-');
        if($value==='') $value='tema-'.substr(hash('sha256',$original),0,12);
        return substr($value,0,72);
    }

    private static function clean(string $value,int $max): string
    {
        $value=preg_replace('/[\x00-\x1F\x7F]+/u',' ',trim($value))??trim($value);
        $value=preg_replace('/\s+/u',' ',$value)??$value;
        if(strlen($value)<=$max) return $value;
        if(function_exists('mb_strcut')) return rtrim(mb_strcut($value,0,$max,'UTF-8'));
        return rtrim(substr($value,0,$max));
    }

    private static function samePayload(array $a,array $b): bool
    {
        return json_encode($a,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
            ===json_encode($b,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    private static function zeroUsage(): array
    {
        return [
            'input_tokens'=>0,
            'output_tokens'=>0,
            'cached_tokens'=>0,
            'embedding_tokens'=>0,
            'total_tokens'=>0,
            'model_calls'=>0,
            'duration_ms'=>0,
        ];
    }

    private static function assertInteractionRef(string $logicalRef): void
    {
        if(!preg_match('#^memory://interactions/[0-9]{4}/[0-9]{2}/[0-9]{2}/conv_[0-9a-f]{32}/req_[0-9a-f]{32}$#',$logicalRef)){
            throw new RuntimeException('Invalid thematic summary interaction reference');
        }
    }
}
