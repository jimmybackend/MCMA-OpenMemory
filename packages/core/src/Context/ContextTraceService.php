<?php
declare(strict_types=1);

namespace MCMA\Core\Context;

use MCMA\Core\Knowledge\KnowledgeRecord;
use MCMA\Core\Library;
use RuntimeException;
use Throwable;

final class ContextTraceService
{
    public const REF = 'memory://system/context-traces';
    private const VERSION = '1.0';
    private const MAX_TRACES = 50;
    private const CATALOG_REF = 'memory://system/context-knowledge-catalog';
    private const CATALOG_VERSION = '1.0';
    private const CATALOG_BATCH = 8;

    public function __construct(private readonly Library $library) {}

    public function record(
        string $requestId,
        string $question,
        bool $currentRequired,
        bool $rememberRequested,
        array $result
    ): array {
        $this->ensurePrivatePolicy();

        $trace = [
            'trace_id' => $requestId,
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
            'question' => $question,
            'current_required' => $currentRequired,
            'remember_requested' => $rememberRequested,
            'route' => (string)($result['route'] ?? 'unknown'),
            'provider_called' => (bool)($result['provider_called'] ?? false),
            'provider_id' => isset($result['provider_id']) ? (string)$result['provider_id'] : null,
            'memory_attempt' => is_array($result['memory_attempt'] ?? null) ? $result['memory_attempt'] : null,
            'context_used' => is_array($result['context_used'] ?? null) ? $result['context_used'] : ['memory'=>false],
            'stored' => (bool)($result['stored'] ?? false),
            'stored_logical_ref' => isset($result['logical_ref']) ? (string)$result['logical_ref'] : null,
            'storage' => is_array($result['storage'] ?? null) ? [
                'validation_state' => $result['storage']['validation_state'] ?? null,
                'confidence' => $result['storage']['confidence'] ?? null,
                'created' => $result['storage']['created'] ?? null,
            ] : null,
            'billing' => self::billingSummary($result['billing'] ?? null),
        ];

        if ($this->exists()) {
            $this->library->mutateJson(self::REF, static function(mixed $current) use ($trace): array {
                $payload = is_array($current) ? $current : [];
                $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];
                array_unshift($entries, $trace);
                $entries = array_slice($entries, 0, self::MAX_TRACES);
                return [
                    'context_trace_version' => self::VERSION,
                    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'entries' => $entries,
                ];
            }, 'owner');
        } else {
            $this->library->writeAs(
                'owner',
                self::REF,
                [
                    'context_trace_version' => self::VERSION,
                    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'entries' => [$trace],
                ],
                'json',
                'hot',
                '00-system',
                'system',
                'observed'
            );
        }

