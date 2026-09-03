<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\Agent\Librarian;
use MCMA\Core\Ask\AskService;
use MCMA\Core\Ask\GenerationProvider;
use MCMA\Core\Knowledge\KnowledgeRecord;
use MCMA\Core\Knowledge\KnowledgeService;
use MCMA\Core\Library;
use MCMA\Core\Semantic\EmbeddingProvider;
use MCMA\Core\Semantic\SemanticIndexService;
use MCMA\Core\Storage\LocalFilesystemAdapter;

final class AskEmbeddingProvider implements EmbeddingProvider
{
    public int $calls = 0;

    public function id(): string { return 'test:ask-embedding-v1:dimensions=3'; }

    public function embed(string $text): array
    {
        $this->calls++;
        return match (KnowledgeRecord::normalizeIntent($text)) {
            'what is exact memory?' => [0.0, 1.0, 0.0],
            'what is semantic memory?' => [1.0, 0.0, 0.0],
            'explain semantic memory differently' => [0.99, 0.01, 0.0],
            'brand new question' => [0.0, 0.0, 1.0],
            'validated alternate' => [0.01, 0.0, 0.99995],
            'no provider question' => [0.2, 0.2, 0.9591663047],
            'context refresh question' => [0.4, 0.5, 0.7681145748],
            default => [0.33, 0.33, 0.34],
        };
    }
}

final class AskGenerationProvider implements GenerationProvider
{
    public int $calls = 0;
    public array $lastContext = [];

    public function id(): string { return 'test-generation:v1'; }

    public function generate(string $question, array $context = []): array
    {
        $this->calls++;
        $this->lastContext=$context;
        if (!isset($context['memory_attempt']) || !is_array($context['memory_attempt'])) {
            throw new RuntimeException('Ask provider did not receive safe memory summary');
        }

        return [
            'text' => 'Generated answer #' . $this->calls . ' for ' . $question,
            'usage' => ['inputTokens'=>10,'outputTokens'=>20,'totalTokens'=>30],
            'stop_reason' => 'end_turn',
        ];
    }
}

$base = sys_get_temp_dir() . '/mcma-ask-' . bin2hex(random_bytes(4));
putenv('MCMA_KEY_DIR=' . $base . '/keys');
putenv('MCMA_MASTER_KEY_B64');

function rr_ask(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) rr_ask($path); else @unlink($path);
    }
    @rmdir($dir);
}

