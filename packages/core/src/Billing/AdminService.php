<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

use MCMA\Core\Library;
use MCMA\Core\MultiUser\AuthenticatedIdentity;
use MCMA\Core\MultiUser\MultiUserService;
use MCMA\Core\Storage\PrefixStorageAdapter;
use MCMA\Core\Storage\StorageAdapter;
use RuntimeException;

final class AdminService
{
    private const PREFIX='system/admin';
    private const ADMINS_REF='memory://admin/administrators';

    public function __construct(
        private readonly StorageAdapter $rootStorage,
        private readonly MultiUserService $users,
        private readonly BillingService $billing,
        private readonly BillingCatalog $catalog,
        private readonly string $identityPepper
    ) {
        if(strlen($this->identityPepper)<32) throw new RuntimeException('Admin identity pepper must be at least 32 bytes');
    }

    public function bootstrapRoot(string $issuer,string $subject): array
    {
        $identity=AuthenticatedIdentity::fromSubject($issuer,$subject,$this->identityPepper);
        $library=$this->library();
        $existing=$this->adminContent();
        $admins=$existing['admins']??[];
        if(is_array($admins)&&$admins!==[]){
            $record=$admins[$identity->fingerprint()]??null;
            if(is_array($record)&&($record['role']??null)==='superadmin'&&($record['status']??null)==='active'){
                return ['initialized'=>true,'role'=>'superadmin','existing'=>true];
            }
            throw new RuntimeException('Root superadmin is already configured');
        }

        $library->mutateJson(self::ADMINS_REF,function(mixed $current)use($identity):array{
            if(!is_array($current)||array_is_list($current)) throw new RuntimeException('Malformed admin registry');
            $admins=$current['admins']??[];
            if(!is_array($admins)||($admins!==[]&&array_is_list($admins))) throw new RuntimeException('Malformed admin map');
            if($admins!==[]){
                foreach($admins as $record){
                    if(is_array($record)&&($record['identity_fingerprint']??null)===$identity->fingerprint()) return $current;
                }
                throw new RuntimeException('Root superadmin is already configured');
            }
            $admins[$identity->fingerprint()]=[
                'identity_fingerprint'=>$identity->fingerprint(),
                'role'=>'superadmin',
                'status'=>'active',
                'created_at'=>self::now(),
            ];
            $current['admins']=$admins;$current['updated_at']=self::now();return $current;
        },'owner');

        return ['initialized'=>true,'role'=>'superadmin'];
    }

    public function assertSuperAdmin(string $issuer,string $subject): void
    {
        $identity=AuthenticatedIdentity::fromSubject($issuer,$subject,$this->identityPepper);
        $content=$this->adminContent();
        $record=$content['admins'][$identity->fingerprint()]??null;
        if(!is_array($record)||($record['role']??null)!=='superadmin'||($record['status']??null)!=='active'){
            throw new BillingException('Superadmin authorization required','admin_required',403);
        }
    }

    public function addSuperAdmin(string $actorIssuer,string $actorSubject,string $newIssuer,string $newSubject): array
    {
        $this->assertSuperAdmin($actorIssuer,$actorSubject);
        $new=AuthenticatedIdentity::fromSubject($newIssuer,$newSubject,$this->identityPepper);
        $library=$this->library();
        $library->mutateJson(self::ADMINS_REF,function(mixed $current)use($new):array{
            if(!is_array($current)||array_is_list($current)) throw new RuntimeException('Malformed admin registry');
            $admins=$current['admins']??[];
            $admins[$new->fingerprint()]=[
                'identity_fingerprint'=>$new->fingerprint(),
                'role'=>'superadmin','status'=>'active','created_at'=>self::now(),
            ];
            $current['admins']=$admins;$current['updated_at']=self::now();return $current;
        },'owner');
        $this->audit($actorIssuer,$actorSubject,'admin.add',['new_admin_fingerprint'=>$new->fingerprint()]);
        return ['role'=>'superadmin','status'=>'active'];
    }

    public function listUsers(string $issuer,string $subject): array
    {
        $this->assertSuperAdmin($issuer,$subject);
        $out=[];
        foreach($this->users->listUsers() as $record){
            $userId=(string)$record['user_id'];
            $library=$this->users->resolveUserIdForService($userId,false);
            $out[]=$record+[
                'billing'=>$this->billing->summary($library),
                'totals'=>$this->billing->totals($library),
            ];
        }
        return $out;
    }

    public function adjustCredits(string $issuer,string $subject,string $userId,int $units,string $reason): array
    {
        $this->assertSuperAdmin($issuer,$subject);
        if($units===0) throw new RuntimeException('Credit adjustment cannot be zero');
        $library=$this->users->resolveUserIdForService($userId,false);
        $ledger=$this->billing->adjustCredits($library,$units,$reason,'admin-adjustment');
        $this->audit($issuer,$subject,'credits.adjust',['user_id'=>$userId,'units'=>$units,'reason'=>$reason]);
        return ['billing'=>$this->billing->summary($library),'ledger'=>$ledger];
    }

    public function setPlan(string $issuer,string $subject,string $userId,string $planId): array
    {
        $this->assertSuperAdmin($issuer,$subject);
        $library=$this->users->resolveUserIdForService($userId,false);
        $account=$this->billing->setPlan($library,$planId);
        $this->audit($issuer,$subject,'user.plan',['user_id'=>$userId,'plan_id'=>$planId]);
        return $account;
    }

