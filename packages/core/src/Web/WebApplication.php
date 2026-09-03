<?php
declare(strict_types=1);

namespace MCMA\Core\Web;

use MCMA\Core\Agent\Librarian;
use MCMA\Core\Ask\AskService;
use MCMA\Core\Billing\AdminService;
use MCMA\Core\Billing\ApiKeyService;
use MCMA\Core\Billing\BillableAskService;
use MCMA\Core\Billing\BillableExplicitMemoryService;
use MCMA\Core\Billing\BillableInteractionApprovalService;
use MCMA\Core\Billing\BillableMemoryMutationService;
use MCMA\Core\Billing\BillingException;
use MCMA\Core\Billing\BillingService;
use MCMA\Core\Billing\MeteredEmbeddingProvider;
use MCMA\Core\Billing\MeteredGenerationProvider;
use MCMA\Core\Billing\UsageCollector;
use MCMA\Core\Billing\StripeCheckoutService;
use MCMA\Core\Cli\ProviderFactory;
use MCMA\Core\Context\BroadMemoryRecallBuilder;
use MCMA\Core\Context\ContextTraceService;
use MCMA\Core\Context\ConversationContextBuilder;
use MCMA\Core\Context\MultiMemoryContextBuilder;
use MCMA\Core\Interaction\InteractionArchiveService;
use MCMA\Core\Interaction\InteractionCatalogService;
use MCMA\Core\Knowledge\KnowledgeService;
use MCMA\Core\Library;
use MCMA\Core\Memory\ExplicitMemoryService;
use MCMA\Core\Memory\MemoryMutationService;
use MCMA\Core\MultiUser\MultiUserService;
use MCMA\Core\Semantic\SemanticIndexService;
use RuntimeException;
use Throwable;

final class WebApplication
{
    private const STATE_COOKIE='mcma_oidc_state';
    private const SESSION_COOKIE='mcma_session';

    public function __construct(
        private readonly MultiUserService $users,
        private readonly OidcClient $oidc,
        private readonly EncryptedCookie $stateCookieCipher,
        private readonly EncryptedCookie $sessionCookieCipher,
        private readonly ProviderFactory $providers,
        private readonly string $publicOrigin,
        private readonly bool $autoRegister,
        private readonly bool $selfRegister,
        private readonly array $providerOptions,
        private readonly int $sessionTtl=28800,
        private readonly ?BillingService $billing=null,
        private readonly ?ApiKeyService $apiKeys=null,
        private readonly ?AdminService $admin=null,
        private readonly bool $billingEnabled=false,
        private readonly int $billingMaxOutputTokens=1024,
        private readonly ?StripeCheckoutService $stripe=null,
        private readonly string $basePath=''
    ){
        if(!preg_match('#^https://[^/]+$#',$this->publicOrigin)) throw new RuntimeException('MCMA web public origin must be an HTTPS origin without a path');
        if($this->basePath!==''&&(!preg_match('#^/[A-Za-z0-9._/-]+$#',$this->basePath)||str_ends_with($this->basePath,'/')||str_contains($this->basePath,'..'))) {
            throw new RuntimeException('MCMA web base path must be empty or an absolute path without trailing slash');
        }
        if($this->sessionTtl<300||$this->sessionTtl>604800) throw new RuntimeException('MCMA web session TTL must be between 300 and 604800 seconds');
        if($this->billingMaxOutputTokens<1||$this->billingMaxOutputTokens>200000) throw new RuntimeException('Invalid billing max output tokens');
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        try{$response=$this->dispatch($request);}
        catch(WebException $e){$response=HttpResponse::json(['ok'=>false,'error'=>$e->error(),'message'=>$e->getMessage()],$e->status());}
        catch(BillingException $e){$response=HttpResponse::json(['ok'=>false,'error'=>$e->reason(),'message'=>$e->getMessage()],$e->httpStatus());}
        catch(Throwable $e){
            error_log('MCMA web error: '.$e->getMessage());
            $response=HttpResponse::json(['ok'=>false,'error'=>'internal_error','message'=>'Internal server error'],500);
        }
        return $this->secure($response);
    }

