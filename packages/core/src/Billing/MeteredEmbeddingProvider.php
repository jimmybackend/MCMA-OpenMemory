<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

use MCMA\Core\Semantic\EmbeddingProvider;

final class MeteredEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private readonly EmbeddingProvider $delegate,
        private readonly UsageCollector $collector,
        private readonly mixed $beforeCall = null
    ) {
    }

    public function id(): string
    {
        return $this->delegate->id();
    }

    public function embed(string $text): array
    {
        if (is_callable($this->beforeCall)) ($this->beforeCall)('embedding', $this->id(), $text);
        $started = hrtime(true);
        $vector = $this->delegate->embed($text);
        $durationMs = (int)max(0, round((hrtime(true) - $started) / 1_000_000));

        if ($this->delegate instanceof UsageAwareEmbeddingProvider) {
            $usage = $this->delegate->lastUsage();
            $tokens = max(0, (int)($usage['inputTokens'] ?? 0));
            $method = (string)($usage['method'] ?? 'provider');
        } else {
            // Conservative fallback marker; billing policy can price estimated usage
            // differently or reject it. The method is always persisted.
            $tokens = max(1, strlen($text));
            $method = 'estimated-bytes-upper-bound';
        }

        $this->collector->embedding($this->id(), $tokens, $method, $durationMs);
        return $vector;
    }
}
