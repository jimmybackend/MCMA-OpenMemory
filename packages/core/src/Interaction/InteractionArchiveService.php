<?php
declare(strict_types=1);

namespace MCMA\Core\Interaction;

use MCMA\Core\Knowledge\KnowledgeRecord;
use MCMA\Core\Knowledge\KnowledgeService;
use MCMA\Core\Library;
use MCMA\Core\Semantic\EmbeddingProvider;
use MCMA\Core\Semantic\SemanticIndexService;
use RuntimeException;
use Throwable;

final class InteractionArchiveService
{
    public const VERSION='1.0';
    private const CONVERSATION_INDEX_REF='memory://system/conversation-index';
    private const CONVERSATION_INDEX_VERSION='1.1';

    public function __construct(private readonly Library $library) {}

    public static function normalizeConversationId(?string $value): string
    {
        $value=trim((string)$value);
        if(preg_match('/^conv_[0-9a-f]{32}$/',$value)) return $value;
        return 'conv_'.bin2hex(random_bytes(16));
    }

    public function archive(
        string $actor,
        string $requestId,
        string $conversationId,
        string $question,
        array $result,
        string $origin='web'
    ): array {
        if(!preg_match('/^req_[0-9a-f]{32}$/',$requestId)) throw new RuntimeException('Invalid interaction request id');
        if(!preg_match('/^conv_[0-9a-f]{32}$/',$conversationId)) throw new RuntimeException('Invalid conversation id');

        $at=gmdate('Y-m-d\TH:i:s\Z');
        $date=substr($at,0,10);
        [$year,$month,$day]=explode('-',$date);
        $logicalRef=sprintf('memory://interactions/%s/%s/%s/%s/%s',$year,$month,$day,$conversationId,$requestId);

        $answer=$result['answer']??null;
        $answerFormat='text';
        $answerValue=null;
        if(is_array($answer)&&array_key_exists('value',$answer)){
            $answerValue=$answer['value'];
            $answerFormat=is_string($answer['format']??null)?(string)$answer['format']:'text';
        }

        $knowledgeRef=self::knowledgeRefFromResult($result);
        $canonicalRef=self::canonicalRefFromResult($result);
        $catalog=self::initialCatalog($question,$answerValue,$result);

        $interaction=[
            'interaction_version'=>self::VERSION,
            'interaction_id'=>$requestId,
            'conversation_id'=>$conversationId,
            'origin'=>$origin,
            'at'=>$at,
            'question'=>$question,
            'answer'=>[
                'format'=>$answerFormat,
                'value'=>$answerValue,
            ],
            'route'=>(string)($result['route']??'unknown'),
            'provider'=>[
                'called'=>(bool)($result['provider_called']??false),
                'id'=>isset($result['provider_id'])?(string)$result['provider_id']:null,
            ],
            'relations'=>array_values(array_filter(array_unique([
                $canonicalRef,
                $knowledgeRef,
            ]),static fn($v)=>is_string($v)&&$v!=='')),
            'canonical_memory_ref'=>$canonicalRef,
            'knowledge_ref'=>$knowledgeRef,
            'stored'=>(bool)($result['stored']??false),
            'validation'=>[
                'state'=>'unverified',
                'confidence'=>0.5,
                'last_validated_at'=>null,
                'history'=>[[
                    'at'=>$at,
                    'state'=>'unverified',
                    'confidence'=>0.5,
                    'reason'=>'interaction-archived',
                ]],
            ],
            'catalog'=>$catalog,
            'billing'=>self::billingSummary($result['billing']??null),
        ];

        $stored=$this->library->writeAs(
            $actor,$logicalRef,$interaction,'json','hot','30-episodic','session','observed'
        );

        try{
            $this->upsertConversationIndex($actor,$logicalRef,$interaction);
        }catch(Throwable $e){
            error_log('MCMA conversation index update error: '.$e->getMessage());
        }

        return [
            'logical_ref'=>$logicalRef,
            'object_id'=>$stored['object_id'],
            'storage_hash'=>$stored['storage_hash'],
            'conversation_id'=>$conversationId,
            'interaction_id'=>$requestId,
            'at'=>$at,
            'validation_state'=>'unverified',
            'ai_tokens_used'=>0,
            'credit_units_charged'=>0,
        ];
    }

    public function read(string $actor,string $logicalRef): array
    {
        self::assertInteractionRef($logicalRef);
        $stored=$this->library->readAs($actor,$logicalRef);
        $content=$stored['payload']['content']??null;
        if(!is_array($content)||($content['interaction_version']??null)!==self::VERSION){
            throw new RuntimeException('Stored interaction is malformed');
        }
        return [
            'logical_ref'=>$logicalRef,
            'object_id'=>$stored['object_id'],
            'storage_hash'=>$stored['storage_hash'],
            'metadata'=>$stored['payload']['metadata']??[],
            'interaction'=>$content,
            'ai_tokens_used'=>0,
            'credit_units_charged'=>0,
        ];
    }