    private function dispatch(HttpRequest $request): HttpResponse
    {
        $method=$request->method();
        $path=rtrim($request->path(),'/')?:'/';

        $home=$this->basePath===''?'/':$this->basePath;
        $loginPath=$home==='/'?'/login':$home.'/login';
        $callbackPath=$home==='/'?'/callback':$home.'/callback';
        $logoutPath=$home==='/'?'/logout':$home.'/logout';

        if($method==='GET'&&$path===$home) return HttpResponse::redirect($loginPath,[],302);

        if($method==='GET'&&$path==='/mcma/v1/health'){
            return HttpResponse::json([
                'ok'=>true,'service'=>'mcma-web','version'=>'1.0','multi_user'=>true,
                'billing_enabled'=>$this->billingEnabled,'api_keys_enabled'=>$this->apiKeys!==null,
                'stripe_enabled'=>$this->billingEnabled&&$this->stripe!==null,
            ]);
        }
        if($method==='GET'&&$path===$loginPath) return $this->login();
        if($method==='GET'&&$path===$callbackPath) return $this->callback($request);
        if($method==='POST'&&$path===$logoutPath){
            $this->assertOrigin($request);
            return HttpResponse::redirect($home,['set-cookie'=>[$this->clearCookie(self::SESSION_COOKIE)]],303);
        }

        if($method==='GET'&&$path==='/mcma/v1/me'){
            $principal=$this->requestPrincipal($request);
            $payload=['ok'=>true,'user'=>$this->users->infoUserId($principal['user_id'])];
            if(($principal['kind']??null)==='web'&&isset($principal['identity'])&&is_array($principal['identity'])){
                $payload['identity']=$principal['identity'];
            }
            if($this->billing!==null) $payload['billing']=$this->billing->summary($principal['library']);
            return HttpResponse::json($payload);
        }

        if($method==='POST'&&$path==='/mcma/v1/register'){
            $this->assertOrigin($request);
            if(!$this->selfRegister&&!$this->autoRegister) throw new WebException(403,'self_registration_disabled','Self-registration is disabled');
            [$issuer,$subject]=$this->sessionIdentity($request);
            $user=$this->users->register($issuer,$subject);
            if($this->billing!==null){
                $library=$this->users->resolve($issuer,$subject);
                $this->billing->ensureAccount($library);
            }
            return HttpResponse::json(['ok'=>true,'user'=>$user],201);
        }

        if($method==='GET'&&$path==='/mcma/v1/billing'){
            $principal=$this->requestPrincipal($request);
            if($this->billing===null) throw new WebException(503,'billing_unavailable','Billing service is not configured');
            return HttpResponse::json([
                'ok'=>true,
                'billing'=>$this->billing->summary($principal['library']),
                'totals'=>$this->billing->totals($principal['library']),
            ]);
        }

        if($method==='POST'&&$path==='/mcma/v1/billing/stripe/webhook'){
            if($this->stripe===null) throw new WebException(503,'stripe_unavailable','Stripe is not configured');
            $signature=$request->header('stripe-signature')??'';
            return HttpResponse::json(['ok'=>true,'stripe'=>$this->stripe->handleWebhook($request->body(),$signature)]);
        }

        if($method==='GET'&&$path==='/mcma/v1/billing/stripe/packages'){
            if(!$this->billingEnabled) throw new WebException(503,'billing_disabled','Billing is disabled');
            if($this->stripe===null) throw new WebException(503,'stripe_unavailable','Stripe is not configured');
            $this->sessionPrincipal($request);
            return HttpResponse::json(['ok'=>true,'packages'=>$this->stripe->packages()]);
        }

        if($method==='POST'&&$path==='/mcma/v1/billing/stripe/checkout'){
            if(!$this->billingEnabled) throw new WebException(503,'billing_disabled','Billing is disabled');
            if($this->stripe===null) throw new WebException(503,'stripe_unavailable','Stripe is not configured');
            $this->assertOrigin($request);
            $principal=$this->sessionPrincipal($request);
            $input=$request->json(16384);
            $packageId=(string)($input['package_id']??'');
            return HttpResponse::json(['ok'=>true,'checkout'=>$this->stripe->createCheckout($principal['user_id'],$packageId)],201);
        }

        if($method==='GET'&&$path==='/mcma/v1/billing/usage'){
            $principal=$this->requestPrincipal($request);
            if($this->billing===null) throw new WebException(503,'billing_unavailable','Billing service is not configured');
            $date=$request->query('date')??gmdate('Y-m-d');
            return HttpResponse::json(['ok'=>true,'usage'=>$this->billing->dailyUsage($principal['library'],$date)]);
        }

        if($path==='/mcma/v1/api-keys'){
            if($this->apiKeys===null) throw new WebException(503,'api_keys_unavailable','API keys are not configured');
            $principal=$this->sessionPrincipal($request);
            if($method==='GET') return HttpResponse::json(['ok'=>true,'keys'=>$this->apiKeys->list($principal['user_id'])]);
            if($method==='POST'){
                $this->assertOrigin($request);
                $input=$request->json(16384);
                if($this->billing!==null){
                    $summary=$this->billing->summary($principal['library']);
                    if(!(bool)($summary['plan']['api_enabled']??false)) throw new BillingException('API access is not enabled for this plan','api_not_allowed',403);
                }
                $created=$this->apiKeys->create($principal['user_id'],(string)($input['label']??'API key'));
                return HttpResponse::json(['ok'=>true,'key'=>$created],201);
            }
        }

        if($method==='DELETE'&&preg_match('#^/mcma/v1/api-keys/(key_[0-9a-f]{24})$#',$path,$m)){
            $this->assertOrigin($request);
            if($this->apiKeys===null) throw new WebException(503,'api_keys_unavailable','API keys are not configured');
            $principal=$this->sessionPrincipal($request);
            return HttpResponse::json(['ok'=>true,'key'=>$this->apiKeys->revoke($principal['user_id'],$m[1])]);
        }

        if($method==='GET'&&$path==='/mcma/v1/context'){
            $principal=$this->sessionPrincipal($request);
            $context=new ContextTraceService($principal['library']);
            return HttpResponse::json([
                'ok'=>true,
                'context'=>$context->snapshot((float)($this->providerOptions['min-confidence']??0.75)),
            ]);
        }

        if($method==='GET'&&$path==='/mcma/v1/conversations'){
            $principal=$this->sessionPrincipal($request);
            return HttpResponse::json([
                'ok'=>true,
                'archive'=>(new InteractionArchiveService($principal['library']))->conversations('owner'),
            ]);
        }

        if($method==='GET'&&preg_match('#^/mcma/v1/requests/(req_[0-9a-f]{32})$#',$path,$m)){
            $principal=$this->sessionPrincipal($request);
            $conversationId=trim((string)($request->query('conversation_id')??''));
            if(!preg_match('/^conv_[0-9a-f]{32}$/',$conversationId)){
                throw new WebException(400,'invalid_conversation_id','conversation_id must match conv_<32 lowercase hex>');
            }
            $interaction=(new InteractionArchiveService($principal['library']))->interactionByRequestId('owner',$conversationId,$m[1]);
            if($interaction===null){
                return HttpResponse::json([
                    'ok'=>true,'status'=>'pending','request_id'=>$m[1],'conversation_id'=>$conversationId,
                ],202);
            }
            return HttpResponse::json([
                'ok'=>true,'status'=>'completed','request_id'=>$m[1],'conversation_id'=>$conversationId,
                'result'=>self::resultFromArchivedInteraction($interaction),
            ]);
        }

        if($method==='GET'&&preg_match('#^/mcma/v1/conversations/(conv_[0-9a-f]{32})$#',$path,$m)){
            $principal=$this->sessionPrincipal($request);
            try{
                $conversation=(new InteractionArchiveService($principal['library']))->conversation('owner',$m[1]);
            }catch(RuntimeException $e){
                if(str_starts_with($e->getMessage(),'Conversation not found:')){
                    throw new WebException(404,'conversation_not_found','Conversation not found');
                }
                throw $e;
            }
            return HttpResponse::json(['ok'=>true,'archive'=>$conversation]);
        }

        if($method==='GET'&&$path==='/mcma/v1/library-tree'){
            $principal=$this->sessionPrincipal($request);
            return HttpResponse::json([
                'ok'=>true,
                'library'=>(new InteractionArchiveService($principal['library']))->libraryTree('owner'),
            ]);
        }

        if($method==='GET'&&$path==='/mcma/v1/library-object'){
            $principal=$this->sessionPrincipal($request);
            $logicalRef=trim((string)($request->query('ref')??''));
            if($logicalRef===''||strlen($logicalRef)>2048){
                throw new WebException(400,'invalid_library_ref','A library memory reference is required');
            }

            if(str_starts_with($logicalRef,'memory://interactions/')){
                try{
                    $detail=(new InteractionArchiveService($principal['library']))->read('owner',$logicalRef);
                }catch(Throwable $e){
                    if(str_contains($e->getMessage(),'Memory not found:')) throw new WebException(404,'library_object_not_found','Library object not found');
                    throw $e;
                }
                return HttpResponse::json(['ok'=>true,'object'=>['kind'=>'interaction']+$detail]);
            }

            $kind=str_starts_with($logicalRef,'memory://user/')?'memory':
                (preg_match('#^memory://knowledge/q-[0-9a-f]{64}$#',$logicalRef)?'knowledge':null);
            if($kind===null){
                throw new WebException(400,'invalid_library_ref','Only user memory, interactions and knowledge are browsable here');
            }

            try{$stored=$principal['library']->readAs('owner',$logicalRef);}
            catch(Throwable $e){
                if(str_contains($e->getMessage(),'Memory not found:')) throw new WebException(404,'library_object_not_found','Library object not found');
                throw $e;
            }
            return HttpResponse::json([
                'ok'=>true,
                'object'=>[
                    'kind'=>$kind,
                    'logical_ref'=>$logicalRef,
                    'object_id'=>$stored['object_id']??null,
                    'storage_hash'=>$stored['storage_hash']??null,
                    'metadata'=>$stored['payload']['metadata']??[],
                    'content'=>$stored['payload']['content']??null,
                    'ai_tokens_used'=>0,
                    'credit_units_charged'=>0,
                ],
            ]);
        }

        if($method==='POST'&&$path==='/mcma/v1/interaction-validation'){
            $this->assertOrigin($request);
            $principal=$this->requestPrincipal($request);
            $input=$request->json(16384);
            $logicalRef=trim((string)($input['ref']??''));
            $action=(string)($input['action']??'');
            if(!str_starts_with($logicalRef,'memory://interactions/')){
                throw new WebException(400,'invalid_interaction_ref','An interaction reference is required');
            }
            if(!in_array($action,['approve','discard'],true)){
                throw new WebException(400,'invalid_interaction_action','action must be approve or discard');
            }

            $embedding=$this->providers->embedding($this->providerOptions,true);
            $generator=$this->providers->generation($this->providerOptions);
            $validationRequestId='req_'.bin2hex(random_bytes(16));

            if($this->billingEnabled){
                if($this->billing===null) throw new WebException(503,'billing_unavailable','Billing is enabled but service is unavailable');
                $this->billing->ensureAccount($principal['library']);
                $result=(new BillableInteractionApprovalService(
                    $principal['library'],$this->billing,$embedding,$generator,$this->billingMaxOutputTokens
                ))->validate(
                    $validationRequestId,$principal['kind'],$logicalRef,$action,
                    array_filter(['api_key_id'=>$principal['api_key_id']??null],static fn($v)=>$v!==null)
                );
            }else{
                $catalog=$action==='approve'?new InteractionCatalogService($generator):null;
                $result=(new InteractionArchiveService($principal['library']))->validate(
                    'owner',$logicalRef,$action,$catalog,$embedding
                );
                $result['billing']=['credit_units_charged'=>0,'usage'=>['total_tokens'=>0]];
            }

            return HttpResponse::json(['ok'=>true,'validation'=>$result]);
        }

        if($method==='GET'&&$path==='/mcma/v1/memory-tree'){
            $principal=$this->sessionPrincipal($request);
            $tree=$principal['library']->treeAs('owner');
            $userTree=is_array($tree['user']??null)?$tree['user']:[];
            $total=0;
            foreach($principal['library']->listAs('owner') as $entry){
                foreach($entry['logical_refs']??[] as $ref){
                    if(is_string($ref)&&str_starts_with($ref,'memory://user/')) $total++;
                }
            }
            return HttpResponse::json([
                'ok'=>true,
                'memory'=>[
                    'root'=>'memory://user',
                    'tree'=>$userTree,
                    'total'=>$total,
                    'ai_tokens_used'=>0,
                    'credit_units_charged'=>0,
                ],
            ]);
        }

        if($method==='GET'&&$path==='/mcma/v1/memory-object'){
            $principal=$this->sessionPrincipal($request);
            $logicalRef=trim((string)($request->query('ref')??''));
            if($logicalRef===''||strlen($logicalRef)>2048||!str_starts_with($logicalRef,'memory://user/')){
                throw new WebException(400,'invalid_memory_ref','A memory://user/... reference is required');
            }

            try{
                $stored=$principal['library']->readAs('owner',$logicalRef);
            }catch(Throwable $e){
                if(str_contains($e->getMessage(),'Memory not found:')){
                    throw new WebException(404,'memory_not_found','Memory object not found');
                }
                throw $e;
            }

            $payload=$stored['payload']??null;
            if(!is_array($payload)) throw new WebException(500,'invalid_memory_object','Stored memory payload is malformed');
            $metadata=is_array($payload['metadata']??null)?$payload['metadata']:[];
            $content=$payload['content']??null;

            return HttpResponse::json([
                'ok'=>true,
                'memory'=>[
                    'logical_ref'=>$logicalRef,
                    'object_id'=>$stored['object_id']??null,
                    'storage_hash'=>$stored['storage_hash']??null,
                    'metadata'=>$metadata,
                    'content'=>$content,
                    'ai_tokens_used'=>0,
                    'credit_units_charged'=>0,
                ],
            ]);
        }

        if($method==='GET'&&$path==='/mcma/v1/memories'){
            $principal=$this->sessionPrincipal($request);
            $query=trim((string)($request->query('q')??''));
            if(strlen($query)>256) throw new WebException(400,'invalid_memory_query','Memory search query must be <= 256 bytes');

            $temperature=$request->query('temperature');
            if($temperature===''||$temperature==='all') $temperature=null;
            if($temperature!==null&&!in_array($temperature,['hot','warm','cold','frozen'],true)){
                throw new WebException(400,'invalid_memory_temperature','Invalid memory temperature filter');
            }

            $validation=$request->query('validation');
            if($validation===''||$validation==='all') $validation=null;
            if($validation!==null&&!in_array($validation,\MCMA\Core\Knowledge\KnowledgeRecord::VALIDATION_STATES,true)){
                throw new WebException(400,'invalid_memory_validation','Invalid memory validation filter');
            }

            $pageRaw=$request->query('page')??'1';
            $limitRaw=$request->query('limit')??'25';
            if(!ctype_digit($pageRaw)||!ctype_digit($limitRaw)){
                throw new WebException(400,'invalid_memory_pagination','Memory page and limit must be positive integers');
            }
            $page=(int)$pageRaw;$limit=(int)$limitRaw;
            if($page<1||$limit<1||$limit>100){
                throw new WebException(400,'invalid_memory_pagination','Memory page must be >= 1 and limit must be between 1 and 100');
            }

            $knowledge=new KnowledgeService($principal['library']);
            return HttpResponse::json([
                'ok'=>true,
                'memory'=>$knowledge->browse(
                    'owner',$query,$temperature,$validation,$page,$limit,
                    (float)($this->providerOptions['min-confidence']??0.75)
                ),
            ]);
        }

        if($method==='GET'&&preg_match('#^/mcma/v1/memories/([0-9a-f]{64})$#',$path,$m)){
            $principal=$this->sessionPrincipal($request);
            $knowledge=new KnowledgeService($principal['library']);
            return HttpResponse::json([
                'ok'=>true,
                'memory'=>$knowledge->inspectId(
                    'owner',$m[1],(float)($this->providerOptions['min-confidence']??0.75)
                ),
            ]);
        }

        if($method==='POST'&&preg_match('#^/mcma/v1/memories/([0-9a-f]{64})/validation$#',$path,$m)){
            $this->assertOrigin($request);
            $principal=$this->sessionPrincipal($request);
            $input=$request->json(8192);
            $action=(string)($input['action']??'');
            if(!in_array($action,['confirm','discard'],true)){
                throw new WebException(400,'invalid_memory_validation_action','action must be confirm or discard');
            }

            $knowledge=new KnowledgeService($principal['library']);
            $before=$knowledge->inspectId(
                'owner',$m[1],(float)($this->providerOptions['min-confidence']??0.75)
            );
            $targetState=$action==='confirm'?'verified':'retracted';
            $targetConfidence=$action==='confirm'?0.95:0.0;
            $unchanged=($before['validation_state']??null)===$targetState
                && abs((float)($before['confidence']??-1)-$targetConfidence)<1e-12;

            $semanticSync=null;
            if(!$unchanged){
                $knowledge->validateId(
                    'owner',$m[1],$targetState,$targetConfidence,
                    $action==='confirm'?'user-confirmed-in-memory-explorer':'user-discarded-in-memory-explorer',
                    [[
                        'source_type'=>'user',
                        'reference'=>'web-memory-explorer',
                        'note'=>$action==='confirm'?'Confirmed by library owner':'Discarded by library owner',
                    ]]
                );

                $embedding=$this->providers->embedding($this->providerOptions,true);
                if($embedding!==null){
                    $semantic=new SemanticIndexService($principal['library']);
                    $semanticSync=$semantic->refreshStoredEntry(
                        $embedding,KnowledgeService::logicalRefFromId($m[1]),'owner'
                    );
                }
            }

            $after=$knowledge->inspectId(
                'owner',$m[1],(float)($this->providerOptions['min-confidence']??0.75)
            );
            return HttpResponse::json([
                'ok'=>true,
                'memory'=>$after,
                'validation'=>[
                    'action'=>$action,
                    'unchanged'=>$unchanged,
                    'semantic_sync'=>$semanticSync,
                    'ai_tokens_used'=>0,
                    'credit_units_charged'=>0,
                ],
            ]);
        }

        if($method==='POST'&&$path==='/mcma/v1/memory') return $this->explicitMemory($request);
        if($method==='POST'&&$path==='/mcma/v1/ask') return $this->ask($request);

        if(str_starts_with($path,'/mcma/v1/admin/')) return $this->adminRoute($request,$method,$path);

        throw new WebException(404,'not_found','Route not found');
    }

