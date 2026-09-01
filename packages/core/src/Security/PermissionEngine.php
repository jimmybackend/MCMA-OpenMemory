<?php
declare(strict_types=1);

namespace MCMA\Core\Security;

use RuntimeException;

final class PermissionEngine
{
    private const ACTIONS = [
        'read','write','update','delete','temperature','classify','summarize','share','decrypt',
        'manage_permissions','manage_vault','vault_metadata','use_secret'
    ];

    public static function defaultPolicy(): array
    {
        return [
            'policy_version' => '1.0',
            'default' => 'deny',
            'roles' => [
                'owner' => ['allow' => ['*']],
                'ai' => ['allow' => ['read','summarize']],
                'librarian' => ['allow' => ['read','write','update','temperature','classify','summarize']],
                'security-agent' => ['allow' => ['vault_metadata','use_secret']],
                'application' => ['allow' => ['read']],
                'tool' => ['allow' => ['read']],
                'device' => ['allow' => ['read']],
            ],
            'resources' => [
                [
                    'resource' => 'memory://access/vault',
                    'subject' => '*',
                    'deny' => ['read','write','update','delete','temperature','classify','summarize','share','decrypt'],
                ],
                [
                    'resource' => 'memory://access/vault',
                    'subject' => 'owner',
                    'allow' => ['manage_vault','vault_metadata','use_secret'],
                ],
                [
                    'resource' => 'memory://access/vault',
                    'subject' => 'security-agent',
                    'allow' => ['vault_metadata','use_secret'],
                ],
                [
                    'resource' => 'memory://system/context-traces',
                    'subject' => '*',
                    'deny' => ['*'],
                ],
                [
                    'resource' => 'memory://system/context-traces',
                    'subject' => 'owner',
                    'allow' => ['read','write','update','delete'],
                ],
                [
                    'resource' => 'memory://system/semantic-index/*',
                    'subject' => '*',
                    'deny' => ['read','write','update','delete','share','decrypt'],
                ],
                [
                    'resource' => 'memory://system/semantic-index/*',
                    'subject' => 'librarian',
                    'allow' => ['read','write','update'],
                ],
                [
                    'resource' => 'memory://system/semantic-index/*',
                    'subject' => 'owner',
                    'allow' => ['read','write','update','delete'],
                ],
                [
                    'resource' => 'memory://access/permissions',
                    'subject' => '*',
                    'deny' => ['read','write','update','delete','share','decrypt'],
                ],
                [
                    'resource' => 'memory://access/permissions',
                    'subject' => 'owner',
                    'allow' => ['read','manage_permissions'],
                ],
            ],
        ];
    }

    public static function validate(array $policy): void
    {
        if (($policy['policy_version'] ?? null) !== '1.0') throw new RuntimeException('Unsupported permission policy version');
        if (!in_array($policy['default'] ?? null, ['allow','deny'], true)) throw new RuntimeException('Permission policy default must be allow or deny');
        if (!isset($policy['roles']) || !is_array($policy['roles'])) throw new RuntimeException('Permission policy roles must be an object');
        if (!isset($policy['resources']) || !is_array($policy['resources'])) throw new RuntimeException('Permission policy resources must be an array');
        foreach ($policy['roles'] as $role => $definition) {
            self::validateSubject((string)$role);
            if (!is_array($definition)) throw new RuntimeException('Invalid permission role definition: ' . $role);
            self::validateActions($definition['allow'] ?? []);
            self::validateActions($definition['deny'] ?? []);
        }
        foreach ($policy['resources'] as $rule) {
            if (!is_array($rule)) throw new RuntimeException('Invalid permission resource rule');
            self::validateResourcePattern((string)($rule['resource'] ?? ''));
            $subject = (string)($rule['subject'] ?? '');
            if ($subject !== '*') self::validateSubject($subject);
            self::validateActions($rule['allow'] ?? []);
            self::validateActions($rule['deny'] ?? []);
        }
    }

    public static function validateRequest(string $subject, string $action, string $resource): void
    {
        self::validateSubject($subject);
        self::validateAction($action);
        self::validateResource($resource);
    }

    public static function decision(array $policy, string $subject, string $action, string $resource): array
    {
        self::validate($policy);
        self::validateRequest($subject, $action, $resource);

        $best = ['specificity' => -1, 'effect' => $policy['default'], 'source' => 'default'];
        $role = $policy['roles'][$subject] ?? null;
        if (is_array($role)) {
            if (self::containsAction($role['deny'] ?? [], $action)) $best = ['specificity'=>0,'effect'=>'deny','source'=>'role:' . $subject];
            elseif (self::containsAction($role['allow'] ?? [], $action)) $best = ['specificity'=>0,'effect'=>'allow','source'=>'role:' . $subject];
        }

        foreach ($policy['resources'] as $i => $rule) {
            $pattern = (string)$rule['resource'];
            $ruleSubject = (string)$rule['subject'];
            if (!self::resourceMatches($pattern, $resource)) continue;
            if ($ruleSubject !== '*' && $ruleSubject !== $subject) continue;

            $specificity = self::resourceSpecificity($pattern) + ($ruleSubject === $subject ? 10 : 0);
            $effect = null;
            if (self::containsAction($rule['deny'] ?? [], $action)) $effect = 'deny';
            elseif (self::containsAction($rule['allow'] ?? [], $action)) $effect = 'allow';
            if ($effect === null) continue;

            if ($specificity > $best['specificity'] || ($specificity === $best['specificity'] && $effect === 'deny')) {
                $best = ['specificity'=>$specificity,'effect'=>$effect,'source'=>'resource:' . $i];
            }
        }

        return ['allowed'=>$best['effect']==='allow','subject'=>$subject,'action'=>$action,'resource'=>$resource,'source'=>$best['source']];
    }

    public static function assertAllowed(array $policy, string $subject, string $action, string $resource): void
    {
        $decision = self::decision($policy, $subject, $action, $resource);
        if (!$decision['allowed']) throw new RuntimeException('MCMA permission denied: ' . $subject . ' cannot ' . $action . ' ' . $resource);
    }

    private static function containsAction(array $actions,string $action):bool{return in_array('*',$actions,true)||in_array($action,$actions,true);}
    private static function resourceMatches(string $pattern,string $resource):bool{if($pattern==='*')return true;if(str_ends_with($pattern,'/*'))return str_starts_with($resource,substr($pattern,0,-1));return $pattern===$resource;}
    private static function resourceSpecificity(string $pattern):int{if($pattern==='*')return 1;if(str_ends_with($pattern,'/*'))return 2+strlen($pattern);return 1000+strlen($pattern);}
    private static function validateActions(mixed $actions):void{if(!is_array($actions))throw new RuntimeException('Permission action list must be an array');foreach($actions as $a){if($a==='*')continue;self::validateAction((string)$a);}}
    private static function validateAction(string $action):void{if(!in_array($action,self::ACTIONS,true))throw new RuntimeException('Unknown MCMA permission action: '.$action);}
    private static function validateSubject(string $subject):void{if(!preg_match('/^[a-z][a-z0-9-]{0,63}$/',$subject))throw new RuntimeException('Invalid MCMA permission subject: '.$subject);}
    private static function validateResource(string $resource):void{if(!preg_match('#^memory://[a-z][a-z0-9-]{0,31}(?:/[a-z0-9][a-z0-9._-]{0,127})+$#',$resource))throw new RuntimeException('Invalid MCMA permission resource');}
    private static function validateResourcePattern(string $resource):void{if($resource==='*')return;if(str_ends_with($resource,'/*')){self::validateResource(substr($resource,0,-2).'/x');return;}self::validateResource($resource);}
}
