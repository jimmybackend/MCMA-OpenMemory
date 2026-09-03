<?php
declare(strict_types=1);

namespace MCMA\Core\Memory;

use MCMA\Core\Knowledge\KnowledgeService;
use MCMA\Core\Library;
use MCMA\Core\Semantic\EmbeddingProvider;
use MCMA\Core\Semantic\SemanticIndexService;
use RuntimeException;
use Throwable;

final class MemoryMutationService
{
    public function __construct(
        private readonly Library $library,
        private readonly ?EmbeddingProvider $embeddingProvider=null
    ) {}

    public static function isMutationRequest(string $text): bool
    {
        $text=trim($text);
        if($text==='') return false;
        return preg_match('/^(?:por\s+favor\s+)?(?:actualiza|modifica|edita|corrige|cambia|agrega|añade|anade|incorpora|elimina|borra|retira|update|modify|edit|correct|append|add|delete|remove)\b/iu',$text)===1
            && (preg_match('/\b(?:memoria|recuerdo|archivo|conocimiento|concepto|memory|file|knowledge|concept)\b/iu',$text)===1||str_contains($text,'memory://user/'));
    }

    public function execute(string $actor,string $requestText,?string $contextCanonicalRef=null): array
    {
        $parsed=self::parse($requestText);
        if(($parsed['target']??null)==='@context'&&is_string($contextCanonicalRef)&&str_starts_with($contextCanonicalRef,'memory://user/')){
            try{
                $this->library->readAs($actor,$contextCanonicalRef);
                $resolved=['status'=>'resolved','logical_ref'=>$contextCanonicalRef,'candidates'=>[]];
            }catch(Throwable){
                $resolved=['status'=>'not-found','candidates'=>[]];
            }
        }else{
            $resolved=$this->resolveTarget($actor,(string)($parsed['target']??''));
        }
        if(($resolved['status']??'')!=='resolved'){
            $candidates=$resolved['candidates']??[];
            $message=($resolved['status']??'')==='ambiguous'
                ?'Encontré varias memorias posibles. Especifica una ruta memory://user/...: '.implode(', ',array_column($candidates,'logical_ref'))
                :'No encontré una memoria personal que coincida con "'.$parsed['target'].'".';
            return [
                'found'=>true,'reusable'=>false,'decision'=>'memory-mutation-needs-target',
                'route'=>'memory-mutation','provider_called'=>false,
                'answer'=>['format'=>'text','value'=>$message],
                'stored'=>false,'mutation'=>['action'=>$parsed['action'],'status'=>$resolved['status'],'candidates'=>$candidates],
                'context_used'=>['memory'=>false],
            ];
        }

        $ref=(string)$resolved['logical_ref'];
        $stored=$this->library->readAs($actor,$ref);
        $payload=$stored['payload'];
        $format=(string)($payload['content_format']??'json');
        $metadata=is_array($payload['metadata']??null)?$payload['metadata']:[];
        $current=$payload['content']??null;
        $previousHash=$stored['storage_hash'];
        $now=gmdate('Y-m-d\TH:i:s\Z');

        $knowledgeRef=null;
        if(is_array($current)&&is_string($current['retrieval']['knowledge_ref']??null)){
            $knowledgeRef=$current['retrieval']['knowledge_ref'];
        }

        if($parsed['action']==='delete'){
            if(is_array($current)){
                $next=$current;
                $next['lifecycle']=['status'=>'deleted','deleted_at'=>$now,'previous_storage_hash'=>$previousHash];
                if(array_key_exists('content',$next)) $next['content']='';
                if(is_array($next['source']??null)&&array_key_exists('original',$next['source'])) $next['source']['original']='';
            }else{
                $next=[
                    'memory_tombstone_version'=>'1.0',
                    'status'=>'deleted',
                    'deleted_at'=>$now,
                    'previous_storage_hash'=>$previousHash,
                ];
                $format='json';
            }
            $result=$this->library->updateAs(
                $actor,$ref,$next,$format,'cold',
                (string)($metadata['cognitive_layer']??'40-semantic'),
                (string)($metadata['scope']??'user'),'observed'
            );
            $semantic=null;
            if(is_string($knowledgeRef)&&str_starts_with($knowledgeRef,'memory://knowledge/')){
                try{
                    $knowledge=new KnowledgeService($this->library);
                    $detail=$knowledge->inspect($actor,(string)($current['retrieval']['question']??''));
                    $question=(string)($detail['record']['intent']['question']??$current['retrieval']['question']??'');
                    if($question!=='') $knowledge->validateKnowledge($actor,$question,'retracted',0.0,'owner-deleted-canonical-memory',[
                        ['source_type'=>'user','reference'=>$ref,'note'=>'Canonical owner memory deleted through versioned mutation'],
                    ]);
                    if($this->embeddingProvider!==null) $semantic=(new SemanticIndexService($this->library))->remove($this->embeddingProvider,$knowledgeRef,'librarian');
                }catch(Throwable $e){$semantic=['error'=>self::safeError($e)];}
            }
            return self::result($ref,$result,'delete','Memoria retirada de uso. Se conservó el historial cifrado y se creó una nueva revisión de borrado.',$semantic);
        }

        $newContent=trim((string)($parsed['new_content']??''));
        if($newContent==='') throw new RuntimeException('Memory update requires new content');

        $currentText='';
        if(is_array($current)&&is_string($current['content']??null)) $currentText=trim((string)$current['content']);
        elseif(is_string($current)) $currentText=trim($current);

        if(($parsed['mode']??'replace')==='append'&&$currentText!==''){
            $newContent=rtrim($currentText)."\n\n".$newContent;
        }

        if(is_array($current)){
            // Preserve the complete canonical structure for both modern and
            // legacy JSON memories. Only the knowledge payload changes.
            $next=$current;
            $next['content']=$newContent;
            $history=is_array($next['mutation_history']??null)?$next['mutation_history']:[];
            $history[]=[
                'at'=>$now,
                'action'=>(string)($parsed['mode']??'replace'),
                'previous_storage_hash'=>$previousHash,
            ];
            $next['mutation_history']=array_slice($history,-50);
            $next['lifecycle']=['status'=>'active','updated_at'=>$now];
        }else{
            $next=$newContent;
        }

        $result=$this->library->updateAs(
            $actor,$ref,$next,$format,
            (string)($metadata['temperature']??'hot'),
            (string)($metadata['cognitive_layer']??'40-semantic'),
            (string)($metadata['scope']??'user'),
            (string)($metadata['maturity']??'confirmed')
        );

        $semantic=null;
        if(is_array($next)&&is_string($next['retrieval']['question']??null)){
            try{
                $retrievalQuestion=(string)$next['retrieval']['question'];
                $knowledge=new KnowledgeService($this->library);
                $mirror=$knowledge->capture(
                    'librarian',$retrievalQuestion,$newContent,'text',0.95,'verified',
                    [['source_type'=>'user','reference'=>$ref,'note'=>'Owner-updated canonical memory']],
                    (string)($next['classification']['freshness_class']??'stable'),
                    null,'reuse-unless-stale',[$ref]
                );
                $knowledgeRef=(string)($mirror['logical_ref']??$next['retrieval']['knowledge_ref']??'');
                if($this->embeddingProvider!==null&&$knowledgeRef!==''){
                    $semantic=(new SemanticIndexService($this->library))->indexOne($this->embeddingProvider,$knowledgeRef,'librarian');
                }
            }catch(Throwable $e){$semantic=['error'=>self::safeError($e)];}
        }

        return self::result($ref,$result,'update','Memoria actualizada y versionada correctamente.',$semantic);
    }