    private function explicitMemory(HttpRequest $request): HttpResponse
    {
        $this->assertOrigin($request);
        $principal=$this->requestPrincipal($request);
        $input=$request->json(65536);
        $text=trim((string)($input['text']??$input['content']??''));
        if($text===''||strlen($text)>32768){
            throw new WebException(400,'invalid_memory_text','text is required and must be <= 32768 bytes');
        }

        $requestId=$this->requestId($input);
        $conversationId=$this->conversationId($input);
        $existing=(new InteractionArchiveService($principal['library']))->interactionByRequestId('owner',$conversationId,$requestId);
        if($existing!==null) return HttpResponse::json(['ok'=>true,'result'=>self::resultFromArchivedInteraction($existing)]);
        $result=$this->captureExplicitMemory($principal,$text,$requestId);
        $result=$this->recordContextTrace($principal,$requestId,$text,false,true,$result);
        $result=$this->recordInteraction($principal,$requestId,$conversationId,$text,$result);
        return HttpResponse::json(['ok'=>true,'result'=>$result]);
    }

    private function ask(HttpRequest $request): HttpResponse
    {
        $this->assertOrigin($request);
        $principal=$this->requestPrincipal($request);
        $input=$request->json(65536);
        $question=trim((string)($input['question']??''));
        if($question===''||strlen($question)>32768) throw new WebException(400,'invalid_question','question is required and must be <= 32768 bytes');
        $current=$this->boolField($input,'current',false);
        $remember=$this->boolField($input,'remember',true);
        $requestId=$this->requestId($input);
        $conversationId=$this->conversationId($input);

        $archiveService=new InteractionArchiveService($principal['library']);
        $existing=$archiveService->interactionByRequestId('owner',$conversationId,$requestId);
        if($existing!==null) return HttpResponse::json(['ok'=>true,'result'=>self::resultFromArchivedInteraction($existing)]);

        if(MemoryMutationService::isMutationRequest($question)){
            $result=$this->mutateMemory($principal,$question,$requestId);
            $result=$this->recordContextTrace($principal,$requestId,$question,false,true,$result);
            $result=$this->recordInteraction($principal,$requestId,$conversationId,$question,$result);
            return HttpResponse::json(['ok'=>true,'result'=>$result]);
        }

        if(ExplicitMemoryService::isExplicitSaveRequest($question)){
            $result=$this->captureExplicitMemory($principal,$question,$requestId);
            $result=$this->recordContextTrace($principal,$requestId,$question,false,true,$result);
            $result=$this->recordInteraction($principal,$requestId,$conversationId,$question,$result);
            return HttpResponse::json(['ok'=>true,'result'=>$result]);
        }

        $embedding=$this->providers->embedding($this->providerOptions,true);
        $generator=$this->providers->generation($this->providerOptions);
        $freshness=(string)($this->providerOptions['capture-freshness']??'stable');
        $maxAge=$freshness==='immutable'?null:(int)($this->providerOptions['capture-max-age']??2592000);
        $capture=[
            'confidence'=>(float)($this->providerOptions['capture-confidence']??0.5),
            'validation_state'=>(string)($this->providerOptions['capture-validation']??'unverified'),
            'freshness_class'=>$freshness,'max_age_seconds'=>$maxAge,
            'reuse_policy'=>(string)($this->providerOptions['capture-reuse']??'reuse-unless-stale'),
            'provenance'=>[],
        ];

        $conversationContextBuilder=$this->conversationContextBuilder($principal['library']);
        $multiMemoryContextBuilder=$this->multiMemoryContextBuilder($principal['library']);
        $broadMemoryRecallBuilder=$this->broadMemoryRecallBuilder($principal['library']);

        if($this->billingEnabled){
            if($this->billing===null) throw new WebException(503,'billing_unavailable','Billing is enabled but service is unavailable');
            $this->billing->ensureAccount($principal['library']);
            $service=new BillableAskService(
                $principal['library'],$this->billing,$embedding,$generator,$this->billingMaxOutputTokens,
                $conversationContextBuilder,$multiMemoryContextBuilder,$broadMemoryRecallBuilder
            );
            $result=$service->ask(
                $requestId,
                $principal['kind'],
                $question,$current,
                (float)($this->providerOptions['min-confidence']??0.75),
                (float)($this->providerOptions['min-similarity']??0.78),
                (int)($this->providerOptions['top-k']??5),
                $remember,$capture,
                array_filter(['api_key_id'=>$principal['api_key_id']??null],static fn($v)=>$v!==null),
                isset($this->providerOptions['candidate-similarity'])?(float)$this->providerOptions['candidate-similarity']:null,
                isset($this->providerOptions['min-rerank-score'])?(float)$this->providerOptions['min-rerank-score']:null,
                $conversationId
            );
        }else{
            $usageCollector=new UsageCollector();
            $embedding=$embedding!==null?new MeteredEmbeddingProvider($embedding,$usageCollector):null;
            $generator=$generator!==null?new MeteredGenerationProvider($generator,$usageCollector):null;
            $knowledge=new KnowledgeService($principal['library']);
            $semantic=$embedding!==null?new SemanticIndexService($principal['library']):null;
            $librarian=$embedding!==null?new Librarian($knowledge,$semantic,$embedding):new Librarian($knowledge);
            $ask=new AskService(
                $knowledge,$semantic,$embedding,$generator,$librarian,
                $conversationContextBuilder,$multiMemoryContextBuilder,$broadMemoryRecallBuilder
            );
            $result=$ask->ask(
                'ai',$question,$current,
                (float)($this->providerOptions['min-confidence']??0.75),
                (float)($this->providerOptions['min-similarity']??0.78),
                (int)($this->providerOptions['top-k']??5),
                $remember,$capture,
                isset($this->providerOptions['candidate-similarity'])?(float)$this->providerOptions['candidate-similarity']:null,
                isset($this->providerOptions['min-rerank-score'])?(float)$this->providerOptions['min-rerank-score']:null,
                $conversationId
            );
            $result['billing']=[
                'ai_billed'=>false,
                'credit_units_charged'=>0,
                'usage'=>$usageCollector->summary(),
                'provider_usage'=>$usageCollector->components(),
                'reason'=>$usageCollector->components()===[]?'no-ai-provider-called':'metered-without-billing',
            ];
        }

        $result=$this->recordContextTrace($principal,$requestId,$question,$current,$remember,$result);
        $result=$this->recordInteraction($principal,$requestId,$conversationId,$question,$result);
        return HttpResponse::json(['ok'=>true,'result'=>$result]);
    }

