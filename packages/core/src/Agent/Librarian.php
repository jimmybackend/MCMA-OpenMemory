<?php
declare(strict_types=1);

namespace MCMA\Core\Agent;

use MCMA\Core\Knowledge\KnowledgeRecord;
use MCMA\Core\Knowledge\KnowledgeService;
use MCMA\Core\Semantic\EmbeddingProvider;
use MCMA\Core\Semantic\SemanticIndexService;
use RuntimeException;

final class Librarian
{
    public function __construct(
        private readonly KnowledgeService $knowledge,
        private readonly ?SemanticIndexService $semantic = null,
        private readonly ?EmbeddingProvider $embeddingProvider = null
    ) {
        if (($this->semantic === null) !== ($this->embeddingProvider === null)) {
            throw new RuntimeException('Librarian semantic indexing requires both SemanticIndexService and EmbeddingProvider');
        }
    }

    public function remember(string $question, mixed $answer, array $options = []): array
    {
        $result = $this->knowledge->capture(
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

        return $this->syncSemantic($question, $result);
    }

    public function validate(string $question, string $state, float $confidence, string $reason, array $additionalProvenance = []): array
    {
        $result = $this->knowledge->validateKnowledge('librarian', $question, $state, $confidence, $reason, $additionalProvenance);
        return $this->syncSemantic($question, $result);
    }

    public function recall(string $question, bool $currentRequired = false, float $minConfidence = 0.75): array
    {
        return $this->knowledge->directAnswer('librarian', $question, $currentRequired, $minConfidence);
    }

    public function deindex(string $question): array
    {
        if ($this->semantic === null || $this->embeddingProvider === null) {
            throw new RuntimeException('Librarian semantic indexing is not configured');
        }

        return $this->semantic->remove(
            $this->embeddingProvider,
            KnowledgeRecord::logicalRef($question),
            'librarian'
        );
    }

    private function syncSemantic(string $question, array $result): array
    {
        if ($this->semantic === null || $this->embeddingProvider === null) return $result;

        $result['semantic_index'] = $this->semantic->indexOne(
            $this->embeddingProvider,
            KnowledgeRecord::logicalRef($question),
            'librarian'
        );
        return $result;
    }
}
