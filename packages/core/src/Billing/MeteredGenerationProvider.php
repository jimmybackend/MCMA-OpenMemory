<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

use MCMA\Core\Ask\GenerationProvider;

final class MeteredGenerationProvider implements GenerationProvider
{
    public function __construct(
        private readonly GenerationProvider $delegate,
        private readonly UsageCollector $collector,
        private readonly mixed $beforeCall = null
    ) {
    }

    public function id(): string
    {
        return $this->delegate->id();
    }

    public function generate(string $question, array $context = []): array
    {
        $billingInput=self::billingInput($question,$context);
        if (is_callable($this->beforeCall)) ($this->beforeCall)('generation', $this->id(), $billingInput);
        $started = hrtime(true);
        $result = $this->delegate->generate($question, $context);
        $durationMs = (int)max(0, round((hrtime(true) - $started) / 1_000_000));

        $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
        if ($usage === []) {
            $usage = [
                'inputTokens'=>max(1,strlen($billingInput)),
                'outputTokens'=>max(1,strlen((string)($result['text'] ?? ''))),
                'method'=>'estimated-bytes-upper-bound',
            ];
        } else {
            $usage['method'] = 'provider';
        }
        $this->collector->generation($this->id(), $usage, $durationMs);
        return $result;
    }

    private static function billingInput(string $question,array $context): string
    {
        $parts=[$question];
        $instruction=$context['system_instructions']??null;
        if(is_string($instruction)&&trim($instruction)!=='') $parts[]=trim($instruction);

        $memory=$context['memory_context']??null;
        if(is_array($memory)){
            $encoded=json_encode($memory,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if(is_string($encoded)) $parts[]=$encoded;
        }

        $multiMemory=$context['multi_memory_context']??null;
        if(is_array($multiMemory)){
            $encoded=json_encode($multiMemory,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if(is_string($encoded)) $parts[]=$encoded;
        }

        $conversation=$context['conversation_context']??null;
        if(is_array($conversation)){
            $encoded=json_encode($conversation,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if(is_string($encoded)) $parts[]=$encoded;
        }
        return implode("\n",$parts);
    }
}