    private function captureExplicitMemory(array $principal,string $text,string $requestId): array
    {
        $embedding=$this->providers->embedding($this->providerOptions,true);
        $generator=$this->providers->generation($this->providerOptions);

        try{
            if($this->billingEnabled){
                if($this->billing===null) throw new WebException(503,'billing_unavailable','Billing is enabled but service is unavailable');
                $this->billing->ensureAccount($principal['library']);
                return (new BillableExplicitMemoryService(
                    $principal['library'],$this->billing,$embedding,$generator,$this->billingMaxOutputTokens
                ))->capture(
                    $requestId,
                    $principal['kind'],
                    $text,
                    array_filter(['api_key_id'=>$principal['api_key_id']??null],static fn($v)=>$v!==null)
                );
            }

            $usageCollector=new UsageCollector();
            $meteredEmbedding=$embedding!==null?new MeteredEmbeddingProvider($embedding,$usageCollector):null;
            $meteredGenerator=$generator!==null?new MeteredGenerationProvider($generator,$usageCollector):null;
            $result=(new ExplicitMemoryService($principal['library'],$meteredGenerator,$meteredEmbedding))->capture('owner',$text);
            $result['billing']=[
                'ai_billed'=>false,
                'credit_units_charged'=>0,
                'usage'=>$usageCollector->summary(),
                'provider_usage'=>$usageCollector->components(),
                'reason'=>$usageCollector->components()===[]?'no-ai-provider-called':'metered-without-billing',
            ];
            return $result;
        }catch(RuntimeException $e){
            if($e->getMessage()==='Explicit memory request has no content to store'){
                throw new WebException(400,'empty_memory_text','The save instruction has no memory content');
            }
            throw $e;
        }
    }

