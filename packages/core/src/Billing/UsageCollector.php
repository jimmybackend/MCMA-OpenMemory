<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

final class UsageCollector
{
    private array $components = [];

    public function embedding(string $providerId, int $tokens, string $method, int $durationMs): void
    {
        $this->components[] = [
            'kind'=>'embedding',
            'provider_id'=>$providerId,
            'input_tokens'=>max(0,$tokens),
            'output_tokens'=>0,
            'cached_tokens'=>0,
            'embedding_tokens'=>max(0,$tokens),
            'token_count_method'=>$method,
            'duration_ms'=>max(0,$durationMs),
        ];
    }

    public function generation(string $providerId, array $usage, int $durationMs): void
    {
        $input = self::intUsage($usage, ['inputTokens','prompt_tokens','input_tokens']);
        $output = self::intUsage($usage, ['outputTokens','completion_tokens','output_tokens']);
        $cached = self::intUsage($usage, ['cachedTokens','cached_tokens']);

        $this->components[] = [
            'kind'=>'generation',
            'provider_id'=>$providerId,
            'input_tokens'=>$input,
            'output_tokens'=>$output,
            'cached_tokens'=>$cached,
            'embedding_tokens'=>0,
            'token_count_method'=>($usage['method'] ?? 'provider'),
            'duration_ms'=>max(0,$durationMs),
        ];
    }

    public function components(): array
    {
        return $this->components;
    }

    public function summary(): array
    {
        $summary = [
            'input_tokens'=>0,
            'output_tokens'=>0,
            'cached_tokens'=>0,
            'embedding_tokens'=>0,
            'model_calls'=>count($this->components),
            'duration_ms'=>0,
        ];
        foreach ($this->components as $component) {
            foreach (['input_tokens','output_tokens','cached_tokens','embedding_tokens','duration_ms'] as $field) {
                $summary[$field] += (int)$component[$field];
            }
        }
        $summary['total_tokens'] = $summary['input_tokens'] + $summary['output_tokens'] + $summary['embedding_tokens'];
        return $summary;
    }

    private static function intUsage(array $usage, array $names): int
    {
        foreach ($names as $name) {
            if (isset($usage[$name]) && is_int($usage[$name]) && $usage[$name] >= 0) return $usage[$name];
        }
        return 0;
    }
}
