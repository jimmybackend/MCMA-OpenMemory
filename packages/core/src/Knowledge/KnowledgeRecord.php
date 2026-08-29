<?php
declare(strict_types=1);

namespace MCMA\Core\Knowledge;

use RuntimeException;

final class KnowledgeRecord
{
    public const VERSION = '1.0';
    public const VALIDATION_STATES = ['unverified','plausible','supported','verified','disputed','retracted'];
    public const FRESHNESS_CLASSES = ['immutable','stable','dynamic','volatile'];
    public const REUSE_POLICIES = ['always','reuse-unless-stale','revalidate-if-stale','never-direct'];
    public const SOURCE_TYPES = ['user','memory','web','documentation','api','database','working-test','model','file','observation','migration','other'];

    public static function normalizeIntent(string $question): string
    {
        $question = trim($question);
        if ($question === '') throw new RuntimeException('Knowledge question must not be empty');
        $question = preg_replace('/\s+/u', ' ', $question) ?? $question;
        if (function_exists('mb_strtolower')) return mb_strtolower($question, 'UTF-8');

        return strtr(strtolower($question), [
            'Á'=>'á','É'=>'é','Í'=>'í','Ó'=>'ó','Ú'=>'ú','Ü'=>'ü','Ñ'=>'ñ',
            'À'=>'à','È'=>'è','Ì'=>'ì','Ò'=>'ò','Ù'=>'ù','Ç'=>'ç',
        ]);
    }

    public static function intentKey(string $question): string
    {
        return 'sha256:' . hash('sha256', self::normalizeIntent($question));
    }

    public static function logicalRef(string $question): string
    {
        return 'memory://knowledge/q-' . substr(self::intentKey($question), 7);
    }

    public static function create(
        string $question,
        mixed $answer,
        string $answerFormat = 'text',
        float $confidence = 0.5,
        string $validationState = 'unverified',
        array $provenance = [],
        string $freshnessClass = 'stable',
        ?int $maxAgeSeconds = 2592000,
        string $reusePolicy = 'reuse-unless-stale',
        array $relations = [],
        ?string $at = null
    ): array {
        $at ??= self::now();
        self::validateAnswer($answer, $answerFormat);
        self::validateConfidence($confidence);
        self::validateValidationState($validationState);
        self::validateFreshness($freshnessClass, $maxAgeSeconds, $reusePolicy);
        $provenance = self::normalizeProvenance($provenance, $at);
        self::validateRelations($relations);

        $normalized = self::normalizeIntent($question);
        $record = [
            'knowledge_version' => self::VERSION,
            'intent' => [
                'question' => $question,
                'normalized' => $normalized,
                'key' => 'sha256:' . hash('sha256', $normalized),
            ],
            'answer' => [
                'format' => $answerFormat,
                'value' => $answer,
            ],
            'provenance' => $provenance,
            'epistemic' => [
                'confidence' => $confidence,
                'validation_state' => $validationState,
                'evidence_count' => count($provenance),
                'captured_at' => $at,
                'last_validated_at' => in_array($validationState, ['supported','verified'], true) ? $at : null,
                'history' => [[
                    'at' => $at,
                    'validation_state' => $validationState,
                    'confidence' => $confidence,
                    'reason' => 'capture',
                ]],
            ],
            'freshness' => [
                'class' => $freshnessClass,
                'max_age_seconds' => $freshnessClass === 'immutable' ? null : $maxAgeSeconds,
                'reuse_policy' => $reusePolicy,
            ],
            'relations' => array_values(array_unique($relations)),
        ];
        self::validate($record);
        return $record;
    }

