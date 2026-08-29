<?php
declare(strict_types=1);

namespace MCMA\Core\Web;

use MCMA\Core\Billing\AdminService;
use MCMA\Core\Billing\ApiKeyService;
use MCMA\Core\Billing\BillingCatalog;
use MCMA\Core\Billing\BillingService;
use MCMA\Core\Cli\ProviderFactory;
use MCMA\Core\MultiUser\MultiUserService;
use MCMA\Core\Storage\StorageFactory;
use RuntimeException;

final class WebFactory
{
    public static function fromEnvironment(): WebApplication
    {
        $storageLocation=self::required('MCMA_WEB_STORAGE_LOCATION');
        $publicOrigin=rtrim(self::required('MCMA_WEB_PUBLIC_ORIGIN'),'/');
        $sessionSecret=self::required('MCMA_WEB_SESSION_SECRET');
        $issuer=rtrim(self::required('MCMA_OIDC_ISSUER'),'/');
        $clientId=self::required('MCMA_OIDC_CLIENT_ID');
        $clientSecret=self::optional('MCMA_OIDC_CLIENT_SECRET');
        $scope=self::optional('MCMA_OIDC_SCOPE')??'openid';
        $identityPepper=self::required('MCMA_MULTIUSER_PEPPER');

        $rootStorage=StorageFactory::fromLocation($storageLocation);
        $users=MultiUserService::fromEnvironment($rootStorage);
        $catalog=new BillingCatalog($rootStorage);
        $billing=new BillingService($catalog);

        $apiKeys=null;
        $apiPepper=self::optional('MCMA_API_KEY_PEPPER');
        if($apiPepper!==null) $apiKeys=new ApiKeyService($rootStorage,$users,$apiPepper);

        $admin=new AdminService($rootStorage,$users,$billing,$catalog,$identityPepper);
        $rootAdminIssuer=self::optional('MCMA_SUPERADMIN_ISSUER');
        $rootAdminSubject=self::optional('MCMA_SUPERADMIN_SUBJECT');
        if(($rootAdminIssuer===null)!==($rootAdminSubject===null)){
            throw new RuntimeException('MCMA_SUPERADMIN_ISSUER and MCMA_SUPERADMIN_SUBJECT must be configured together');
        }
        if($rootAdminIssuer!==null&&$rootAdminSubject!==null){
            $admin->bootstrapRoot($rootAdminIssuer,$rootAdminSubject);
        }

        $oidc=new OidcClient($issuer,$clientId,$clientSecret,$publicOrigin.'/callback',$scope);

        $generationProvider=self::optional('MCMA_WEB_GENERATION_PROVIDER')??'none';
        $options=[
            'embedding-provider'=>self::optional('MCMA_WEB_EMBEDDING_PROVIDER')??'none',
            'generation-provider'=>$generationProvider,
            'min-confidence'=>(float)(self::optional('MCMA_WEB_MIN_CONFIDENCE')??'0.75'),
            'min-similarity'=>(float)(self::optional('MCMA_WEB_MIN_SIMILARITY')??'0.78'),
            'top-k'=>(int)(self::optional('MCMA_WEB_TOP_K')??'5'),
            'capture-confidence'=>(float)(self::optional('MCMA_WEB_CAPTURE_CONFIDENCE')??'0.5'),
            'capture-validation'=>self::optional('MCMA_WEB_CAPTURE_VALIDATION')??'unverified',
            'capture-freshness'=>self::optional('MCMA_WEB_CAPTURE_FRESHNESS')??'stable',
            'capture-max-age'=>(int)(self::optional('MCMA_WEB_CAPTURE_MAX_AGE')??'2592000'),
            'capture-reuse'=>self::optional('MCMA_WEB_CAPTURE_REUSE')??'reuse-unless-stale',
        ];
        $dimensions=self::optional('MCMA_WEB_EMBEDDING_DIMENSIONS');
        if($dimensions!==null) $options['dimensions']=(int)$dimensions;

        $maxOutput=(int)(self::optional('MCMA_WEB_BILLING_MAX_OUTPUT_TOKENS')??self::providerMaxTokens($generationProvider));

        return new WebApplication(
            $users,
            $oidc,
            new EncryptedCookie($sessionSecret,'oidc-state'),
            new EncryptedCookie($sessionSecret,'session'),
            new ProviderFactory(),
            $publicOrigin,
            self::boolEnv('MCMA_WEB_AUTO_REGISTER',false),
            self::boolEnv('MCMA_WEB_SELF_REGISTER',false),
            $options,
            (int)(self::optional('MCMA_WEB_SESSION_TTL')??'28800'),
            $billing,
            $apiKeys,
            $admin,
            self::boolEnv('MCMA_BILLING_ENABLED',false),
            $maxOutput
        );
    }

    private static function providerMaxTokens(string $provider): string
    {
        return match($provider){
            'bedrock-converse'=>self::optional('MCMA_BEDROCK_MAX_TOKENS')??'1024',
            'ollama'=>self::optional('MCMA_OLLAMA_MAX_TOKENS')??'1024',
            'llamacpp'=>self::optional('MCMA_LLAMACPP_MAX_TOKENS')??'512',
            default=>'1024',
        };
    }

    private static function required(string $name): string
    {
        $value=self::optional($name);
        if($value===null) throw new RuntimeException($name.' is required for MCMA web');
        return $value;
    }

    private static function optional(string $name): ?string
    {
        $value=getenv($name);
        if(!is_string($value)||trim($value)==='') return null;
        return trim($value);
    }

    private static function boolEnv(string $name,bool $default): bool
    {
        $value=self::optional($name);
        if($value===null) return $default;
        $value=strtolower($value);
        if(in_array($value,['1','true','yes','on'],true)) return true;
        if(in_array($value,['0','false','no','off'],true)) return false;
        throw new RuntimeException($name.' must be true/false');
    }
}