    private function mutateMemory(array $principal,string $text,string $requestId): array
    {
        $embedding=$this->providers->embedding($this->providerOptions,true);
        if($this->billingEnabled){
            if($this->billing===null) throw new WebException(503,'billing_unavailable','Billing is enabled but service is unavailable');
            $this->billing->ensureAccount($principal['library']);
            return (new BillableMemoryMutationService(
                $principal['library'],$this->billing,$embedding
            ))->execute(
                $requestId,$principal['kind'],$text,
                array_filter(['api_key_id'=>$principal['api_key_id']??null],static fn($v)=>$v!==null)
            );
        }
        $usageCollector=new UsageCollector();
        $meteredEmbedding=$embedding!==null?new MeteredEmbeddingProvider($embedding,$usageCollector):null;
        $result=(new MemoryMutationService($principal['library'],$meteredEmbedding))->execute('owner',$text);
        $result['billing']=[
            'ai_billed'=>false,'credit_units_charged'=>0,
            'usage'=>$usageCollector->summary(),
            'provider_usage'=>$usageCollector->components(),
            'reason'=>$usageCollector->components()===[]?'no-ai-provider-called':'metered-without-billing',
        ];
        return $result;
    }

    private function broadMemoryRecallBuilder(Library $library): ?BroadMemoryRecallBuilder
    {
        if(($this->providerOptions['broad-memory-recall-enabled']??true)!==true) return null;
        return new BroadMemoryRecallBuilder(
            $library,
            (int)($this->providerOptions['broad-memory-recall-max-items']??8),
            (int)($this->providerOptions['broad-memory-recall-byte-budget']??16000)
        );
    }