function ok_ask(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

try {
    $lib = Library::init(new LocalFilesystemAdapter($base . '/library'), 'private');
    $lib->initializeAccessControl();

    $knowledge = new KnowledgeService($lib);
    $embedding = new AskEmbeddingProvider();
    $semantic = new SemanticIndexService($lib);
    $librarian = new Librarian($knowledge, $semantic, $embedding);
    $generator = new AskGenerationProvider();
    $ask = new AskService($knowledge, $semantic, $embedding, $generator, $librarian);

    $librarian->remember('What is exact memory?', 'Exact reusable answer.', [
        'confidence' => 0.97,
        'validation_state' => 'verified',
        'provenance' => [['source_type'=>'working-test','reference'=>'ask-exact']],
        'freshness_class' => 'immutable',
        'max_age_seconds' => null,
    ]);

    $librarian->remember('What is semantic memory?', 'Semantic reusable answer.', [
        'confidence' => 0.96,
        'validation_state' => 'verified',
        'provenance' => [['source_type'=>'working-test','reference'=>'ask-semantic']],
        'freshness_class' => 'immutable',
        'max_age_seconds' => null,
    ]);

    $exact = $ask->ask('ai', 'What is exact memory?');
    ok_ask(($exact['route'] ?? null) === 'memory-exact', 'Ask did not reuse exact memory');
    ok_ask(($exact['answer']['value'] ?? null) === 'Exact reusable answer.', 'Ask exact answer mismatch');
    ok_ask(($exact['provider_called'] ?? true) === false, 'Ask called generation provider for exact reuse');
    ok_ask($generator->calls === 0, 'Generation provider called during exact reuse');

    $semanticResult = $ask->ask('ai', 'Explain semantic memory differently', false, 0.75, 0.80, 5);
    ok_ask(($semanticResult['route'] ?? null) === 'memory-semantic', 'Ask did not reuse semantic memory');
    ok_ask(($semanticResult['answer']['value'] ?? null) === 'Semantic reusable answer.', 'Ask semantic answer mismatch');
    ok_ask(($semanticResult['provider_called'] ?? true) === false, 'Ask called generation provider for semantic reuse');
    ok_ask($generator->calls === 0, 'Generation provider called during semantic reuse');

    $generated = $ask->ask('ai', 'Brand new question', false, 0.75, 0.80, 5, true, [], null, null, null, 'es-MX');
    ok_ask(($generated['route'] ?? null) === 'provider', 'Ask miss did not use generation provider');
    ok_ask(($generated['response_language']??null)==='es-MX','Ask did not preserve preferred response language');
    ok_ask(str_contains((string)($generator->lastContext['system_instructions']??''),'es-MX'),'Generation provider did not receive browser response locale');
    ok_ask(str_contains((string)($generator->lastContext['system_instructions']??''),'explicitly requests another language'),'Language instruction did not preserve explicit user override');
    ok_ask(($generated['decision'] ?? null) === 'generated', 'Ask provider decision mismatch');
    ok_ask(($generated['provider_called'] ?? false) === true, 'Ask provider_called flag mismatch');
    ok_ask(($generated['stored'] ?? false) === true, 'Generated answer was not stored');
    ok_ask(($generated['answer']['value'] ?? null) === 'Generated answer #1 for Brand new question', 'Generated answer mismatch');
    ok_ask($generator->calls === 1, 'Generation provider call count mismatch');

    $stored = $knowledge->inspect('owner', 'Brand new question');
    ok_ask(($stored['record']['epistemic']['validation_state'] ?? null) === 'unverified', 'Generated knowledge must default to unverified');
    ok_ask(abs((float)($stored['record']['epistemic']['confidence'] ?? -1) - 0.5) < 1e-12, 'Generated knowledge confidence default mismatch');
    ok_ask(($stored['record']['provenance'][0]['source_type'] ?? null) === 'model', 'Generated knowledge provenance source mismatch');
    ok_ask(($stored['record']['provenance'][0]['reference'] ?? null) === 'test-generation:v1', 'Generated knowledge provider provenance mismatch');

    $firstGeneratedHash = $stored['storage_hash'];
    $again = $ask->ask('ai', 'Brand new question', false, 0.75, 0.80, 5, true);
    ok_ask(($again['route'] ?? null) === 'provider', 'Unverified exact knowledge should require provider');
    ok_ask(($again['stored'] ?? true) === false, 'Ask overwrote an existing non-reusable exact record');
    ok_ask(($again['store_reason'] ?? null) === 'existing-exact-record-preserved', 'Ask exact preservation reason missing');
    ok_ask($generator->calls === 2, 'Generation provider was not called for unverified exact record');
    $preserved = $knowledge->inspect('owner', 'Brand new question');
    ok_ask(hash_equals($firstGeneratedHash, $preserved['storage_hash']), 'Ask overwrote preserved exact record');

    $librarian->remember('Validated alternate', 'Trusted semantic fallback answer.', [
        'confidence' => 0.96,
        'validation_state' => 'verified',
        'provenance' => [['source_type'=>'working-test','reference'=>'ask-semantic-fallback']],
        'freshness_class' => 'immutable',
        'max_age_seconds' => null,
    ]);
    $hybridFallback = $ask->ask('ai', 'Brand new question', false, 0.75, 0.99999, 5, true, [], 0.80, 0.85);
    ok_ask(($hybridFallback['route'] ?? null) === 'memory-semantic', 'Hybrid ask did not use semantic fallback');
    ok_ask(($hybridFallback['matched_question'] ?? null) === 'Validated alternate', 'Hybrid ask selected wrong fallback');
    ok_ask(($hybridFallback['selection_gate'] ?? null) === 'rerank', 'Hybrid ask did not report rerank selection');
    ok_ask(($hybridFallback['provider_called'] ?? true) === false, 'Hybrid ask called generation provider');
    ok_ask($generator->calls === 2, 'Generation provider call count changed during hybrid ask');

    $fallback = $ask->ask('ai', 'Brand new question', false, 0.75, 0.80, 5, true);
    ok_ask(($fallback['route'] ?? null) === 'memory-semantic', 'Non-reusable exact memory blocked semantic fallback');
    ok_ask(($fallback['matched_question'] ?? null) === 'Validated alternate', 'Semantic fallback selected wrong knowledge');
    ok_ask(($fallback['answer']['value'] ?? null) === 'Trusted semantic fallback answer.', 'Semantic fallback answer mismatch');
    ok_ask(($fallback['provider_called'] ?? true) === false, 'Generation provider called despite reusable semantic fallback');
    ok_ask($generator->calls === 2, 'Generation provider call count changed during semantic fallback');

    $librarian->validate('Brand new question', 'verified', 0.95, 'Validated for ask reuse test');
    $reused = $ask->ask('ai', 'Brand new question', false, 0.75, 0.80, 5, true);
    ok_ask(($reused['route'] ?? null) === 'memory-exact', 'Validated generated knowledge was not reused');
    ok_ask(($reused['answer']['value'] ?? null) === 'Generated answer #1 for Brand new question', 'Validated stored answer mismatch');
    ok_ask($generator->calls === 2, 'Generation provider called after validated exact reuse');

    $memoryOnly = new AskService($knowledge);
    $memoryOnlyExact = $memoryOnly->ask('ai', 'What is exact memory?');
    ok_ask(($memoryOnlyExact['route'] ?? null) === 'memory-exact', 'Ask core exact reuse unexpectedly requires AI components');

    $noProvider = new AskService($knowledge, $semantic, $embedding, null, $librarian);
    $missing = $noProvider->ask('ai', 'No provider question', false, 0.75, 0.99, 5);
    ok_ask(($missing['decision'] ?? null) === 'provider-required', 'Ask without generation provider should request provider');
    ok_ask(($missing['provider_called'] ?? true) === false, 'Ask without generation provider marked provider call');
    ok_ask(!isset($missing['answer']), 'Ask provider-required result leaked an answer');

    $librarian->remember('Context refresh question', 'Previously verified MCMA context.', [
        'confidence' => 0.95,
        'validation_state' => 'verified',
        'provenance' => [['source_type'=>'working-test','reference'=>'ask-context-builder']],
        'freshness_class' => 'stable',
        'max_age_seconds' => 2592000,
    ]);
    $contextGenerated = $ask->ask('ai', 'Context refresh question', true, 0.75, 0.80, 5, false);
    ok_ask(($contextGenerated['route'] ?? null) === 'provider', 'Current-data request should revalidate through provider');
    ok_ask(($contextGenerated['context_used']['memory'] ?? false) === true, 'Validated memory context was not attached to generation');
    ok_ask(($generator->lastContext['memory_context']['answer'] ?? null) === 'Previously verified MCMA context.', 'Context Builder did not pass the verified memory answer');
    ok_ask(($generator->lastContext['memory_context']['validation_state'] ?? null) === 'verified', 'Context Builder lost validation metadata');
    ok_ask(in_array('current-data-requested',$generator->lastContext['memory_context']['reasons']??[],true), 'Context Builder lost revalidation reason');

    ok_ask(($lib->verify()['ok'] ?? false) === true, 'Library verify failed after ask orchestration');

    echo "MCMA ask orchestration integration passed.\n";
} finally {
    rr_ask($base);
}
