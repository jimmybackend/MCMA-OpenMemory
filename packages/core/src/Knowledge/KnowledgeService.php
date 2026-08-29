<?php
declare(strict_types=1);

namespace MCMA\Core\Knowledge;

use MCMA\Core\Library;
use RuntimeException;
use Throwable;

final class KnowledgeService
{
    public function __construct(private readonly Library $library) {}

    public function capture(
        string $actor,
        string $question,
        mixed $answer,
        string $answerFormat = 'text',
        float $confidence = 0.5,
        string $validationState = 'unverified',
        array $provenance = [],
        string $freshnessClass = 'stable',
        ?int $maxAgeSeconds = 2592000,
        string $reusePolicy = 'reuse-unless-stale',
        array $relations = []
    ): array {
        $record = KnowledgeRecord::create(
            $question, $answer, $answerFormat, $confidence, $validationState, $provenance,
            $freshnessClass, $maxAgeSeconds, $reusePolicy, $relations
        );
        $logicalRef = KnowledgeRecord::logicalRef($question);

        if ($this->hasVisibleRef($actor, $logicalRef)) {
            $result = $this->library->updateAs($actor, $logicalRef, $record, 'json', 'warm', '40-semantic', 'knowledge', 'knowledge');
            $result['created'] = false;
        } else {
            $result = $this->library->writeAs($actor, $logicalRef, $record, 'json', 'warm', '40-semantic', 'knowledge', 'knowledge');
            $result['created'] = true;
        }
        $result['intent_key'] = $record['intent']['key'];
        return $result;
    }

    public function validateKnowledge(
        string $actor,
        string $question,
        string $state,
        float $confidence,
        string $reason,
        array $additionalProvenance = []
    ): array {
        $logicalRef = KnowledgeRecord::logicalRef($question);
        $current = $this->library->readAs($actor, $logicalRef);
        $record = $current['payload']['content'] ?? null;
        if (!is_array($record)) throw new RuntimeException('Stored knowledge record is malformed');

        $record = KnowledgeRecord::withValidation($record, $state, $confidence, $reason, $additionalProvenance);
        $result = $this->library->updateAs($actor, $logicalRef, $record, 'json', 'warm', '40-semantic', 'knowledge', 'knowledge');
        $result['validation_state'] = $state;
        $result['confidence'] = $confidence;
        return $result;
    }

    public function inspect(string $actor, string $question): array
    {
        $logicalRef = KnowledgeRecord::logicalRef($question);
        $stored = $this->library->readAs($actor, $logicalRef);
        $record = $stored['payload']['content'] ?? null;
        if (!is_array($record)) throw new RuntimeException('Stored knowledge record is malformed');
        KnowledgeRecord::validate($record);

        return [
            'logical_ref' => $logicalRef,
            'object_id' => $stored['object_id'],
            'storage_hash' => $stored['storage_hash'],
            'record' => $record,
        ];
    }

    public function directAnswer(string $actor, string $question, bool $currentRequired = false, float $minConfidence = 0.75, ?int $nowEpoch = null): array
    {
        $logicalRef = KnowledgeRecord::logicalRef($question);
        try {
            $stored = $this->library->readAs($actor, $logicalRef);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'Memory not found:')) {
                return [
                    'found' => false,
                    'reusable' => false,
                    'decision' => 'miss',
                    'reasons' => ['not-found'],
                    'logical_ref' => $logicalRef,
                ];
            }
            throw $e;
        }

        $record = $stored['payload']['content'] ?? null;
        if (!is_array($record)) throw new RuntimeException('Stored knowledge record is malformed');
        $assessment = KnowledgeRecord::assess($record, $currentRequired, $minConfidence, $nowEpoch);

        $result = [
            'found' => true,
            'logical_ref' => $logicalRef,
            'object_id' => $stored['object_id'],
            'storage_hash' => $stored['storage_hash'],
        ] + $assessment;

        if ($assessment['reusable']) {
            $result['answer'] = $record['answer'];
            $result['provenance'] = $record['provenance'];
        }
        return $result;
    }

    private function hasVisibleRef(string $actor, string $logicalRef): bool
    {
        foreach ($this->library->listAs($actor) as $entry) {
            if (in_array($logicalRef, $entry['logical_refs'] ?? [], true)) return true;
        }
        return false;
    }
}