    private function conversationContextBuilder(Library $library): ?ConversationContextBuilder
    {
        if(($this->providerOptions['conversation-context-enabled']??true)!==true) return null;

        return new ConversationContextBuilder(
            $library,
            (int)($this->providerOptions['conversation-context-token-budget']??6000),
            (int)($this->providerOptions['conversation-context-max-turns']??6),
            (int)($this->providerOptions['conversation-context-candidates']??12),
            (float)($this->providerOptions['conversation-context-min-relevance']??0.08),
            (int)($this->providerOptions['conversation-context-recent-anchors']??2)
        );
    }

    private function multiMemoryContextBuilder(Library $library): ?MultiMemoryContextBuilder
    {
        if(($this->providerOptions['rag-multi-memory-enabled']??true)!==true) return null;

        return new MultiMemoryContextBuilder(
            $library,
            (int)($this->providerOptions['rag-token-budget']??8000),
            (int)($this->providerOptions['rag-max-memories']??4),
            (int)($this->providerOptions['rag-candidates']??8),
            (float)($this->providerOptions['rag-candidate-similarity']??0.55),
            (float)($this->providerOptions['rag-min-score']??0.50),
            (int)($this->providerOptions['rag-max-answer-bytes']??4500),
            (int)($this->providerOptions['rag-max-provenance']??4)
        );
    }

    private function requestId(array $input): string
    {
        $value=$input['request_id']??null;
        if($value===null||$value==='') return 'req_'.bin2hex(random_bytes(16));
        if(!is_string($value)||!preg_match('/^req_[0-9a-f]{32}$/',$value)){
            throw new WebException(400,'invalid_request_id','request_id must match req_<32 lowercase hex>');
        }
        return $value;
    }

    private static function resultFromArchivedInteraction(array $interaction): array
    {
        $provider=is_array($interaction['provider']??null)?$interaction['provider']:[];
        $billing=is_array($interaction['billing']??null)?$interaction['billing']:[
            'credit_units_charged'=>0,
            'usage'=>['total_tokens'=>0],
            'provider_usage'=>[],
        ];
        return [
            'found'=>true,
            'reusable'=>false,
            'decision'=>'recovered-archived-response',
            'route'=>(string)($interaction['route']??'unknown'),
            'provider_called'=>(bool)($provider['called']??false),
            'provider_id'=>$provider['id']??null,
            'answer'=>is_array($interaction['answer']??null)?$interaction['answer']:['format'=>'text','value'=>null],
            'stored'=>(bool)($interaction['stored']??false),
            'billing'=>$billing,
            'interaction_archive'=>[
                'recorded'=>true,
                'logical_ref'=>$interaction['logical_ref']??null,
                'conversation_id'=>$interaction['conversation_id']??null,
                'interaction_id'=>$interaction['interaction_id']??null,
                'at'=>$interaction['at']??null,
                'validation_state'=>$interaction['validation']['state']??'unverified',
                'recovered'=>true,
            ],
        ];
    }