    public function conversations(string $actor): array
    {
        $index=$this->conversationIndex($actor);
        $items=[];
        foreach($index['conversations']??[] as $conversation){
            if(!is_array($conversation)) continue;
            $items[]=self::conversationSummary($conversation);
        }

        usort($items,static fn(array $a,array $b): int =>
            (strtotime((string)($b['last_at']??''))?:0)<=>(strtotime((string)($a['last_at']??''))?:0)
        );

        return [
            'conversations'=>$items,
            'total'=>count($items),
            'ai_tokens_used'=>0,
            'credit_units_charged'=>0,
        ];
    }

    public function conversation(string $actor,string $conversationId): array
    {
        if(!preg_match('/^conv_[0-9a-f]{32}$/',$conversationId)){
            throw new RuntimeException('Invalid conversation id');
        }

        $index=$this->conversationIndex($actor);
        $conversation=$index['conversations'][$conversationId]??null;
        if(!is_array($conversation)){
            throw new RuntimeException('Conversation not found: '.$conversationId);
        }

        $interactions=[];
        foreach($conversation['interaction_refs']??[] as $logicalRef){
            if(!is_string($logicalRef)) continue;
            $detail=$this->read($actor,$logicalRef);
            $interaction=$detail['interaction'];
            $interactions[]=[
                'logical_ref'=>$logicalRef,
                'interaction_id'=>(string)($interaction['interaction_id']??''),
                'at'=>(string)($interaction['at']??''),
                'question'=>(string)($interaction['question']??''),
                'answer'=>is_array($interaction['answer']??null)?$interaction['answer']:['format'=>'text','value'=>null],
                'route'=>(string)($interaction['route']??'unknown'),
                'provider'=>is_array($interaction['provider']??null)?$interaction['provider']:['called'=>false,'id'=>null],
                'validation'=>is_array($interaction['validation']??null)?$interaction['validation']:[],
                'catalog'=>is_array($interaction['catalog']??null)?$interaction['catalog']:[],
                'billing'=>is_array($interaction['billing']??null)?$interaction['billing']:null,
            ];
        }

        usort($interactions,static fn(array $a,array $b): int =>
            (strtotime((string)$a['at'])?:0)<=>(strtotime((string)$b['at'])?:0)
        );

        return [
            'conversation'=>self::conversationSummary($conversation),
            'interactions'=>$interactions,
            'ai_tokens_used'=>0,
            'credit_units_charged'=>0,
        ];
    }