    public function setServiceStatus(string $issuer,string $subject,string $userId,string $status): array
    {
        $this->assertSuperAdmin($issuer,$subject);
        $library=$this->users->resolveUserIdForService($userId,false);
        $account=$this->billing->setServiceStatus($library,$status);
        $this->audit($issuer,$subject,'user.service_status',['user_id'=>$userId,'status'=>$status]);
        return $account;
    }

    public function setAccessStatus(string $issuer,string $subject,string $userId,string $status): array
    {
        $this->assertSuperAdmin($issuer,$subject);
        if(!in_array($status,['active','disabled'],true)) throw new RuntimeException('Invalid user access status');
        $record=$this->users->setUserStatus($userId,$status);
        $this->audit($issuer,$subject,'user.access_status',['user_id'=>$userId,'status'=>$status]);
        return $record;
    }

    public function setPricing(string $issuer,string $subject,string $providerId,array $rates): array
    {
        $this->assertSuperAdmin($issuer,$subject);
        $entry=$this->catalog->setPricing($providerId,$rates);
        $this->audit($issuer,$subject,'pricing.set',['provider_id'=>$providerId,'version'=>$entry['version']]);
        return $entry;
    }

    public function setPlanDefinition(string $issuer,string $subject,string $planId,array $definition): array
    {
        $this->assertSuperAdmin($issuer,$subject);
        $plan=$this->catalog->setPlan($planId,$definition);
        $this->audit($issuer,$subject,'plan.set',['plan_id'=>$planId]);
        return $plan;
    }

    public function recordPayment(
        string $issuer,
        string $subject,
        string $userId,
        string $providerId,
        array $verifiedPayment
    ): array {
        $this->assertSuperAdmin($issuer,$subject);
        $library=$this->users->resolveUserIdForService($userId,false);
        $provider=new RecordedPaymentProvider($providerId);
        $result=$this->billing->recordPayment($library,$provider,$verifiedPayment);
        $this->audit($issuer,$subject,'payment.record',[
            'user_id'=>$userId,
            'provider'=>$providerId,
            'provider_payment_id'=>(string)($result['payment']['provider_payment_id']??''),
            'credit_units'=>(int)($result['payment']['credit_units']??0),
        ]);
        return ['payment'=>$result['payment'],'billing'=>$this->billing->summary($library)];
    }

    public function billingForUser(string $issuer,string $subject,string $userId): array
    {
        $this->assertSuperAdmin($issuer,$subject);
        $library=$this->users->resolveUserIdForService($userId,false);
        return [
            'user'=>$this->users->infoUserId($userId),
            'billing'=>$this->billing->summary($library),
            'totals'=>$this->billing->totals($library),
        ];
    }

    private function audit(string $issuer,string $subject,string $action,array $details): void
    {
        $identity=AuthenticatedIdentity::fromSubject($issuer,$subject,$this->identityPepper);
        $date=gmdate('Y/m/d');
        $ref='memory://admin/audit/'.$date;
        $library=$this->library();
        if(!$this->hasRef($library,$ref)){
            try{
                $library->writeAs('owner',$ref,[
                    'version'=>'1.0','events'=>[],
                ],'json','warm','00-system','system','confirmed');
            }catch(RuntimeException $e){
                if(!str_contains(strtolower($e->getMessage()),'already exists')) throw $e;
            }
        }
        $library->mutateJson($ref,function(mixed $current)use($identity,$action,$details):array{
            if(!is_array($current)||array_is_list($current)) throw new RuntimeException('Malformed admin audit');
            $events=$current['events']??[];
            $events[]=[
                'event_id'=>'audit_'.bin2hex(random_bytes(16)),
                'occurred_at'=>self::now(),
                'admin_fingerprint'=>$identity->fingerprint(),
                'action'=>$action,
                'details'=>$details,
            ];
            $current['events']=$events;return $current;
        },'owner');
    }

    private function adminContent(): array
    {
        $content=$this->library()->read(self::ADMINS_REF)['payload']['content']??null;
        if(!is_array($content)||array_is_list($content)) throw new RuntimeException('Malformed admin registry');
        return $content;
    }

    private function library(): Library
    {
        $storage=new PrefixStorageAdapter($this->rootStorage,self::PREFIX);
        if(!$storage->exists('manifest.mcma')){
            try{
                $lib=Library::init($storage,'private');
                $lib->initializeAccessControl(null,'owner');
                $lib->writeAs('owner',self::ADMINS_REF,[
                    'version'=>'1.0','admins'=>[],'updated_at'=>self::now(),
                ],'json','warm','00-system','system','confirmed');
                return $lib;
            }catch(RuntimeException $e){
                $m=strtolower($e->getMessage());
                if(!str_contains($m,'already')&&!str_contains($m,'non-empty')) throw $e;
            }
        }
        $lib=Library::open($storage);
        if(!$this->hasRef($lib,self::ADMINS_REF)){
            $lib->writeAs('owner',self::ADMINS_REF,['version'=>'1.0','admins'=>[],'updated_at'=>self::now()],'json','warm','00-system','system','confirmed');
        }
        return $lib;
    }

    private function hasRef(Library $library,string $ref): bool
    {
        foreach($library->list() as $entry) if(in_array($ref,$entry['logical_refs']??[],true)) return true;
        return false;
    }

    private static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
}