    public static function validate(array $record): void
    {
        if (($record['knowledge_version'] ?? null) !== self::VERSION) throw new RuntimeException('Unsupported knowledge record version');

        $intent = $record['intent'] ?? null;
        if (!is_array($intent)) throw new RuntimeException('Knowledge record intent must be an object');
        foreach (['question','normalized','key'] as $field) if (!isset($intent[$field]) || !is_string($intent[$field])) throw new RuntimeException('Missing knowledge intent field: ' . $field);
        $normalized = self::normalizeIntent($intent['question']);
        if (!hash_equals($normalized, $intent['normalized'])) throw new RuntimeException('Knowledge normalized intent mismatch');
        if (!hash_equals('sha256:' . hash('sha256', $normalized), $intent['key'])) throw new RuntimeException('Knowledge intent key mismatch');

        $answer = $record['answer'] ?? null;
        if (!is_array($answer) || !array_key_exists('value', $answer) || !is_string($answer['format'] ?? null)) throw new RuntimeException('Invalid knowledge answer');
        self::validateAnswer($answer['value'], $answer['format']);

        if (!isset($record['provenance']) || !is_array($record['provenance'])) throw new RuntimeException('Knowledge provenance must be an array');
        self::normalizeProvenance($record['provenance'], self::now(), false);

        $epistemic = $record['epistemic'] ?? null;
        if (!is_array($epistemic)) throw new RuntimeException('Knowledge epistemic metadata must be an object');
        self::validateConfidence((float)($epistemic['confidence'] ?? -1));
        self::validateValidationState((string)($epistemic['validation_state'] ?? ''));
        if (!is_int($epistemic['evidence_count'] ?? null) || $epistemic['evidence_count'] < 0) throw new RuntimeException('Invalid evidence_count');
        if (($epistemic['evidence_count'] ?? -1) !== count($record['provenance'])) throw new RuntimeException('Knowledge evidence_count mismatch');
        self::validateTimestamp((string)($epistemic['captured_at'] ?? ''));
        if (($epistemic['last_validated_at'] ?? null) !== null) self::validateTimestamp((string)$epistemic['last_validated_at']);
        if (!isset($epistemic['history']) || !is_array($epistemic['history']) || $epistemic['history'] === []) throw new RuntimeException('Knowledge epistemic history must not be empty');
        foreach ($epistemic['history'] as $event) self::validateHistoryEvent($event);

        $freshness = $record['freshness'] ?? null;
        if (!is_array($freshness)) throw new RuntimeException('Knowledge freshness metadata must be an object');
        self::validateFreshness(
            (string)($freshness['class'] ?? ''),
            isset($freshness['max_age_seconds']) ? (int)$freshness['max_age_seconds'] : null,
            (string)($freshness['reuse_policy'] ?? '')
        );
        if (($freshness['class'] ?? null) === 'immutable' && ($freshness['max_age_seconds'] ?? null) !== null) throw new RuntimeException('Immutable knowledge must have null max_age_seconds');

        if (!isset($record['relations']) || !is_array($record['relations'])) throw new RuntimeException('Knowledge relations must be an array');
        self::validateRelations($record['relations']);
    }

    public static function withValidation(array $record, string $state, float $confidence, string $reason, array $additionalProvenance = [], ?string $at = null): array
    {
        self::validate($record);
        self::validateValidationState($state);
        self::validateConfidence($confidence);
        $at ??= self::now();
        if (trim($reason) === '') throw new RuntimeException('Validation reason must not be empty');

        if ($additionalProvenance !== []) {
            $normalized = self::normalizeProvenance($additionalProvenance, $at);
            $record['provenance'] = array_values(array_merge($record['provenance'], $normalized));
        }

        $record['epistemic']['confidence'] = $confidence;
        $record['epistemic']['validation_state'] = $state;
        $record['epistemic']['evidence_count'] = count($record['provenance']);
        $record['epistemic']['last_validated_at'] = $at;
        $record['epistemic']['history'][] = [
            'at' => $at,
            'validation_state' => $state,
            'confidence' => $confidence,
            'reason' => $reason,
        ];
        self::validate($record);
        return $record;
    }

    public static function assess(array $record, bool $currentRequired = false, float $minConfidence = 0.75, ?int $nowEpoch = null): array
    {
        self::validate($record);
        self::validateConfidence($minConfidence);
        $nowEpoch ??= time();

        $epistemic = $record['epistemic'];
        $freshness = $record['freshness'];
        $state = $epistemic['validation_state'];
        $confidence = (float)$epistemic['confidence'];
        $policy = $freshness['reuse_policy'];
        $class = $freshness['class'];
        $reasons = [];
        $stale = false;

        if (in_array($state, ['disputed','retracted'], true)) {
            return self::decision(false, 'reject', ['validation-state-' . $state], false, $record);
        }
        if ($policy === 'never-direct') {
            return self::decision(false, 'reject', ['reuse-policy-never-direct'], false, $record);
        }
        if (!in_array($state, ['supported','verified'], true)) $reasons[] = 'validation-insufficient';
        if ($confidence < $minConfidence) $reasons[] = 'confidence-below-threshold';

        $referenceTime = $epistemic['last_validated_at'] ?? $epistemic['captured_at'];
        $referenceEpoch = strtotime((string)$referenceTime);
        if ($referenceEpoch === false) throw new RuntimeException('Knowledge reference timestamp is invalid');

        if ($class !== 'immutable') {
            $maxAge = (int)$freshness['max_age_seconds'];
            $stale = ($nowEpoch - $referenceEpoch) > $maxAge;
            if ($stale && $policy !== 'always') $reasons[] = 'stale';
            if ($currentRequired) $reasons[] = 'current-data-requested';
        }

        if ($policy === 'always' && $reasons === []) {
            return self::decision(true, 'reuse', [], $stale, $record);
        }
        if ($reasons !== []) {
            return self::decision(false, 'revalidate', array_values(array_unique($reasons)), $stale, $record);
        }
        return self::decision(true, 'reuse', [], $stale, $record);
    }