    private function resolveTarget(string $actor,string $target): array
    {
        $target=trim($target," \t\n\r\0\x0B\"'");
        if(str_starts_with($target,'memory://user/')){
            try{$this->library->readAs($actor,$target);return ['status'=>'resolved','logical_ref'=>$target];}
            catch(Throwable){return ['status'=>'not-found','candidates'=>[]];}
        }

        $needle=self::normalize($target);
        if($needle==='') return ['status'=>'not-found','candidates'=>[]];
        $matches=[];
        foreach($this->library->listAs($actor) as $entry){
            foreach($entry['logical_refs']??[] as $ref){
                if(!is_string($ref)||!str_starts_with($ref,'memory://user/')) continue;
                try{$stored=$this->library->readAs($actor,$ref);}catch(Throwable){continue;}
                $content=$stored['payload']['content']??null;
                if(is_array($content)&&($content['lifecycle']['status']??null)==='deleted') continue;
                $title=is_array($content)?(string)($content['title']??''):'';
                $encoded=is_string($content)?$content:json_encode($content,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                $score=0;
                if(self::normalize($ref)===$needle) $score=100;
                elseif(self::normalize($title)===$needle&&$title!=='') $score=95;
                elseif(str_contains(self::normalize($ref),$needle)) $score=80;
                elseif($title!==''&&str_contains(self::normalize($title),$needle)) $score=75;
                elseif(is_string($encoded)&&str_contains(self::normalize($encoded),$needle)) $score=50;
                if($score>0) $matches[]=['logical_ref'=>$ref,'title'=>$title,'score'=>$score];
            }
        }
        usort($matches,static fn(array $a,array $b):int=>$b['score']<=>$a['score']?:$a['logical_ref']<=>$b['logical_ref']);
        if($matches===[]) return ['status'=>'not-found','candidates'=>[]];
        if(count($matches)>1&&$matches[0]['score']===$matches[1]['score']){
            return ['status'=>'ambiguous','candidates'=>array_slice($matches,0,8)];
        }
        return ['status'=>'resolved','logical_ref'=>$matches[0]['logical_ref'],'candidates'=>array_slice($matches,0,8)];
    }

    private static function parse(string $text): array
    {
        $text=trim($text);
        $delete=preg_match('/^(?:por\s+favor\s+)?(?:elimina|borra|retira|delete|remove)\b/iu',$text)===1;
        $append=preg_match('/^(?:por\s+favor\s+)?(?:agrega|añade|anade|incorpora|append|add)\b/iu',$text)===1
            || preg_match('/\b(?:y\s+)?(?:agrega|añade|anade|incorpora|append|add)\b/iu',$text)===1;
        $action=$delete?'delete':'update';
        $mode=$delete?'delete':($append?'append':'replace');

        $contextPattern='(?:ese|este|esa|esta|el|la)\\s+(?:conocimiento|concepto|memoria|recuerdo|archivo|knowledge|concept|memory|file)';
        $target=null;
        $newContent=null;

        if(preg_match('#(memory://user/[a-z0-9][a-z0-9._/-]*)#i',$text,$m)===1){
            $target=$m[1];
        }elseif(preg_match('/\\b'.$contextPattern.'\\b/iu',$text)===1){
            $target='@context';
        }

        if(!$delete){
            if($append&&preg_match('/\\b(?:agrega|añade|anade|incorpora|append|add)\\b\\s*(?:esto|this)?\\s*[:=,-]?\\s*(.+)$/isu',$text,$m)===1){
                $newContent=trim($m[1]);
            }elseif(preg_match('/\\s+(?:con|with|para\\s+que\\s+(?:diga|contenga|sea)|so\\s+it\\s+(?:says|contains))\\s*[:=-]?\\s+(.+)$/isu',$text,$m)===1){
                $newContent=trim($m[1]);
            }elseif(str_contains($text,'memory://user/')&&preg_match('/\\s*[:=]\\s*(.+)$/su',$text,$m)===1){
                $newContent=trim($m[1]);
            }
        }

        if($target===null){
            $body=preg_replace(
                '/^(?:por\\s+favor\\s+)?(?:actualiza|modifica|edita|corrige|cambia|agrega|añade|anade|incorpora|elimina|borra|retira|update|modify|edit|correct|append|add|delete|remove)\\s+(?:(?:la|el|the)\\s+)?(?:(?:memoria|recuerdo|archivo|conocimiento|concepto|memory|file|knowledge|concept)\\s+)?(?:de\\s+|sobre\\s+|llamad[oa]\\s+)?/iu',
                '',
                $text
            )??$text;
            $target=$body;
            if(!$delete){
                $parts=preg_split('/\\s+(?:con|with|para\\s+que\\s+(?:diga|contenga|sea)|so\\s+it\\s+(?:says|contains)|y\\s+(?:agrega|añade|anade|incorpora))\\s*[:=-]?\\s+/iu',$body,2);
                if(is_array($parts)&&count($parts)===2) $target=trim($parts[0]);
            }
        }

        return [
            'action'=>$action,
            'mode'=>$mode,
            'target'=>trim((string)$target," \t\n\r\0\x0B\"'.,:;"),
            'new_content'=>$newContent,
        ];
    }

    private static function result(string $ref,array $stored,string $action,string $message,mixed $semantic): array
    {
        return [
            'found'=>true,'reusable'=>false,'decision'=>'memory-'.$action,
            'route'=>'memory-mutation','provider_called'=>false,
            'logical_ref'=>$ref,
            'canonical_memory_ref'=>$ref,
            'answer'=>['format'=>'text','value'=>$message."\n".$ref."\nRevisión ".(int)($stored['revision']??0).'.'],
            'stored'=>true,
            'storage'=>[
                'logical_ref'=>$ref,'object_id'=>$stored['object_id']??null,
                'storage_hash'=>$stored['storage_hash']??null,
                'previous_storage_hash'=>$stored['previous_storage_hash']??null,
                'revision'=>(int)($stored['revision']??0),
                'semantic_index'=>$semantic,
            ],
            'mutation'=>['action'=>$action,'status'=>'completed','versioned'=>true],
            'context_used'=>['memory'=>true,'canonical'=>true,'logical_ref'=>$ref],
        ];
    }

    private static function normalize(string $value): string
    {
        $value=preg_replace('/\s+/u',' ',trim($value))??trim($value);
        return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);
    }

    private static function safeError(Throwable $e): string
    {
        $message=trim($e->getMessage());
        return $message===''?'operation failed':substr($message,0,240);
    }
}
