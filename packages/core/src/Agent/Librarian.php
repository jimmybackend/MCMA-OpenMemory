<?php
declare(strict_types=1);

namespace MCMA\Core\Agent;

use MCMA\Core\Knowledge\KnowledgeService;

final class Librarian
{
    public function __construct(private readonly KnowledgeService $knowledge) {}

    public function remember(string $question, mixed $answer, array $options = []): array
    {
        return $this->knowledge->capture(
            'librarian',
            $question,
            $answer,
            (string)($options['answer_format'] ?? 'text'),
            (float)($options['confidence'] ?? 0.5),
            (string)($options['validation_state'] ?? 'unverified'),
            is_array($options['provenance'] ?? null) ? $options['provenance'] : [],
            (string)($options['freshness_class'] ?? 'stable'),
            array_key_exists('max_age_seconds', $options) ? ($options['max_age_seconds'] === null ? null : (int)$options['max_age_seconds']) : 2592000,
            (string)($options['reuse_policy'] ?? 'reuse-unless-stale'),
            is_array($options['relations'] ?? null) ? $options['relations'] : []
        );
    }

    public function validate(string $question, string $state, float $confidence, string $reason, array $additionalProvenance = []): array
    {
        return $this->knowledge->validateKnowledge('librarian', $question, $state, $confidence, $reason, $additionalProvenance);
    }

    public function recall(string $question, bool $currentRequired = false, float $minConfidence = 0.75): array
    {
        return $this->knowledge->directAnswer('librarian', $question, $currentRequired, $minConfidence);
    }
}