    private static function decision(bool $reusable, string $decision, array $reasons, bool $stale, array $record): array
    {
        return [
            'reusable' => $reusable,
            'decision' => $decision,
            'reasons' => $reasons,
            'stale' => $stale,
            'intent_key' => $record['intent']['key'],
            'validation_state' => $record['epistemic']['validation_state'],
            'confidence' => $record['epistemic']['confidence'],
            'freshness_class' => $record['freshness']['class'],
            'reuse_policy' => $record['freshness']['reuse_policy'],
        ];
    }

    private static function normalizeProvenance(array $provenance, string $defaultAt, bool $fillDefaults = true): array
    {
        $out = [];
        foreach ($provenance as $entry) {
            if (!is_array($entry)) throw new RuntimeException('Each provenance entry must be an object');
            $sourceType = (string)($entry['source_type'] ?? '');
            if (!in_array($sourceType, self::SOURCE_TYPES, true)) throw new RuntimeException('Invalid provenance source_type: ' . $sourceType);
            $reference = (string)($entry['reference'] ?? '');
            if ($reference === '' || strlen($reference) > 2048) throw new RuntimeException('Invalid provenance reference');
            $at = isset($entry['captured_at']) ? (string)$entry['captured_at'] : ($fillDefaults ? $defaultAt : '');
            self::validateTimestamp($at);
            $normalized = ['source_type'=>$sourceType,'reference'=>$reference,'captured_at'=>$at];
            if (isset($entry['content_hash'])) {
                $hash = (string)$entry['content_hash'];
                if (!preg_match('/^sha256:[0-9a-f]{64}$/', $hash)) throw new RuntimeException('Invalid provenance content_hash');
                $normalized['content_hash'] = $hash;
            }
            if (isset($entry['note'])) {
                $note = (string)$entry['note'];
                if (strlen($note) > 1024) throw new RuntimeException('Provenance note too long');
                $normalized['note'] = $note;
            }
            $out[] = $normalized;
        }
        return $out;
    }

    private static function validateHistoryEvent(mixed $event): void
    {
        if (!is_array($event)) throw new RuntimeException('Knowledge validation history event must be an object');
        self::validateTimestamp((string)($event['at'] ?? ''));
        self::validateValidationState((string)($event['validation_state'] ?? ''));
        self::validateConfidence((float)($event['confidence'] ?? -1));
        if (!is_string($event['reason'] ?? null) || trim($event['reason']) === '') throw new RuntimeException('Knowledge validation history reason required');
    }

    private static function validateAnswer(mixed $answer, string $format): void
    {
        if (!in_array($format, ['text','markdown','json'], true)) throw new RuntimeException('Unsupported knowledge answer format');
        if (in_array($format, ['text','markdown'], true) && !is_string($answer)) throw new RuntimeException('Text/markdown knowledge answer must be a string');
    }

    private static function validateConfidence(float $confidence): void
    {
        if (!is_finite($confidence) || $confidence < 0.0 || $confidence > 1.0) throw new RuntimeException('Knowledge confidence must be between 0 and 1');
    }

    private static function validateValidationState(string $state): void
    {
        if (!in_array($state, self::VALIDATION_STATES, true)) throw new RuntimeException('Invalid knowledge validation state');
    }

    private static function validateFreshness(string $class, ?int $maxAgeSeconds, string $policy): void
    {
        if (!in_array($class, self::FRESHNESS_CLASSES, true)) throw new RuntimeException('Invalid knowledge freshness class');
        if (!in_array($policy, self::REUSE_POLICIES, true)) throw new RuntimeException('Invalid knowledge reuse policy');
        if ($class !== 'immutable' && ($maxAgeSeconds === null || $maxAgeSeconds < 0)) throw new RuntimeException('Non-immutable knowledge requires non-negative max_age_seconds');
    }

    private static function validateRelations(array $relations): void
    {
        foreach ($relations as $relation) {
            if (!is_string($relation) || !preg_match('#^memory://[a-z][a-z0-9-]{0,31}(?:/[a-z0-9][a-z0-9._-]{0,127})+$#', $relation)) {
                throw new RuntimeException('Invalid knowledge relation');
            }
        }
    }

    private static function validateTimestamp(string $timestamp): void
    {
        if ($timestamp === '' || strtotime($timestamp) === false) throw new RuntimeException('Invalid knowledge timestamp');
    }

    private static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
}
