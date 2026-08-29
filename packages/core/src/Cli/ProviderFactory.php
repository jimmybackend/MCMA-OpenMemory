<?php
declare(strict_types=1);

namespace MCMA\Core\Cli;

use MCMA\Connectors\Aws\BedrockConverseGenerationProvider;
use MCMA\Connectors\Aws\BedrockTitanEmbeddingProvider;
use MCMA\Connectors\Local\LlamaCppEmbeddingProvider;
use MCMA\Connectors\Local\LlamaCppGenerationProvider;
use MCMA\Connectors\Local\OllamaEmbeddingProvider;
use MCMA\Connectors\Local\OllamaGenerationProvider;
use MCMA\Core\Ask\GenerationProvider;
use MCMA\Core\Semantic\EmbeddingProvider;

final class ProviderFactory
{
    public function embedding(array $options, bool $optional = false): ?EmbeddingProvider
    {
        $provider = $options['embedding-provider'] ?? ($optional ? 'none' : 'bedrock-titan-v2');
        if ($provider === 'none' && $optional) return null;

        if ($provider === 'bedrock-titan-v2') {
            $dimensions = isset($options['dimensions']) ? (int)$options['dimensions'] : null;
            return BedrockTitanEmbeddingProvider::fromEnvironment($dimensions);
        }
        if ($provider === 'ollama') return OllamaEmbeddingProvider::fromEnvironment();
        if ($provider === 'llamacpp') return LlamaCppEmbeddingProvider::fromEnvironment();

        throw new CliException('Unsupported embedding provider: ' . $provider);
    }

    public function generation(array $options): ?GenerationProvider
    {
        $provider = $options['generation-provider'] ?? 'none';
        if ($provider === 'none') return null;
        if ($provider === 'bedrock-converse') return BedrockConverseGenerationProvider::fromEnvironment();
        if ($provider === 'ollama') return OllamaGenerationProvider::fromEnvironment();
        if ($provider === 'llamacpp') return LlamaCppGenerationProvider::fromEnvironment();

        throw new CliException('Unsupported generation provider: ' . $provider);
    }
}
