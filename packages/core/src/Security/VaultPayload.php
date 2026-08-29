<?php
declare(strict_types=1);

namespace MCMA\Core\Security;

use RuntimeException;

final class VaultPayload
{
    public static function empty(): array { return ['vault_version'=>'1.0','entries'=>[]]; }

    public static function validate(array $vault): void
    {
        if (($vault['vault_version']??null)!=='1.0' || !isset($vault['entries']) || !is_array($vault['entries'])) throw new RuntimeException('Invalid MCMA vault payload');
        foreach ($vault['entries'] as $name=>$entry) {
            self::validateName((string)$name);
            if (!is_array($entry) || !isset($entry['type'],$entry['secret_b64u'],$entry['created_at'],$entry['updated_at'])) throw new RuntimeException('Malformed vault entry: '.$name);
            self::decode((string)$entry['secret_b64u']);
        }
    }

    public static function put(array $vault,string $name,string $secret,string $type='secret'): array
    {
        self::validate($vault); self::validateName($name); self::validateType($type);
        $now=gmdate('Y-m-d\TH:i:s\Z'); $created=$vault['entries'][$name]['created_at']??$now;
        $vault['entries'][$name]=['type'=>$type,'secret_b64u'=>self::encode($secret),'created_at'=>$created,'updated_at'=>$now];
        ksort($vault['entries'],SORT_STRING); return $vault;
    }

    public static function delete(array $vault,string $name): array
    {
        self::validate($vault); self::validateName($name); unset($vault['entries'][$name]); return $vault;
    }

    public static function metadata(array $vault): array
    {
        self::validate($vault); $out=[];
        foreach($vault['entries'] as $name=>$entry) $out[]=['name'=>$name,'type'=>$entry['type'],'created_at'=>$entry['created_at'],'updated_at'=>$entry['updated_at']];
        return $out;
    }

    public static function secret(array $vault,string $name): string
    {
        self::validate($vault); self::validateName($name);
        if(!isset($vault['entries'][$name])) throw new RuntimeException('Vault entry not found: '.$name);
        return self::decode((string)$vault['entries'][$name]['secret_b64u']);
    }

    private static function validateName(string $name):void{if(!preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/',$name))throw new RuntimeException('Invalid vault entry name');}
    private static function validateType(string $type):void{if(!preg_match('/^[a-z][a-z0-9._-]{0,63}$/',$type))throw new RuntimeException('Invalid vault entry type');}
    private static function encode(string $bytes):string{return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');}
    private static function decode(string $value):string{$pad=(4-strlen($value)%4)%4;$d=base64_decode(strtr($value.str_repeat('=',$pad),'-_','+/'),true);if($d===false)throw new RuntimeException('Invalid vault secret encoding');return $d;}
}