    public function search(string $actor,string $query,int $limit=20): array
    {
        $query=trim($query);
        if($query===''||strlen($query)>256) throw new RuntimeException('Interaction search query must be between 1 and 256 bytes');
        if($limit<1||$limit>100) throw new RuntimeException('Interaction search limit must be between 1 and 100');

        $matches=[];
        foreach($this->library->listAs($actor) as $entry){
            foreach($entry['logical_refs']??[] as $logicalRef){
                if(!is_string($logicalRef)||!preg_match('#^memory://interactions/[0-9]{4}/[0-9]{2}/[0-9]{2}/conv_[0-9a-f]{32}/req_[0-9a-f]{32}$#',$logicalRef)) continue;
                try{$detail=$this->read($actor,$logicalRef);}catch(Throwable){continue;}
                $interaction=$detail['interaction'];
                $validation=is_array($interaction['validation']??null)?$interaction['validation']:[];
                if(in_array((string)($validation['state']??''),['retracted','disputed'],true)) continue;

                $parts=[(string)($interaction['question']??'')];
                $answer=$interaction['answer']['value']??null;
                $parts[]=is_string($answer)?$answer:(json_encode($answer,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'');
                $catalog=is_array($interaction['catalog']??null)?$interaction['catalog']:[];
                foreach(['title','topics','projects','people','characters','entities','sources'] as $field){
                    $value=$catalog[$field]??null;
                    if(is_string($value)) $parts[]=$value;
                    elseif(is_array($value)) $parts[]=implode(' ',array_values(array_filter($value,'is_string')));
                }
                if(!self::containsText(implode("\n",$parts),$query)) continue;

                $matches[]=[
                    'logical_ref'=>$logicalRef,
                    'interaction_id'=>(string)($interaction['interaction_id']??''),
                    'conversation_id'=>(string)($interaction['conversation_id']??''),
                    'at'=>(string)($interaction['at']??''),
                    'question'=>(string)($interaction['question']??''),
                    'answer'=>is_array($interaction['answer']??null)?$interaction['answer']:['format'=>'text','value'=>null],
                    'route'=>(string)($interaction['route']??'unknown'),
                    'validation'=>$validation,
                    'catalog'=>$catalog,
                    'billing'=>is_array($interaction['billing']??null)?$interaction['billing']:null,
                ];
            }
        }

        usort($matches,static fn(array $a,array $b):int=>
            (strtotime((string)($b['at']??''))?:0)<=>(strtotime((string)($a['at']??''))?:0)
        );
        return [
            'query'=>$query,
            'interactions'=>array_slice($matches,0,$limit),
            'total_matches'=>count($matches),
            'ai_tokens_used'=>0,
            'credit_units_charged'=>0,
        ];
    }

    public function interactionByRequestId(string $actor,string $conversationId,string $requestId): ?array
    {
        if(!preg_match('/^conv_[0-9a-f]{32}$/',$conversationId)||!preg_match('/^req_[0-9a-f]{32}$/',$requestId)){
            throw new RuntimeException('Invalid conversation/request id');
        }
        $index=$this->conversationIndex($actor);
        $conversation=$index['conversations'][$conversationId]??null;
        if(!is_array($conversation)) return null;
        foreach($conversation['interaction_refs']??[] as $logicalRef){
            if(!is_string($logicalRef)||!str_ends_with($logicalRef,'/'.$requestId)) continue;
            $detail=$this->read($actor,$logicalRef);
            $interaction=$detail['interaction'];
            return [
                'logical_ref'=>$logicalRef,
                'interaction_id'=>(string)($interaction['interaction_id']??''),
                'conversation_id'=>(string)($interaction['conversation_id']??''),
                'at'=>(string)($interaction['at']??''),
                'question'=>(string)($interaction['question']??''),
                'answer'=>is_array($interaction['answer']??null)?$interaction['answer']:['format'=>'text','value'=>null],
                'route'=>(string)($interaction['route']??'unknown'),
                'provider'=>is_array($interaction['provider']??null)?$interaction['provider']:['called'=>false,'id'=>null],
                'validation'=>is_array($interaction['validation']??null)?$interaction['validation']:[],
                'catalog'=>is_array($interaction['catalog']??null)?$interaction['catalog']:[],
                'billing'=>is_array($interaction['billing']??null)?$interaction['billing']:null,
                'stored'=>(bool)($interaction['stored']??false),
                'canonical_memory_ref'=>$interaction['canonical_memory_ref']??null,
                'knowledge_ref'=>$interaction['knowledge_ref']??null,
            ];
        }
        return null;
    }

    public function contextCandidates(string $actor,string $conversationId,int $limit=12): array
    {
        if(!preg_match('/^conv_[0-9a-f]{32}$/',$conversationId)){
            throw new RuntimeException('Invalid conversation id');
        }
        if($limit<1||$limit>32) throw new RuntimeException('Conversation context candidate limit must be between 1 and 32');

        // The encrypted conversation index is trusted derived infrastructure and
        // remains owner-only. It is used only to discover recent canonical refs.
        // Every returned interaction is still read through the requesting actor,
        // so the index can never bypass resource permissions.
        $index=$this->conversationIndex('owner');
        $conversation=$index['conversations'][$conversationId]??null;
        if(!is_array($conversation)){
            return [
                'conversation_id'=>$conversationId,
                'candidates'=>[],
                'candidate_count'=>0,
            ];
        }

        $recent=is_array($conversation['recent_interactions']??null)
            ?array_values($conversation['recent_interactions'])
            :[];
        usort($recent,static function(array $a,array $b): int {
            $timeCompare=(strtotime((string)($b['at']??''))?:0)<=>(strtotime((string)($a['at']??''))?:0);
            if($timeCompare!==0) return $timeCompare;
            return (string)($b['ref']??'')<=>(string)($a['ref']??'');
        });

        $candidates=[];
        foreach($recent as $item){
            if(count($candidates)>=$limit) break;
            if(!is_array($item)) continue;
            $logicalRef=$item['ref']??null;
            if(!is_string($logicalRef)) continue;
            try{
                $detail=$this->read($actor,$logicalRef);
            }catch(Throwable){
                continue;
            }
            $interaction=$detail['interaction'];
            if((string)($interaction['conversation_id']??'')!==$conversationId) continue;
            $candidates[]=[
                'logical_ref'=>$logicalRef,
                'interaction_id'=>(string)($interaction['interaction_id']??''),
                'at'=>(string)($interaction['at']??''),
                'question'=>(string)($interaction['question']??''),
                'answer'=>is_array($interaction['answer']??null)?$interaction['answer']:['format'=>'text','value'=>null],
                'route'=>(string)($interaction['route']??'unknown'),
                'validation'=>is_array($interaction['validation']??null)?$interaction['validation']:[],
            ];
        }

        return [
            'conversation_id'=>$conversationId,
            'candidates'=>$candidates,
            'candidate_count'=>count($candidates),
        ];
    }

    public function validate(
        string $actor,
        string $logicalRef,
        string $action,
        ?InteractionCatalogService $catalogService=null,
        ?EmbeddingProvider $embeddingProvider=null
    ): array {
        if(!in_array($action,['approve','discard'],true)) throw new RuntimeException('Invalid interaction validation action');

        $detail=$this->read($actor,$logicalRef);
        $interaction=$detail['interaction'];
        $now=gmdate('Y-m-d\TH:i:s\Z');
        $target=$action==='approve'?'verified':'retracted';
        $confidence=$action==='approve'?0.95:0.0;

        $classificationUsage=null;
        if($action==='approve'&&$catalogService!==null){
            $cataloged=$catalogService->classify(
                (string)$interaction['question'],
                $interaction['answer']['value']??null,
                is_array($interaction['catalog']??null)?$interaction['catalog']:[]
            );
            $interaction['catalog']=$cataloged['catalog'];
            $classificationUsage=$cataloged['usage']??null;
        }

        $knowledgeSync=null;
        if($action==='approve'){
            $knowledgeSync=$this->approveKnowledge($actor,$logicalRef,$interaction,$embeddingProvider);
            if(is_string($knowledgeSync['logical_ref']??null)) $interaction['knowledge_ref']=$knowledgeSync['logical_ref'];
        }elseif(($interaction['route']??null)!=='memory-capture'){
            // Retraction is enforced by KnowledgeRecord validation gates; no
            // embedding refresh is required, so discard remains zero-AI.
            $knowledgeSync=$this->retractRelatedKnowledge($actor,$interaction,null);
        }

        $interaction['validation']['state']=$target;
        $interaction['validation']['confidence']=$confidence;
        $interaction['validation']['last_validated_at']=$now;
        $history=is_array($interaction['validation']['history']??null)?$interaction['validation']['history']:[];
        $history[]=[
            'at'=>$now,
            'state'=>$target,
            'confidence'=>$confidence,
            'reason'=>$action==='approve'?'owner-approved-interaction':'owner-discarded-interaction',
        ];
        $interaction['validation']['history']=$history;

        $stored=$this->library->updateAs(
            $actor,$logicalRef,$interaction,'json',
            $action==='approve'?'warm':'cold',
            '30-episodic','session',
            $action==='approve'?'confirmed':'observed'
        );

        try{
            $this->upsertConversationIndex($actor,$logicalRef,$interaction);
        }catch(Throwable $e){
            error_log('MCMA conversation index refresh error: '.$e->getMessage());
        }

        return [
            'logical_ref'=>$logicalRef,
            'object_id'=>$stored['object_id'],
            'storage_hash'=>$stored['storage_hash'],
            'validation_state'=>$target,
            'confidence'=>$confidence,
            'catalog'=>$interaction['catalog']??[],
            'knowledge_sync'=>$knowledgeSync,
            'classification_usage'=>$classificationUsage,
        ];
    }

    public function libraryTree(string $actor): array
    {
        $tree=[
            'Memoria personal'=>self::convertCanonicalTree(
                is_array(($this->library->treeAs($actor)['user']??null))?$this->library->treeAs($actor)['user']:[]
            ),
            'Conversaciones'=>[
                'Por sesión'=>[],
                'Por fecha'=>[],
                'Por temas'=>[],
                'Por proyectos'=>[],
                'Por personas'=>[],
                'Por personajes'=>[],
                'Por entidades'=>[],
                'Por fuente'=>[],
                'Por estado'=>[],
            ],
            'Knowledge'=>[],
        ];

        $interactions=[];
        $knowledge=[];
        foreach($this->library->listAs($actor) as $entry){
            foreach($entry['logical_refs']??[] as $ref){
                if(!is_string($ref)) continue;
                if(str_starts_with($ref,'memory://interactions/')){
                    try{$interactions[]=$this->read($actor,$ref)['interaction']+['logical_ref'=>$ref];}catch(Throwable){}
                    break;
                }
                if(preg_match('#^memory://knowledge/q-[0-9a-f]{64}$#',$ref)){
                    try{
                        $stored=$this->library->readAs($actor,$ref);
                        $record=$stored['payload']['content']??null;
                        if(is_array($record)){
                            KnowledgeRecord::validate($record);
                            $knowledge[]=['logical_ref'=>$ref,'record'=>$record];
                        }
                    }catch(Throwable){}
                    break;
                }
            }
        }

        usort($interactions,static fn(array $a,array $b): int =>
            (strtotime((string)$a['at'])?:0)<=>(strtotime((string)$b['at'])?:0)
        );

        $sessions=[];
        foreach($interactions as $item){
            $ref=(string)$item['logical_ref'];
            $at=(string)$item['at'];
            $question=(string)$item['question'];
            $conversation=(string)$item['conversation_id'];
            $leaf=self::leafLabel($question,(string)$item['interaction_id']);

            $sessions[$conversation][]=$item;
            self::addViewPath($tree['Conversaciones']['Por fecha'],[
                substr($at,0,4),substr($at,5,2),substr($at,8,2),$conversation,$leaf
            ],$ref,'interaction');

            $catalog=is_array($item['catalog']??null)?$item['catalog']:[];
            foreach(self::labels($catalog['topics']??[]) as $label) self::addViewPath($tree['Conversaciones']['Por temas'],[$label,$leaf],$ref,'interaction');
            foreach(self::labels($catalog['projects']??[]) as $label) self::addViewPath($tree['Conversaciones']['Por proyectos'],[$label,$leaf],$ref,'interaction');
            foreach(self::labels($catalog['people']??[]) as $label) self::addViewPath($tree['Conversaciones']['Por personas'],[$label,$leaf],$ref,'interaction');
            foreach(self::labels($catalog['characters']??[]) as $label) self::addViewPath($tree['Conversaciones']['Por personajes'],[$label,$leaf],$ref,'interaction');
            foreach(self::labels($catalog['entities']??[]) as $label) self::addViewPath($tree['Conversaciones']['Por entidades'],[$label,$leaf],$ref,'interaction');
            foreach(self::labels($catalog['sources']??[]) as $label) self::addViewPath($tree['Conversaciones']['Por fuente'],[$label,$leaf],$ref,'interaction');

            $state=(string)($item['validation']['state']??'unverified');
            self::addViewPath($tree['Conversaciones']['Por estado'],[$state,$leaf],$ref,'interaction');
        }

        foreach($sessions as $conversation=>$items){
            $first=$items[0];
            $last=$items[count($items)-1];
            $title=self::sessionLabel($first,$last,count($items));
            foreach($items as $item){
                self::addViewPath(
                    $tree['Conversaciones']['Por sesión'],
                    [$title,self::leafLabel((string)$item['question'],(string)$item['interaction_id'])],
                    (string)$item['logical_ref'],'interaction'
                );
            }
        }

        foreach(['Por temas','Por proyectos','Por personas','Por personajes','Por entidades'] as $view){
            if($tree['Conversaciones'][$view]===[]) $tree['Conversaciones'][$view]=['Sin clasificar'=>[]];
        }

        foreach($knowledge as $item){
            $record=$item['record'];
            $state=(string)($record['epistemic']['validation_state']??'unverified');
            $captured=(string)($record['epistemic']['captured_at']??'');
            $label=self::leafLabel((string)$record['intent']['question'],substr((string)$item['logical_ref'],-8));
            self::addViewPath($tree['Knowledge'],[$state,substr($captured,0,10),$label],(string)$item['logical_ref'],'knowledge');
        }

        return [
            'root'=>'Biblioteca MCMA',
            'tree'=>$tree,
            'interaction_total'=>count($interactions),
            'knowledge_total'=>count($knowledge),
            'ai_tokens_used'=>0,
            'credit_units_charged'=>0,
        ];
    }

    private function conversationIndex(string $actor): array
    {
        $this->ensureConversationIndexPolicy();
        $refs=$this->interactionRefs($actor);
        $index=$this->readConversationIndex($actor);

        if(
            $index===null
            || (int)($index['indexed_interaction_count']??-1)!==count($refs)
            || !hash_equals((string)($index['refs_hash']??''),self::refsHash($refs))
        ){
            return $this->rebuildConversationIndex($actor,$refs);
        }

        return $index;
    }

    private function upsertConversationIndex(string $actor,string $logicalRef,array $interaction): void
    {
        self::assertInteractionRef($logicalRef);
        $this->ensureConversationIndexPolicy();

        $refs=$this->interactionRefs($actor);
        $index=$this->readConversationIndex($actor);
        if($index===null){
            $this->rebuildConversationIndex($actor,$refs);
            return;
        }

        self::indexInteraction($index,$logicalRef,$interaction);
        $index['indexed_interaction_count']=count($refs);
        $index['refs_hash']=self::refsHash($refs);
        $index['updated_at']=gmdate('Y-m-d\TH:i:s\Z');
        $this->persistConversationIndex($actor,$index);
    }

    private function rebuildConversationIndex(string $actor,array $refs): array
    {
        $index=[
            'conversation_index_version'=>self::CONVERSATION_INDEX_VERSION,
            'updated_at'=>gmdate('Y-m-d\TH:i:s\Z'),
            'indexed_interaction_count'=>count($refs),
            'refs_hash'=>self::refsHash($refs),
            'conversations'=>[],
        ];

        foreach($refs as $logicalRef){
            try{
                $detail=$this->read($actor,$logicalRef);
                self::indexInteraction($index,$logicalRef,$detail['interaction']);
            }catch(Throwable $e){
                error_log('MCMA conversation index rebuild skipped interaction: '.$e->getMessage());
            }
        }

        $this->persistConversationIndex($actor,$index);
        return $index;
    }

    private function readConversationIndex(string $actor): ?array
    {
        try{
            $stored=$this->library->readAs($actor,self::CONVERSATION_INDEX_REF);
        }catch(Throwable $e){
            if(str_contains($e->getMessage(),'Memory not found:')) return null;
            throw $e;
        }

        $content=$stored['payload']['content']??null;
        if(
            !is_array($content)
            || ($content['conversation_index_version']??null)!==self::CONVERSATION_INDEX_VERSION
            || !is_array($content['conversations']??null)
        ){
            return null;
        }
        return $content;
    }

    private function persistConversationIndex(string $actor,array $index): void
    {
        $exists=false;
        foreach($this->library->listAs($actor) as $entry){
            if(in_array(self::CONVERSATION_INDEX_REF,$entry['logical_refs']??[],true)){
                $exists=true;
                break;
            }
        }

        if(!$exists){
            $this->library->writeAs(
                $actor,self::CONVERSATION_INDEX_REF,$index,'json','hot','00-system','system','confirmed'
            );
            return;
        }

        $this->library->updateAs(
            $actor,self::CONVERSATION_INDEX_REF,$index,'json','hot','00-system','system','confirmed'
        );
    }

    private function interactionRefs(string $actor): array
    {
        $refs=[];
        foreach($this->library->listAs($actor) as $entry){
            foreach($entry['logical_refs']??[] as $ref){
                if(is_string($ref)&&str_starts_with($ref,'memory://interactions/')){
                    try{self::assertInteractionRef($ref);$refs[]=$ref;}catch(Throwable){}
                    break;
                }
            }
        }
        sort($refs,SORT_STRING);
        return array_values(array_unique($refs));
    }

    private function ensureConversationIndexPolicy(): void
    {
        try{
            $policy=$this->library->permissions('owner');
        }catch(RuntimeException){
            return;
        }

        $denyFound=false;
        $ownerFound=false;
        foreach($policy['resources']??[] as $rule){
            if(!is_array($rule)||($rule['resource']??null)!==self::CONVERSATION_INDEX_REF) continue;
            if(($rule['subject']??null)==='*') $denyFound=true;
            if(($rule['subject']??null)==='owner') $ownerFound=true;
        }
        if($denyFound&&$ownerFound) return;

        if(!$denyFound){
            $policy['resources'][]=[
                'resource'=>self::CONVERSATION_INDEX_REF,
                'subject'=>'*',
                'deny'=>['*'],
            ];
        }
        if(!$ownerFound){
            $policy['resources'][]=[
                'resource'=>self::CONVERSATION_INDEX_REF,
                'subject'=>'owner',
                'allow'=>['read','write','update','delete'],
            ];
        }
        $this->library->setPermissions($policy,'owner');
    }

    private static function indexInteraction(array &$index,string $logicalRef,array $interaction): void
    {
        $conversationId=(string)($interaction['conversation_id']??'');
        if(!preg_match('/^conv_[0-9a-f]{32}$/',$conversationId)) return;

        $at=(string)($interaction['at']??'');
        $catalog=is_array($interaction['catalog']??null)?$interaction['catalog']:[];
        $title=self::cleanLabel((string)($catalog['title']??''));
        if($title==='') $title=self::shortTitle((string)($interaction['question']??'Conversación'));
        $projects=self::labels($catalog['projects']??[]);

        $existing=$index['conversations'][$conversationId]??null;
        if(!is_array($existing)){
            $existing=[
                'conversation_id'=>$conversationId,
                'title'=>$title,
                'first_at'=>$at,
                'last_at'=>$at,
                'interaction_count'=>0,
                'interaction_refs'=>[],
                'recent_interactions'=>[],
                'projects'=>[],
            ];
        }

        $refs=is_array($existing['interaction_refs']??null)?$existing['interaction_refs']:[];
        if(!in_array($logicalRef,$refs,true)) $refs[]=$logicalRef;
        sort($refs,SORT_STRING);

        $firstAt=(string)($existing['first_at']??'');
        if($firstAt===''||($at!==''&&(strtotime($at)?:0)<(strtotime($firstAt)?:0))){
            $existing['first_at']=$at;
            $existing['title']=$title;
        }
        $lastAt=(string)($existing['last_at']??'');
        if($lastAt===''||($at!==''&&(strtotime($at)?:0)>=(strtotime($lastAt)?:0))){
            $existing['last_at']=$at;
        }

        $recent=is_array($existing['recent_interactions']??null)?$existing['recent_interactions']:[];
        $recent=array_values(array_filter($recent,static fn($item): bool =>
            is_array($item)&&is_string($item['ref']??null)&&$item['ref']!==$logicalRef
        ));
        $recent[]=['ref'=>$logicalRef,'at'=>$at];
        usort($recent,static function(array $a,array $b): int {
            $timeCompare=(strtotime((string)($a['at']??''))?:0)<=>(strtotime((string)($b['at']??''))?:0);
            if($timeCompare!==0) return $timeCompare;
            return (string)($a['ref']??'')<=>(string)($b['ref']??'');
        });
        if(count($recent)>32) $recent=array_slice($recent,-32);

        $existing['interaction_refs']=$refs;
        $existing['recent_interactions']=$recent;
        $existing['interaction_count']=count($refs);
        $existing['projects']=array_values(array_unique(array_merge(
            self::labels($existing['projects']??[]),
            $projects
        )));
        $index['conversations'][$conversationId]=$existing;
    }

    private static function conversationSummary(array $conversation): array
    {
        return [
            'conversation_id'=>(string)($conversation['conversation_id']??''),
            'title'=>(string)($conversation['title']??'Conversación'),
            'first_at'=>(string)($conversation['first_at']??''),
            'last_at'=>(string)($conversation['last_at']??''),
            'interaction_count'=>(int)($conversation['interaction_count']??0),
            'projects'=>self::labels($conversation['projects']??[]),
        ];
    }

    private static function refsHash(array $refs): string
    {
        return 'sha256:'.hash('sha256',implode("\n",$refs));
    }

    private function approveKnowledge(string $actor,string $interactionRef,array $interaction,?EmbeddingProvider $embeddingProvider): array
    {
        $question=trim((string)($interaction['question']??''));
        $answer=$interaction['answer']['value']??null;
        if($question===''||$answer===null||$answer==='') throw new RuntimeException('Interaction has no answer to approve');

        $knowledge=new KnowledgeService($this->library);
        $knowledgeRef=is_string($interaction['knowledge_ref']??null)?(string)$interaction['knowledge_ref']:KnowledgeRecord::logicalRef($question);
        $existing=false;
        try{
            $this->library->readAs($actor,$knowledgeRef);
            $existing=true;
        }catch(Throwable $e){
            if(!str_contains($e->getMessage(),'Memory not found:')) throw $e;
        }

        if($existing&&preg_match('#^memory://knowledge/q-[0-9a-f]{64}$#',$knowledgeRef)){
            $detail=$this->library->readAs($actor,$knowledgeRef);
            $record=$detail['payload']['content']??null;
            if(is_array($record)){
                KnowledgeRecord::validate($record);
                $knowledgeResult=$knowledge->validateKnowledge(
                    $actor,
                    (string)$record['intent']['question'],
                    'verified',0.95,'owner-approved-interaction',[[
                        'source_type'=>'user',
                        'reference'=>$interactionRef,
                        'note'=>'Owner approved this archived interaction as reusable knowledge',
                    ]]
                );
            }else{
                $existing=false;
            }
        }

        if(!$existing){
            $relations=array_values(array_filter(array_unique(array_merge(
                [$interactionRef],
                is_array($interaction['relations']??null)?$interaction['relations']:[]
            )),static fn($v)=>is_string($v)&&$v!==''));
            $knowledgeResult=$knowledge->capture(
                $actor,$question,$answer,
                is_string($interaction['answer']['format']??null)?(string)$interaction['answer']['format']:'text',
                0.95,'verified',[[
                    'source_type'=>'user',
                    'reference'=>$interactionRef,
                    'note'=>'Owner approved this archived interaction as reusable knowledge',
                ]],
                'stable',31536000,'reuse-unless-stale',$relations
            );
            $knowledgeRef=KnowledgeRecord::logicalRef($question);
        }

        $semantic=null;
        if($embeddingProvider!==null){
            try{
                $semantic=(new SemanticIndexService($this->library))->refreshStoredEntry($embeddingProvider,$knowledgeRef,$actor);
            }catch(Throwable $e){
                $semantic=['ok'=>false,'error'=>self::safeError($e)];
            }
        }

        return [
            'logical_ref'=>$knowledgeRef,
            'created'=>!$existing,
            'validation_state'=>'verified',
            'confidence'=>0.95,
            'semantic_index'=>$semantic,
        ];
    }

    private function retractRelatedKnowledge(string $actor,array $interaction,?EmbeddingProvider $embeddingProvider): ?array
    {
        $knowledgeRef=$interaction['knowledge_ref']??null;
        if(!is_string($knowledgeRef)||!preg_match('#^memory://knowledge/q-[0-9a-f]{64}$#',$knowledgeRef)) return null;

        try{
            $stored=$this->library->readAs($actor,$knowledgeRef);
            $record=$stored['payload']['content']??null;
            if(!is_array($record)) return null;
            KnowledgeRecord::validate($record);
            $knowledge=new KnowledgeService($this->library);
            $result=$knowledge->validateKnowledge(
                $actor,(string)$record['intent']['question'],
                'retracted',0.0,'owner-discarded-interaction',[[
                    'source_type'=>'user',
                    'reference'=>(string)($interaction['interaction_id']??'interaction'),
                    'note'=>'Owner rejected the archived interaction as knowledge',
                ]]
            );
            $semantic=null;
            if($embeddingProvider!==null){
                try{$semantic=(new SemanticIndexService($this->library))->refreshStoredEntry($embeddingProvider,$knowledgeRef,$actor);}
                catch(Throwable $e){$semantic=['ok'=>false,'error'=>self::safeError($e)];}
            }
            return ['logical_ref'=>$knowledgeRef,'validation_state'=>'retracted','semantic_index'=>$semantic]+$result;
        }catch(Throwable){
            return null;
        }
    }

    private static function initialCatalog(string $question,mixed $answer,array $result): array
    {
        $classification=is_array($result['storage']['classification']??null)?$result['storage']['classification']:[];
        $topics=self::labels($classification['category_path']??[]);
        $projects=[];
        foreach($topics as $i=>$label){
            $lower=function_exists('mb_strtolower')?mb_strtolower($label,'UTF-8'):strtolower($label);
            if(in_array($lower,['proyectos','projects'],true)&&isset($topics[$i+1])) $projects[]=$topics[$i+1];
        }

        $text=$question.' '.(is_string($answer)?$answer:'');
        $entities=self::extractEntities($text);
        $source=(string)($result['provider_id']??'');
        if($source==='') $source=(string)($result['route']??'MCMA');

        return [
            'title'=>self::shortTitle($question),
            'topics'=>$topics,
            'projects'=>array_values(array_unique($projects)),
            'people'=>[],
            'characters'=>[],
            'entities'=>$entities,
            'sources'=>[$source],
            'classification_status'=>$topics!==[]?'derived-from-memory':'pending-approval',
        ];
    }

    private static function convertCanonicalTree(array $node,array $segments=['user']): array
    {
        $out=[];
        foreach($node as $key=>$value){
            if($key==='@object_id') continue;
            if(!is_array($value)) continue;
            $childSegments=[...$segments,$key];
            $child=self::convertCanonicalTree($value,$childSegments);
            if(isset($value['@object_id'])){
                $child['@ref']='memory://'.implode('/',$childSegments);
                $child['@kind']='memory';
            }
            $out[$key]=$child;
        }
        return $out;
    }

    private static function addViewPath(array &$tree,array $segments,string $ref,string $kind): void
    {
        $node=&$tree;
        foreach($segments as $segment){
            $segment=self::cleanLabel($segment);
            if($segment==='') $segment='Sin clasificar';
            if(!isset($node[$segment])||!is_array($node[$segment])) $node[$segment]=[];
            $node=&$node[$segment];
        }
        $node['@ref']=$ref;
        $node['@kind']=$kind;
        unset($node);
    }

    private static function labels(mixed $value): array
    {
        if(!is_array($value)) return [];
        $out=[];
        foreach($value as $item){
            if(!is_string($item)) continue;
            $item=self::cleanLabel($item);
            if($item!==''&&!in_array($item,$out,true)) $out[]=$item;
            if(count($out)>=12) break;
        }
        return $out;
    }

    private static function extractEntities(string $text): array
    {
        $entities=[];
        if(preg_match_all('/\b(?:[a-z0-9-]+\.)+[a-z]{2,}\b/i',$text,$m)){
            foreach($m[0] as $value) $entities[]=strtolower($value);
        }
        if(preg_match_all('/\b[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\b/',$text,$m)){
            foreach($m[0] as $value) $entities[]=$value;
        }
        return array_slice(array_values(array_unique($entities)),0,12);
    }

    private static function knowledgeRefFromResult(array $result): ?string
    {
        $candidate=$result['storage']['retrieval']['logical_ref']??$result['logical_ref']??null;
        return is_string($candidate)&&str_starts_with($candidate,'memory://knowledge/')?$candidate:null;
    }

    private static function canonicalRefFromResult(array $result): ?string
    {
        foreach([
            $result['canonical_memory_ref']??null,
            $result['storage']['logical_ref']??null,
            $result['logical_ref']??null,
        ] as $candidate){
            if(is_string($candidate)&&str_starts_with($candidate,'memory://user/')) return $candidate;
        }
        return null;
    }

    private static function billingSummary(mixed $billing): ?array
    {
        if(!is_array($billing)) return null;
        $usage=is_array($billing['usage']??null)?$billing['usage']:[];
        $providerUsage=is_array($billing['provider_usage']??null)?array_values($billing['provider_usage']):[];
        return [
            'credit_units_charged'=>(int)($billing['credit_units_charged']??0),
            'cost_micros'=>(int)($billing['cost_micros']??0),
            'currency'=>isset($billing['currency'])?(string)$billing['currency']:null,
            'total_tokens'=>(int)($usage['total_tokens']??$usage['totalTokens']??0),
            'usage'=>$usage,
            'provider_usage'=>$providerUsage,
        ];
    }

    private static function containsText(string $haystack,string $needle): bool
    {
        if(function_exists('mb_stripos')) return mb_stripos($haystack,$needle,0,'UTF-8')!==false;
        return stripos($haystack,$needle)!==false;
    }

    private static function shortTitle(string $question): string
    {
        $question=preg_replace('/\s+/u',' ',trim($question))??trim($question);
        if(strlen($question)<=100) return $question;
        return rtrim(substr($question,0,97)).'…';
    }

    private static function leafLabel(string $question,string $id): string
    {
        return self::shortTitle($question).' · '.substr($id,-8);
    }

    private static function sessionLabel(array $first,array $last,int $count): string
    {
        $at=(string)($first['at']??'');
        $question=(string)($first['question']??'Conversación');
        return substr($at,0,16).' · '.self::shortTitle($question).' · '.$count.' turnos';
    }

    private static function cleanLabel(string $value): string
    {
        $value=preg_replace('/[\x00-\x1F\x7F]+/u',' ',trim($value))??trim($value);
        $value=preg_replace('/\s+/u',' ',$value)??$value;
        return strlen($value)>120?substr($value,0,120):$value;
    }

    private static function assertInteractionRef(string $logicalRef): void
    {
        if(!preg_match('#^memory://interactions/[0-9]{4}/[0-9]{2}/[0-9]{2}/conv_[0-9a-f]{32}/req_[0-9a-f]{32}$#',$logicalRef)){
            throw new RuntimeException('Invalid interaction logical reference');
        }
    }

    private static function safeError(Throwable $e): string
    {
        $message=trim($e->getMessage());
        return $message===''?'operation failed':substr($message,0,240);
    }
}
