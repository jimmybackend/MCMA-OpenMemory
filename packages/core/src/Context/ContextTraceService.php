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

        $summary = [
            'total' => 0,
            'reusable' => 0,
            'generated_by_model' => 0,
            'validation' => [],
            'temperatures' => ['hot'=>0,'warm'=>0,'cold'=>0,'frozen'=>0],
        ];
        $generated = [];
        $systemObjects = [];

        foreach ($this->library->listAs('owner') as $entry) {
            foreach ($entry['logical_refs'] ?? [] as $ref) {
                if (!is_string($ref)) continue;

                if (str_starts_with($ref, 'memory://system/')) {
                    $systemObjects[] = [
                        'logical_ref' => $ref,
                        'temperature' => (string)($entry['temperature'] ?? 'cold'),
                        'cognitive_layer' => (string)($entry['cognitive_layer'] ?? '00-system'),
                        'scope' => (string)($entry['scope'] ?? 'system'),
                    ];
                }

                if (!preg_match('#^memory://knowledge/q-[0-9a-f]{64}$#', $ref)) continue;

                $stored = $this->library->readAs('owner', $ref);
                $record = $stored['payload']['content'] ?? null;
                if (!is_array($record)) continue;

                try {
                    KnowledgeRecord::validate($record);
                    $assessment = KnowledgeRecord::assess($record, false, $minConfidence);
                } catch (Throwable) {
                    continue;
                }

                $summary['total']++;
                if (($assessment['reusable'] ?? false) === true) $summary['reusable']++;

                $state = (string)$record['epistemic']['validation_state'];
                $summary['validation'][$state] = (int)($summary['validation'][$state] ?? 0) + 1;

                $temperature = (string)(($stored['payload']['metadata']['temperature'] ?? $entry['temperature'] ?? 'warm'));
                if (array_key_exists($temperature, $summary['temperatures'])) $summary['temperatures'][$temperature]++;

                $modelSource = null;
                foreach ($record['provenance'] ?? [] as $source) {
                    if (is_array($source) && ($source['source_type'] ?? null) === 'model') {
                        $modelSource = (string)($source['reference'] ?? 'model');
                        break;
                    }
                }

                if ($modelSource !== null) {
                    $summary['generated_by_model']++;
                    $generated[] = [
                        'logical_ref' => $ref,
                        'question' => (string)$record['intent']['question'],
                        'validation_state' => $state,
                        'confidence' => (float)$record['epistemic']['confidence'],
                        'temperature' => $temperature,
                        'captured_at' => (string)$record['epistemic']['captured_at'],
                        'provider_id' => $modelSource,
                        'reusable' => (bool)($assessment['reusable'] ?? false),
                    ];
                }
            }
        }

        usort($generated, static fn(array $a,array $b): int =>
            (strtotime($b['captured_at']) ?: 0) <=> (strtotime($a['captured_at']) ?: 0)
        );
        usort($systemObjects, static fn(array $a,array $b): int => $a['logical_ref'] <=> $b['logical_ref']);

        return [
            'summary' => $summary,
            'generated_memories' => array_slice($generated, 0, 50),
            'system_objects' => $systemObjects,
            'traces' => $this->traces(),
            'ai_tokens_used' => 0,
            'credit_units_charged' => 0,
        ];
    }

    private function traces(): array
    {
        if (!$this->exists()) return [];
        $stored = $this->library->readAs('owner', self::REF);
        $content = $stored['payload']['content'] ?? null;
        if (!is_array($content)) return [];
        return is_array($content['entries'] ?? null) ? array_values($content['entries']) : [];
    }

    private function exists(): bool
    {
        foreach ($this->library->listAs('owner') as $entry) {
            if (in_array(self::REF, $entry['logical_refs'] ?? [], true)) return true;
        }
        return false;
    }

    private function ensurePrivatePolicy(): void
    {
        try {
            $policy = $this->library->permissions('owner');
        } catch (RuntimeException) {
            return;
        }

        $denyFound = false;
        $ownerFound = false;
        foreach ($policy['resources'] ?? [] as $rule) {
            if (!is_array($rule) || ($rule['resource'] ?? null) !== self::REF) continue;
            if (($rule['subject'] ?? null) === '*') $denyFound = true;
            if (($rule['subject'] ?? null) === 'owner') $ownerFound = true;
        }
        if ($denyFound && $ownerFound) return;

        if (!$denyFound) {
            $policy['resources'][] = [
                'resource' => self::REF,
                'subject' => '*',
                'deny' => ['*'],
            ];
        }
        if (!$ownerFound) {
            $policy['resources'][] = [
                'resource' => self::REF,
                'subject' => 'owner',
                'allow' => ['read','write','update','delete'],
            ];
        }
        $this->library->setPermissions($policy, 'owner');
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
