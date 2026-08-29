<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

use MCMA\Core\Library;
use MCMA\Core\MultiUser\MultiUserService;
use MCMA\Core\Storage\PrefixStorageAdapter;
use MCMA\Core\Storage\StorageAdapter;
use RuntimeException;

final class ApiKeyService
{
    private const PREFIX='system/api-keys';
    private const REF='memory://api/keys';

    public function __construct(
        private readonly StorageAdapter $rootStorage,
        private readonly MultiUserService $users,
        private readonly string $pepper
    ) {
        if(strlen($this->pepper)<32) throw new RuntimeException('MCMA API key pepper must be at least 32 bytes');
    }

    public function create(string $userId,string $label='API key'): array
    {
        $this->users->resolveUserIdForService($userId,false);
        $label=trim($label);
        if($label===''||strlen($label)>128) throw new RuntimeException('API key label is required');

        $secret=self::b64u(random_bytes(32));
        $token='mcma_api_'.$secret;
        $hash=$this->hashToken($token);
        $keyId='key_'.substr($hash,0,24);
        $record=[
            'key_id'=>$keyId,
            'user_id'=>$userId,
            'label'=>$label,
            'token_hash'=>$hash,
            'status'=>'active',
            'created_at'=>self::now(),
            'revoked_at'=>null,
        ];

        $library=$this->library();
        $library->mutateJson(self::REF,function(mixed $current)use($hash,$record):array{
            if(!is_array($current)||array_is_list($current)) throw new RuntimeException('Malformed API key registry');
            $keys=$current['keys']??[];
            if(!is_array($keys)||($keys!==[]&&array_is_list($keys))) throw new RuntimeException('Malformed API key map');
            if(isset($keys[$hash])) throw new RuntimeException('API key collision');
            $keys[$hash]=$record;
            $current['keys']=$keys;
            $current['updated_at']=self::now();
            return $current;
        },'owner');

        return [
            'key_id'=>$keyId,
            'user_id'=>$userId,
            'label'=>$label,
            'token'=>$token,
            'created_at'=>$record['created_at'],
        ];
    }

    public function authenticate(string $token): array
    {
        if(!str_starts_with($token,'mcma_api_')||strlen($token)<50||strlen($token)>256){
            throw new BillingException('Invalid API token','invalid_api_token',401);
        }
        $hash=$this->hashToken($token);
        $content=$this->content();
        $record=$content['keys'][$hash]??null;
        if(!is_array($record)||($record['token_hash']??null)!==$hash||($record['status']??null)!=='active'){
            throw new BillingException('Invalid or revoked API token','invalid_api_token',401);
        }
        $this->users->resolveUserIdForService((string)$record['user_id'],true);
        return $this->publicRecord($record);
    }

    public function list(string $userId): array
    {
        $this->users->resolveUserIdForService($userId,false);
        $out=[];
        foreach(($this->content()['keys']??[]) as $record){
            if(is_array($record)&&($record['user_id']??null)===$userId) $out[]=$this->publicRecord($record);
        }
        usort($out,static fn(array $a,array $b):int=>strcmp((string)$a['created_at'],(string)$b['created_at']));
        return $out;
    }

    public function revoke(string $userId,string $keyId): array
    {
        if(!preg_match('/^key_[0-9a-f]{24}$/',$keyId)) throw new RuntimeException('Invalid API key id');
        $library=$this->library();
        $revoked=null;
        $library->mutateJson(self::REF,function(mixed $current)use($userId,$keyId,&$revoked):array{
            if(!is_array($current)||array_is_list($current)) throw new RuntimeException('Malformed API key registry');
            $keys=$current['keys']??[];
            foreach($keys as $hash=>$record){
                if(!is_array($record)||($record['key_id']??null)!==$keyId||($record['user_id']??null)!==$userId) continue;
                $record['status']='revoked';
                $record['revoked_at']=self::now();
                $keys[$hash]=$record;
                $revoked=$record;
                break;
            }
            if($revoked===null) throw new RuntimeException('API key not found');
            $current['keys']=$keys;$current['updated_at']=self::now();return $current;
        },'owner');
        return $this->publicRecord($revoked);
    }

    private function content(): array
    {
        $content=$this->library()->read(self::REF)['payload']['content']??null;
        if(!is_array($content)||array_is_list($content)) throw new RuntimeException('Malformed API key registry');
        return $content;
    }

    private function library(): Library
    {
        $storage=new PrefixStorageAdapter($this->rootStorage,self::PREFIX);
        if(!$storage->exists('manifest.mcma')){
            try{
                $lib=Library::init($storage,'private');
                $lib->initializeAccessControl(null,'owner');
                $lib->writeAs('owner',self::REF,[
                    'version'=>'1.0','keys'=>[],'updated_at'=>self::now(),
                ],'json','warm','00-system','system','confirmed');
                return $lib;
            }catch(RuntimeException $e){
                $m=strtolower($e->getMessage());
                if(!str_contains($m,'already')&&!str_contains($m,'non-empty')) throw $e;
            }
        }
        $lib=Library::open($storage);
        foreach($lib->list() as $entry) if(in_array(self::REF,$entry['logical_refs']??[],true)) return $lib;
        $lib->writeAs('owner',self::REF,['version'=>'1.0','keys'=>[],'updated_at'=>self::now()],'json','warm','00-system','system','confirmed');
        return $lib;
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256',$token,$this->pepper);
    }

    private function publicRecord(array $record): array
    {
        return [
            'key_id'=>(string)($record['key_id']??''),
            'user_id'=>(string)($record['user_id']??''),
            'label'=>(string)($record['label']??''),
            'status'=>(string)($record['status']??''),
            'created_at'=>(string)($record['created_at']??''),
            'revoked_at'=>$record['revoked_at']??null,
        ];
    }

    private static function b64u(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');
    }

    private static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
}