    private function conversationId(array $input): string
    {
        $value=$input['conversation_id']??null;
        if($value===null||$value==='') return InteractionArchiveService::normalizeConversationId(null);
        if(!is_string($value)||!preg_match('/^conv_[0-9a-f]{32}$/',$value)){
            throw new WebException(400,'invalid_conversation_id','conversation_id must match conv_<32 lowercase hex>');
        }
        return $value;
    }

    private function recordInteraction(
        array $principal,
        string $requestId,
        string $conversationId,
        string $question,
        array $result
    ): array {
        try{
            $archive=(new InteractionArchiveService($principal['library']))->archive(
                'owner',$requestId,$conversationId,$question,$result,(string)($principal['kind']??'web')
            );
            $result['interaction_archive']=[
                'recorded'=>true,
                'logical_ref'=>$archive['logical_ref'],
                'conversation_id'=>$archive['conversation_id'],
                'interaction_id'=>$archive['interaction_id'],
                'at'=>$archive['at'],
                'validation_state'=>$archive['validation_state'],
            ];
        }catch(Throwable $e){
            error_log('MCMA interaction archive error: '.$e->getMessage());
            $result['interaction_archive']=['recorded'=>false,'conversation_id'=>$conversationId];
        }
        return $result;
    }

    private function recordContextTrace(
        array $principal,
        string $requestId,
        string $question,
        bool $current,
        bool $remember,
        array $result
    ): array {
        try{
            $trace=(new ContextTraceService($principal['library']))->record(
                $requestId,$question,$current,$remember,$result
            );
            $result['context_trace']=[
                'recorded'=>true,
                'trace_id'=>$trace['trace_id'],
                'at'=>$trace['at'],
            ];
        }catch(Throwable $e){
            error_log('MCMA context trace error: '.$e->getMessage());
            $result['context_trace']=['recorded'=>false];
        }
        return $result;
    }

    private function adminRoute(HttpRequest $request,string $method,string $path): HttpResponse
    {
        if($this->admin===null) throw new WebException(503,'admin_unavailable','Admin service is not configured');
        [$issuer,$subject]=$this->sessionIdentity($request);
        $this->admin->assertSuperAdmin($issuer,$subject);

        if($method==='GET'&&$path==='/mcma/v1/admin/users'){
            return HttpResponse::json(['ok'=>true,'users'=>$this->admin->listUsers($issuer,$subject)]);
        }
        if($method==='GET'&&preg_match('#^/mcma/v1/admin/users/(usr_[0-9a-f]{64})/billing$#',$path,$m)){
            return HttpResponse::json(['ok'=>true,'data'=>$this->admin->billingForUser($issuer,$subject,$m[1])]);
        }

        $this->assertOrigin($request);
        if($method==='POST'&&preg_match('#^/mcma/v1/admin/users/(usr_[0-9a-f]{64})/(credits|plan|service|access|payments)$#',$path,$m)){
            $input=$request->json(32768);$userId=$m[1];$action=$m[2];
            $result=match($action){
                'credits'=>$this->admin->adjustCredits($issuer,$subject,$userId,(int)($input['units']??0),(string)($input['reason']??'admin adjustment')),
                'plan'=>$this->admin->setPlan($issuer,$subject,$userId,(string)($input['plan_id']??'')),
                'service'=>$this->admin->setServiceStatus($issuer,$subject,$userId,(string)($input['status']??'')),
                'access'=>$this->admin->setAccessStatus($issuer,$subject,$userId,(string)($input['status']??'')),
                'payments'=>$this->admin->recordPayment($issuer,$subject,$userId,(string)($input['provider']??'manual'),$input),
            };
            return HttpResponse::json(['ok'=>true,'result'=>$result]);
        }
        if($method==='POST'&&$path==='/mcma/v1/admin/pricing'){
            $input=$request->json(32768);
            $providerId=(string)($input['provider_id']??'');
            unset($input['provider_id']);
            return HttpResponse::json(['ok'=>true,'pricing'=>$this->admin->setPricing($issuer,$subject,$providerId,$input)]);
        }
        if($method==='POST'&&preg_match('#^/mcma/v1/admin/plans/([a-z][a-z0-9-]{1,63})$#',$path,$m)){
            $input=$request->json(32768);
            return HttpResponse::json(['ok'=>true,'plan'=>$this->admin->setPlanDefinition($issuer,$subject,$m[1],$input)]);
        }

        throw new WebException(404,'not_found','Admin route not found');
    }

    private function requestPrincipal(HttpRequest $request): array
    {
        $auth=$request->header('authorization');
        if(is_string($auth)&&preg_match('/^Bearer\s+(.+)$/i',$auth,$m)){
            if($this->apiKeys===null) throw new BillingException('API token authentication is unavailable','api_keys_unavailable',503);
            $record=$this->apiKeys->authenticate(trim($m[1]));
            $userId=(string)$record['user_id'];
            return [
                'kind'=>'api','user_id'=>$userId,'api_key_id'=>$record['key_id'],
                'library'=>$this->users->resolveUserIdForService($userId,true),
            ];
        }
        return $this->sessionPrincipal($request);
    }

    private function sessionPrincipal(HttpRequest $request): array
    {
        $session=$this->sessionData($request);
        $issuer=(string)$session['iss'];$subject=(string)$session['sub'];
        $this->ensureRegistered($issuer,$subject);
        $info=$this->users->info($issuer,$subject);
        return [
            'kind'=>'web','user_id'=>(string)$info['user_id'],
            'issuer'=>$issuer,'subject'=>$subject,
            'identity'=>self::publicIdentity($session),
            'library'=>$this->users->resolve($issuer,$subject),
        ];
    }

    private function login(): HttpResponse
    {
        $state=self::token(32);$nonce=self::token(32);$verifier=self::token(48);
        $challenge=self::b64u(hash('sha256',$verifier,true));$now=time();
        $cookie=$this->stateCookieCipher->seal(['state'=>$state,'nonce'=>$nonce,'verifier'=>$verifier,'iat'=>$now,'exp'=>$now+600]);
        return HttpResponse::redirect($this->oidc->authorizationUrl($state,$nonce,$challenge),[
            'set-cookie'=>[$this->cookie(self::STATE_COOKIE,$cookie,600,'Lax')]
        ]);
    }

