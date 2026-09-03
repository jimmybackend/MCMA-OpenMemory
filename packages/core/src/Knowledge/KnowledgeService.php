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

    public function replaceAnswerId(
        string $actor,
        string $id,
        string $answer,
        array $additionalProvenance = []
    ): array {
        $answer=trim($answer);
        if($answer==='') throw new RuntimeException('Edited knowledge answer must not be empty');
        if(strlen($answer)>32768) throw new RuntimeException('Edited knowledge answer must be <= 32768 bytes');

        $logicalRef=self::logicalRefFromId($id);
        $current=$this->library->readAs($actor,$logicalRef);
        $record=$current['payload']['content']??null;
        if(!is_array($record)) throw new RuntimeException('Stored knowledge record is malformed');
        KnowledgeRecord::validate($record);

        $record['answer']=[
            'format'=>'text',
            'value'=>$answer,
        ];
        $record['freshness']=[
            'class'=>'stable',
            'max_age_seconds'=>31536000,
            'reuse_policy'=>'reuse-unless-stale',
        ];
        $record=KnowledgeRecord::withValidation(
            $record,
            'verified',
            0.95,
            'owner-edited-in-library',
            $additionalProvenance
        );

        $stored=$this->library->updateAs(
            $actor,$logicalRef,$record,'json','warm','40-semantic','knowledge','knowledge'
        );

        return [
            'logical_ref'=>$logicalRef,
            'object_id'=>$stored['object_id']??null,
            'storage_hash'=>$stored['storage_hash']??null,
            'previous_storage_hash'=>$stored['previous_storage_hash']??null,
            'revision'=>(int)($stored['revision']??0),
            'validation_state'=>'verified',
            'confidence'=>0.95,
            'temperature'=>'warm',
            'freshness_class'=>'stable',
            'reuse_policy'=>'reuse-unless-stale',
        ];
    }

    public function renameId(
        string $actor,
        string $id,
        string $title
    ): array {
        $title=trim(preg_replace('/\s+/u',' ',$title)??$title);
        if($title==='') throw new RuntimeException('Knowledge display title must not be empty');
        if(strlen($title)>120) throw new RuntimeException('Knowledge display title must be <= 120 bytes');

        $logicalRef=self::logicalRefFromId($id);
        $current=$this->library->readAs($actor,$logicalRef);
        $record=$current['payload']['content']??null;
        if(!is_array($record)) throw new RuntimeException('Stored knowledge record is malformed');
        KnowledgeRecord::validate($record);

        $record['display_title']=$title;
        $stored=$this->library->updateAs(
            $actor,$logicalRef,$record,'json','warm','40-semantic','knowledge','knowledge'
        );

        return [
            'logical_ref'=>$logicalRef,
            'object_id'=>$stored['object_id']??null,
            'storage_hash'=>$stored['storage_hash']??null,
            'previous_storage_hash'=>$stored['previous_storage_hash']??null,
            'revision'=>(int)($stored['revision']??0),
            'display_title'=>$title,
            'question'=>(string)$record['intent']['question'],
        ];
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

    public function browse(
        string $actor,
        string $query = '',
        ?string $temperature = null,
        ?string $validationState = null,
        int $page = 1,
        int $limit = 25,
        float $minConfidence = 0.75
    ): array {
        if ($page < 1) throw new RuntimeException('Memory browser page must be >= 1');
        if ($limit < 1 || $limit > 100) throw new RuntimeException('Memory browser limit must be between 1 and 100');
        if (strlen($query) > 256) throw new RuntimeException('Memory browser query is too long');
        if ($temperature !== null && !in_array($temperature, ['hot','warm','cold','frozen'], true)) {
            throw new RuntimeException('Invalid memory browser temperature');
        }
        if ($validationState !== null && !in_array($validationState, KnowledgeRecord::VALIDATION_STATES, true)) {
            throw new RuntimeException('Invalid memory browser validation state');
        }

        $needle = trim($query);
        $items = [];
        foreach ($this->library->listAs($actor) as $entry) {
            $logicalRef = null;
            foreach ($entry['logical_refs'] ?? [] as $ref) {
                if (is_string($ref) && preg_match('#^memory://knowledge/q-([0-9a-f]{64})$#', $ref)) {
                    $logicalRef = $ref;
                    break;
                }
            }
            if ($logicalRef === null) continue;
            if ($temperature !== null && ($entry['temperature'] ?? null) !== $temperature) continue;

            $stored = $this->library->readAs($actor, $logicalRef);
            $record = $stored['payload']['content'] ?? null;
            if (!is_array($record)) throw new RuntimeException('Stored knowledge record is malformed');
            KnowledgeRecord::validate($record);

            $state = (string)$record['epistemic']['validation_state'];
            if ($validationState !== null && $state !== $validationState) continue;

            $question = (string)$record['intent']['question'];
            $displayTitle=self::displayTitle($record,$question);
            $answerValue = $record['answer']['value'];
            if (
                $needle !== ''
                && !self::containsText($displayTitle, $needle)
                && !self::containsText($question, $needle)
                && !self::containsText(self::answerSearchText($answerValue), $needle)
            ) {
                continue;
            }

            $assessment = KnowledgeRecord::assess($record, false, $minConfidence);
            $metadata = $stored['payload']['metadata'] ?? [];
            $items[] = [
                'id' => substr($logicalRef, strlen('memory://knowledge/q-')),
                'logical_ref' => $logicalRef,
                'question' => $question,
                'display_title' => $displayTitle,
                'answer_format' => (string)$record['answer']['format'],
                'validation_state' => $state,
                'confidence' => (float)$record['epistemic']['confidence'],
                'temperature' => (string)($metadata['temperature'] ?? $entry['temperature'] ?? 'warm'),
                'freshness_class' => (string)$record['freshness']['class'],
                'reuse_policy' => (string)$record['freshness']['reuse_policy'],
                'reusable' => (bool)$assessment['reusable'],
                'stale' => (bool)$assessment['stale'],
                'captured_at' => (string)$record['epistemic']['captured_at'],
                'last_validated_at' => $record['epistemic']['last_validated_at'],
                'updated_at' => isset($metadata['updated_at']) ? (string)$metadata['updated_at'] : null,
            ];
        }

        usort($items, static function(array $a, array $b): int {
            $aTime = strtotime((string)($a['updated_at'] ?? $a['captured_at'])) ?: 0;
            $bTime = strtotime((string)($b['updated_at'] ?? $b['captured_at'])) ?: 0;
            if ($aTime !== $bTime) return $bTime <=> $aTime;
            return $a['question'] <=> $b['question'];
        });

        $total = count($items);
        $offset = ($page - 1) * $limit;
        return [
            'items' => array_slice($items, $offset, $limit),
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => max(1, (int)ceil($total / $limit)),
            'query' => $needle,
            'temperature' => $temperature,
            'validation_state' => $validationState,
            'ai_tokens_used' => 0,
        ];
    }

    public function inspectId(string $actor, string $id, float $minConfidence = 0.75): array
    {
        $logicalRef = self::logicalRefFromId($id);
        $stored = $this->library->readAs($actor, $logicalRef);
        $record = $stored['payload']['content'] ?? null;
        if (!is_array($record)) throw new RuntimeException('Stored knowledge record is malformed');
        KnowledgeRecord::validate($record);

        $assessment = KnowledgeRecord::assess($record, false, $minConfidence);
        $metadata = $stored['payload']['metadata'] ?? [];
        return [
            'id' => $id,
            'logical_ref' => $logicalRef,
            'object_id' => $stored['object_id'],
            'storage_hash' => $stored['storage_hash'],
            'question' => (string)$record['intent']['question'],
            'display_title' => self::displayTitle($record,(string)$record['intent']['question']),
            'answer' => $record['answer'],
            'provenance' => $record['provenance'],
            'relations' => $record['relations'],
            'validation_state' => (string)$record['epistemic']['validation_state'],
            'confidence' => (float)$record['epistemic']['confidence'],
            'captured_at' => (string)$record['epistemic']['captured_at'],
            'last_validated_at' => $record['epistemic']['last_validated_at'],
            'history' => $record['epistemic']['history'],
            'temperature' => (string)($metadata['temperature'] ?? 'warm'),
            'freshness_class' => (string)$record['freshness']['class'],
            'max_age_seconds' => $record['freshness']['max_age_seconds'],
            'reuse_policy' => (string)$record['freshness']['reuse_policy'],
            'reusable' => (bool)$assessment['reusable'],
            'decision' => (string)$assessment['decision'],
            'reasons' => $assessment['reasons'],
            'stale' => (bool)$assessment['stale'],
            'ai_tokens_used' => 0,
        ];
    }

    public function validateId(
        string $actor,
        string $id,
        string $state,
        float $confidence,
        string $reason,
        array $additionalProvenance = []
    ): array {
        $detail = $this->inspectId($actor, $id);
        return $this->validateKnowledge(
            $actor,
            (string)$detail['question'],
            $state,
            $confidence,
            $reason,
            $additionalProvenance
        );
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
            $result['relations'] = $record['relations'];
            foreach($record['relations'] as $relation){
                if(is_string($relation) && str_starts_with($relation,'memory://user/')){
                    $result['canonical_memory_ref']=$relation;
                    break;
                }
            }
        }
        return $result;
    }

    public static function logicalRefFromId(string $id): string
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $id)) throw new RuntimeException('Invalid knowledge memory id');
        return 'memory://knowledge/q-' . $id;
    }

    private static function displayTitle(array $record,string $fallback): string
    {
        $title=$record['display_title']??null;
        if(is_string($title)&&trim($title)!=='') return trim($title);
        return $fallback;
    }

    private static function containsText(string $haystack, string $needle): bool
    {
        if (function_exists('mb_stripos')) return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
        return stripos($haystack, $needle) !== false;
    }

    private static function answerSearchText(mixed $answer): string
    {
        if (is_string($answer)) return $answer;
        $encoded = json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '';
    }

    private function hasVisibleRef(string $actor, string $logicalRef): bool
    {
        foreach ($this->library->listAs($actor) as $entry) {
            if (in_array($logicalRef, $entry['logical_refs'] ?? [], true)) return true;
        }
        return false;
    }
}