        return $trace;
    }

    public function snapshot(float $minConfidence = 0.75): array
    {
        $this->ensurePrivatePolicy();

        $rootEntries=$this->library->listAs('owner');
        $knowledge=[];
        $systemObjects=[];

        foreach($rootEntries as $entry){
            foreach($entry['logical_refs']??[] as $ref){
                if(!is_string($ref)) continue;

                if(str_starts_with($ref,'memory://system/')){
                    $systemObjects[$ref]=[
                        'logical_ref'=>$ref,
                        'temperature'=>(string)($entry['temperature']??'cold'),
                        'cognitive_layer'=>(string)($entry['cognitive_layer']??'00-system'),
                        'scope'=>(string)($entry['scope']??'system'),
                    ];
                }

                if(!preg_match('#^memory://knowledge/q-[0-9a-f]{64}$#',$ref)) continue;
                $knowledge[$ref]=[
                    'object_id'=>(string)($entry['object_id']??''),
                    'storage_hash'=>(string)($entry['storage_hash']??''),
                    'temperature'=>(string)($entry['temperature']??'warm'),
                ];
            }
        }

        [$catalog,$catalogExists]=$this->readCatalog();
        $catalogEntries=is_array($catalog['entries']??null)?$catalog['entries']:[];
        $changed=false;

        foreach(array_keys($catalogEntries) as $ref){
            if(!isset($knowledge[$ref])){
                unset($catalogEntries[$ref]);
                $changed=true;
            }
        }

        $processed=0;
        foreach($knowledge as $ref=>$identity){
            $cached=is_array($catalogEntries[$ref]??null)?$catalogEntries[$ref]:null;
            if(
                $cached!==null
                &&hash_equals((string)($cached['storage_hash']??''),(string)$identity['storage_hash'])
            ) continue;
            if($processed>=self::CATALOG_BATCH) continue;

            try{
                $stored=$this->library->readAs('owner',$ref);
                $record=$stored['payload']['content']??null;
                if(!is_array($record)) throw new RuntimeException('Stored knowledge record is malformed');
                KnowledgeRecord::validate($record);
                $assessment=KnowledgeRecord::assess($record,false,$minConfidence);

                $modelSource=null;
                foreach($record['provenance']??[] as $source){
                    if(is_array($source)&&($source['source_type']??null)==='model'){
                        $modelSource=(string)($source['reference']??'model');
                        break;
                    }
                }

                $catalogEntries[$ref]=[
                    'logical_ref'=>$ref,
                    'object_id'=>(string)($stored['object_id']??$identity['object_id']),
                    'storage_hash'=>(string)($stored['storage_hash']??$identity['storage_hash']),
                    'question'=>(string)($record['intent']['question']??'Knowledge'),
                    'validation_state'=>(string)($record['epistemic']['validation_state']??'unverified'),
                    'confidence'=>(float)($record['epistemic']['confidence']??0.0),
                    'temperature'=>(string)($stored['payload']['metadata']['temperature']??$identity['temperature']),
                    'captured_at'=>(string)($record['epistemic']['captured_at']??''),
                    'reusable'=>(bool)($assessment['reusable']??false),
                    'provider_id'=>$modelSource,
                    'valid'=>true,
                ];
            }catch(Throwable $e){
                $catalogEntries[$ref]=[
                    'logical_ref'=>$ref,
                    'object_id'=>(string)$identity['object_id'],
                    'storage_hash'=>(string)$identity['storage_hash'],
                    'question'=>'Knowledge',
                    'validation_state'=>'unverified',
                    'confidence'=>0.0,
                    'temperature'=>(string)$identity['temperature'],
                    'captured_at'=>'',
                    'reusable'=>false,
                    'provider_id'=>null,
                    'valid'=>false,
                    'error'=>self::safeCatalogError($e),
                ];
            }
            $processed++;
            $changed=true;
        }

        if($changed||!$catalogExists){
            $catalog=[
                'context_knowledge_catalog_version'=>self::CATALOG_VERSION,
                'updated_at'=>gmdate('Y-m-d\TH:i:s\Z'),
                'entries'=>$catalogEntries,
            ];
            $this->persistCatalog($catalog,$catalogExists);
            $systemObjects[self::CATALOG_REF]=[
                'logical_ref'=>self::CATALOG_REF,
                'temperature'=>'warm',
                'cognitive_layer'=>'00-system',
                'scope'=>'system',
            ];
        }

        $summary=[
            'total'=>count($knowledge),
            'reusable'=>0,
            'generated_by_model'=>0,
            'cataloged'=>0,
            'pending'=>0,
            'validation'=>[],
            'temperatures'=>['hot'=>0,'warm'=>0,'cold'=>0,'frozen'=>0],
        ];
        $generated=[];

        foreach($knowledge as $ref=>$identity){
            $item=is_array($catalogEntries[$ref]??null)?$catalogEntries[$ref]:null;
            if(
                $item===null
                ||!hash_equals((string)($item['storage_hash']??''),(string)$identity['storage_hash'])
            ){
                $summary['pending']++;
                continue;
            }

            $summary['cataloged']++;
            if(($item['reusable']??false)===true) $summary['reusable']++;
            $state=(string)($item['validation_state']??'unverified');
            $summary['validation'][$state]=(int)($summary['validation'][$state]??0)+1;
            $temperature=(string)($item['temperature']??'warm');
            if(array_key_exists($temperature,$summary['temperatures'])) $summary['temperatures'][$temperature]++;

            if(is_string($item['provider_id']??null)&&$item['provider_id']!==''){
                $summary['generated_by_model']++;
                $generated[]=[
                    'logical_ref'=>$ref,
                    'question'=>(string)($item['question']??'Knowledge'),
                    'validation_state'=>$state,
                    'confidence'=>(float)($item['confidence']??0.0),
                    'temperature'=>$temperature,
                    'captured_at'=>(string)($item['captured_at']??''),
                    'provider_id'=>(string)$item['provider_id'],
                    'reusable'=>(bool)($item['reusable']??false),
                ];
            }
        }

        usort($generated,static fn(array $a,array $b): int =>
            (strtotime((string)$b['captured_at'])?:0)<=>(strtotime((string)$a['captured_at'])?:0)
        );
        ksort($systemObjects,SORT_STRING);

        $catalogComplete=$summary['pending']===0;

        return [
            'summary'=>$summary,
            'generated_memories'=>array_slice($generated,0,50),
            'system_objects'=>array_values($systemObjects),
            'traces'=>$this->traces(),
            'catalog'=>[
                'complete'=>$catalogComplete,
                'indexed'=>(int)$summary['cataloged'],
                'total'=>(int)$summary['total'],
                'pending'=>(int)$summary['pending'],
                'batch_size'=>self::CATALOG_BATCH,
            ],
            'ai_tokens_used'=>0,
            'credit_units_charged'=>0,
        ];
    }

    private function readCatalog(): array
    {
        try{
            $stored=$this->library->readAs('owner',self::CATALOG_REF);
        }catch(Throwable $e){
            if(str_contains($e->getMessage(),'Memory not found:')) return [[
                'context_knowledge_catalog_version'=>self::CATALOG_VERSION,
                'updated_at'=>null,
                'entries'=>[],
            ],false];
            throw $e;
        }

        $content=$stored['payload']['content']??null;
        if(
            !is_array($content)
            ||($content['context_knowledge_catalog_version']??null)!==self::CATALOG_VERSION
            ||!is_array($content['entries']??null)
        ){
            return [[
                'context_knowledge_catalog_version'=>self::CATALOG_VERSION,
                'updated_at'=>null,
                'entries'=>[],
            ],true];
        }
        return [$content,true];
    }

    private function persistCatalog(array $catalog,bool $exists): void
    {
        if($exists){
            $this->library->updateAs(
                'owner',self::CATALOG_REF,$catalog,'json','warm','00-system','system','confirmed'
            );
            return;
        }

        $this->library->writeAs(
            'owner',self::CATALOG_REF,$catalog,'json','warm','00-system','system','confirmed'
        );
    }

    private function traces(): array
    {
        try{
            $stored=$this->library->readAs('owner',self::REF);
        }catch(Throwable $e){
            if(str_contains($e->getMessage(),'Memory not found:')) return [];
            throw $e;
        }
        $content=$stored['payload']['content']??null;
        if(!is_array($content)) return [];
        return is_array($content['entries']??null)?array_values($content['entries']):[];
    }

    private function exists(): bool
    {
        try{
            $this->library->readAs('owner',self::REF);
            return true;
        }catch(Throwable $e){
            if(str_contains($e->getMessage(),'Memory not found:')) return false;
            throw $e;
        }
    }

    private function ensurePrivatePolicy(): void
    {
        try{
            $policy=$this->library->permissions('owner');
        }catch(RuntimeException){
            return;
        }

        $changed=false;
        foreach([self::REF,self::CATALOG_REF] as $resource){
            $denyFound=false;
            $ownerFound=false;
            foreach($policy['resources']??[] as $rule){
                if(!is_array($rule)||($rule['resource']??null)!==$resource) continue;
                if(($rule['subject']??null)==='*') $denyFound=true;
                if(($rule['subject']??null)==='owner') $ownerFound=true;
            }
            if(!$denyFound){
                $policy['resources'][]=[
                    'resource'=>$resource,
                    'subject'=>'*',
                    'deny'=>['*'],
                ];
                $changed=true;
            }
            if(!$ownerFound){
                $policy['resources'][]=[
                    'resource'=>$resource,
                    'subject'=>'owner',
                    'allow'=>['read','write','update','delete'],
                ];
                $changed=true;
            }
        }

        if($changed) $this->library->setPermissions($policy,'owner');
    }

    private static function safeCatalogError(Throwable $e): string
    {
        $message=trim($e->getMessage());
        if($message==='') return 'catalog-read-error';
        return substr($message,0,180);
    }

    private static function billingSummary(mixed $billing): ?array
    {
        if (!is_array($billing)) return null;
        return [
            'credit_units_charged' => (int)($billing['credit_units_charged'] ?? 0),
            'cost_micros' => (int)($billing['cost_micros'] ?? 0),
            'currency' => isset($billing['currency']) ? (string)$billing['currency'] : null,
            'usage' => is_array($billing['usage'] ?? null) ? $billing['usage'] : null,
            'provider_usage' => is_array($billing['provider_usage'] ?? null) ? array_values($billing['provider_usage']) : [],
        ];
    }
}