    private function callback(HttpRequest $request): HttpResponse
    {
        if($request->query('error')!==null) throw new WebException(401,'oidc_authorization_failed','OIDC authorization failed');
        $stateCookie=$request->cookie(self::STATE_COOKIE);
        if($stateCookie===null) throw new WebException(400,'missing_oidc_state','OIDC state cookie is missing');
        $state=$this->stateCookieCipher->open($stateCookie);$now=time();
        if(!is_int($state['exp']??null)||$state['exp']<$now) throw new WebException(400,'expired_oidc_state','OIDC login state expired');
        $queryState=$request->query('state');
        if(!is_string($queryState)||!is_string($state['state']??null)||!hash_equals($state['state'],$queryState)) throw new WebException(400,'invalid_oidc_state','OIDC state validation failed');
        $identity=$this->oidc->exchangeCode($request->query('code')??'',(string)($state['verifier']??''),(string)($state['nonce']??''));

        if($this->autoRegister){
            $this->users->register($identity['issuer'],$identity['subject']);
            if($this->billing!==null) $this->billing->ensureAccount($this->users->resolve($identity['issuer'],$identity['subject']));
        }

        $expiresAt=min((int)$identity['expires_at'],$now+$this->sessionTtl);
        $sessionData=['v'=>1,'iss'=>$identity['issuer'],'sub'=>$identity['subject'],'iat'=>$now,'exp'=>$expiresAt];
        $claims=is_array($identity['claims']??null)?$identity['claims']:[];
        $email=$claims['email']??null;
        if(is_string($email)&&$email!==''&&strlen($email)<=320) $sessionData['email']=$email;
        $name=$claims['name']??null;
        if(is_string($name)&&trim($name)!==''&&strlen($name)<=256) $sessionData['name']=trim($name);
        $picture=$claims['picture']??null;
        if(is_string($picture)&&strlen($picture)<=1024&&preg_match('#^https://#i',$picture)) $sessionData['picture']=$picture;
        if(is_bool($claims['email_verified']??null)) $sessionData['email_verified']=$claims['email_verified'];
        $session=$this->sessionCookieCipher->seal($sessionData);
        return HttpResponse::redirect($this->basePath===''?'/':$this->basePath,[
            'set-cookie'=>[
                $this->cookie(self::SESSION_COOKIE,$session,max(1,$expiresAt-$now),'Strict'),
                $this->clearCookie(self::STATE_COOKIE),
            ]
        ],303);
    }

    private function sessionIdentity(HttpRequest $request): array
    {
        $session=$this->sessionData($request);
        return [(string)$session['iss'],(string)$session['sub']];
    }

    private function sessionData(HttpRequest $request): array
    {
        $cookie=$request->cookie(self::SESSION_COOKIE);
        if($cookie===null) throw new WebException(401,'authentication_required','Authentication required');
        $session=$this->sessionCookieCipher->open($cookie);
        if(($session['v']??null)!==1) throw new WebException(401,'invalid_session','Unsupported web session');
        $exp=$session['exp']??null;
        if(!is_int($exp)||$exp<time()) throw new WebException(401,'session_expired','Web session expired');
        $issuer=$session['iss']??null;$subject=$session['sub']??null;
        if(!is_string($issuer)||$issuer===''||!is_string($subject)||$subject==='') throw new WebException(401,'invalid_session','Web session identity is invalid');
        return $session;
    }

    private static function publicIdentity(array $session): array
    {
        $identity=[];
        foreach(['email','name','picture'] as $field){
            $value=$session[$field]??null;
            if(is_string($value)&&$value!=='') $identity[$field]=$value;
        }
        if(is_bool($session['email_verified']??null)) $identity['email_verified']=$session['email_verified'];
        return $identity;
    }

    private function ensureRegistered(string $issuer,string $subject): void
    {
        if($this->autoRegister){$this->users->register($issuer,$subject);return;}
        try{$this->users->resolve($issuer,$subject);}
        catch(RuntimeException $e){
            if(str_contains($e->getMessage(),'not registered')) throw new WebException(403,'user_not_registered','Authenticated user is not registered');
            if(str_contains($e->getMessage(),'not active')) throw new WebException(403,'user_disabled','Authenticated user is disabled');
            throw $e;
        }
    }

    private function assertOrigin(HttpRequest $request): void
    {
        $origin=$request->header('origin');
        if($origin!==null&&!hash_equals($this->publicOrigin,rtrim($origin,'/'))) throw new WebException(403,'origin_rejected','Request origin is not allowed');
    }

    private function boolField(array $input,string $name,bool $default): bool
    {
        if(!array_key_exists($name,$input)) return $default;
        if(!is_bool($input[$name])) throw new WebException(400,'invalid_boolean',$name.' must be boolean');
        return $input[$name];
    }

    private function cookie(string $name,string $value,int $maxAge,string $sameSite): string
    {
        return $name.'='.rawurlencode($value).'; Path='.$this->cookiePath().'; Max-Age='.$maxAge.'; Secure; HttpOnly; SameSite='.$sameSite;
    }
    private function clearCookie(string $name): string { return $name.'=; Path='.$this->cookiePath().'; Max-Age=0; Secure; HttpOnly; SameSite=Lax'; }
    private function cookiePath(): string { return $this->basePath===''?'/':$this->basePath; }

    private function secure(HttpResponse $response): HttpResponse
    {
        return new HttpResponse($response->status(),$response->body(),$response->headers()+[
            'cache-control'=>'no-store','x-content-type-options'=>'nosniff','referrer-policy'=>'no-referrer',
            'x-frame-options'=>'DENY','permissions-policy'=>'camera=(), microphone=(), geolocation=()',
        ]);
    }

    private static function token(int $bytes): string { return self::b64u(random_bytes($bytes)); }
    private static function b64u(string $bytes): string { return rtrim(strtr(base64_encode($bytes),'+/','-_'),'='); }
}
