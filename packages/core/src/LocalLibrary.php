<?php
declare(strict_types=1);

namespace MCMA\Core;

use JsonException;
use RuntimeException;

final class LocalLibrary
{
    private string $root;
    private array $manifestEnvelope;
    private array $manifestPayload;
    private string $masterKey;

    private function __construct(string $root, array $manifestEnvelope, array $manifestPayload, string $masterKey)
    {
        $this->root = $root;
        $this->manifestEnvelope = $manifestEnvelope;
        $this->manifestPayload = $manifestPayload;
        $this->masterKey = $masterKey;
    }

    public static function init(string $root, string $metadataMode = 'private'): self
    {
        if (!in_array($metadataMode, ['normal', 'private'], true)) throw new RuntimeException('metadata mode must be normal or private');
        $root = self::normalizeRoot($root);
        if (file_exists($root) && !is_dir($root)) throw new RuntimeException('Library path exists and is not a directory');
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) throw new RuntimeException('Unable to create library directory');

        $contents = array_values(array_diff(scandir($root) ?: [], ['.', '..']));
        if ($contents !== []) throw new RuntimeException('Refusing to initialize a non-empty directory');
        if (!mkdir($root . '/objects', 0700, true) && !is_dir($root . '/objects')) throw new RuntimeException('Unable to create objects directory');

        $libraryId = Crypto::uuidV4('lib');
        $rootIndexId = Crypto::uuidV4('obj');
        $manifestId = Crypto::uuidV4('obj');
        $masterKey = KeyStore::createOrUse($libraryId);
        $createdAt = self::now();

        $indexPayload = ['index_version' => '1.0', 'index_type' => 'root', 'entries' => [], 'shards' => []];
        $indexEnvelope = Crypto::encryptPayload($masterKey, $libraryId, $rootIndexId, 'index', $indexPayload);
        self::writeObjectEnvelope($root, $indexEnvelope);

        $manifestPayload = [
            'library_version' => '1.0',
            'created_at' => $createdAt,
            'metadata_mode' => $metadataMode,
            'root_index' => ['object_id' => $rootIndexId, 'storage_hash' => $indexEnvelope['storage_hash']],
            'entrypoints' => [
                'profile' => 'memory://identity/profile',
                'permissions' => 'memory://access/permissions',
                'vault' => 'memory://access/vault',
            ],
            'crypto_policy' => ['active_key_version' => Crypto::KEY_VERSION],
            'extensions' => [],
        ];
        $manifestEnvelope = Crypto::encryptPayload($masterKey, $libraryId, $manifestId, 'manifest', $manifestPayload);
        self::atomicWrite($root . '/manifest.mcma', Jcs::encode($manifestEnvelope) . PHP_EOL, 0600);

        return new self($root, $manifestEnvelope, $manifestPayload, $masterKey);
    }

    public static function open(string $root): self
    {
        $root = self::normalizeRoot($root);
        $manifestEnvelope = self::readEnvelopeFile($root . '/manifest.mcma');
        $protected = $manifestEnvelope['protected'] ?? null;
        if (!is_array($protected) || ($protected['container'] ?? null) !== 'manifest') throw new RuntimeException('Invalid MCMA manifest container');

        $libraryId = (string) ($protected['library_id'] ?? '');
        Crypto::validateLibraryId($libraryId);
        $masterKey = KeyStore::load($libraryId);
        $manifestPayload = Crypto::decryptPayload($masterKey, $manifestEnvelope);
        self::validateManifestPayload($manifestPayload);

        return new self($root, $manifestEnvelope, $manifestPayload, $masterKey);
    }

    public function info(): array
    {
        $index = $this->loadRootIndex();
        return [
            'library_id' => $this->libraryId(),
            'library_version' => $this->manifestPayload['library_version'],
            'metadata_mode' => $this->manifestPayload['metadata_mode'],
            'created_at' => $this->manifestPayload['created_at'],
            'manifest_object_id' => $this->manifestEnvelope['protected']['object_id'],
            'root_index_object_id' => $this->manifestPayload['root_index']['object_id'],
            'root_index_storage_hash' => $this->manifestPayload['root_index']['storage_hash'],
            'objects_indexed' => count($index['payload']['entries']),
        ];
    }

    public function write(
        string $logicalRef,
        mixed $content,
        string $contentFormat = 'text',
        string $temperature = 'hot',
        string $cognitiveLayer = '40-semantic',
        string $scope = 'user',
        string $maturity = 'raw'
    ): array {
        return $this->withWriteLock(function () use ($logicalRef, $content, $contentFormat, $temperature, $cognitiveLayer, $scope, $maturity): array {
            self::validateLogicalRef($logicalRef);
            self::validateMetadata($contentFormat, $temperature, $cognitiveLayer, $scope, $maturity);

            $index = $this->loadRootIndex();
            $this->assertLogicalRefAvailable($index['payload'], $logicalRef);
            $objectId = Crypto::uuidV4('obj');
            $payload = [
                'content_format' => $contentFormat,
                'metadata' => [
                    'created_at' => self::now(),
                    'temperature' => $temperature,
                    'cognitive_layer' => $cognitiveLayer,
                    'scope' => $scope,
                    'maturity' => $maturity,
                    'logical_refs' => [$logicalRef],
                    'revision' => 1,
                ],
                'content' => self::normalizeContent($content, $contentFormat),
            ];

            $envelope = Crypto::encryptPayload($this->masterKey, $this->libraryId(), $objectId, 'object', $payload);
            self::writeObjectEnvelope($this->root, $envelope);

            $index['payload']['entries'][] = self::indexEntryFromPayload($objectId, $envelope['storage_hash'], $payload);
            self::sortEntries($index['payload']['entries']);
            $this->replaceRootIndex($index['envelope']['protected']['object_id'], $index['payload']);

            return [
                'object_id' => $objectId,
                'storage_hash' => $envelope['storage_hash'],
                'logical_ref' => $logicalRef,
                'revision' => 1,
            ];
        });
    }

    public function update(
        string $logicalRef,
        mixed $content,
        ?string $contentFormat = null,
        ?string $temperature = null,
        ?string $cognitiveLayer = null,
        ?string $scope = null,
        ?string $maturity = null
    ): array {
        return $this->withWriteLock(function () use ($logicalRef, $content, $contentFormat, $temperature, $cognitiveLayer, $scope, $maturity): array {
            self::validateLogicalRef($logicalRef);
            $index = $this->loadRootIndex();
            [$entryPosition, $entry] = $this->findEntryWithPosition($index['payload'], $logicalRef);
            $oldEnvelope = $this->readObjectByHash($entry['storage_hash']);
            $oldPayload = Crypto::decryptPayload($this->masterKey, $oldEnvelope);

            $format = $contentFormat ?? (string) ($oldPayload['content_format'] ?? 'text');
            $metadata = $oldPayload['metadata'] ?? [];
            if (!is_array($metadata)) throw new RuntimeException('Malformed object metadata');

            $newTemperature = $temperature ?? (string) ($metadata['temperature'] ?? 'hot');
            $newLayer = $cognitiveLayer ?? (string) ($metadata['cognitive_layer'] ?? '40-semantic');
            $newScope = $scope ?? (string) ($metadata['scope'] ?? 'user');
            $newMaturity = $maturity ?? (string) ($metadata['maturity'] ?? 'raw');
            self::validateMetadata($format, $newTemperature, $newLayer, $newScope, $newMaturity);

            $revision = max(1, (int) ($metadata['revision'] ?? 1)) + 1;
            $metadata['updated_at'] = self::now();
            $metadata['temperature'] = $newTemperature;
            $metadata['cognitive_layer'] = $newLayer;
            $metadata['scope'] = $newScope;
            $metadata['maturity'] = $newMaturity;
            $metadata['logical_refs'] = $entry['logical_refs'];
            $metadata['revision'] = $revision;
            $metadata['previous_storage_hash'] = $entry['storage_hash'];

            $newPayload = [
                'content_format' => $format,
                'metadata' => $metadata,
                'content' => self::normalizeContent($content, $format),
            ];

            return $this->commitRevision($index, $entryPosition, $entry['object_id'], $newPayload, $logicalRef);
        });
    }

    public function setTemperature(string $logicalRef, string $temperature): array
    {
        if (!in_array($temperature, ['hot', 'warm', 'cold', 'frozen'], true)) throw new RuntimeException('Invalid temperature');

        return $this->withWriteLock(function () use ($logicalRef, $temperature): array {
            self::validateLogicalRef($logicalRef);
            $index = $this->loadRootIndex();
            [$entryPosition, $entry] = $this->findEntryWithPosition($index['payload'], $logicalRef);
            $oldEnvelope = $this->readObjectByHash($entry['storage_hash']);
            $payload = Crypto::decryptPayload($this->masterKey, $oldEnvelope);

            if (!isset($payload['metadata']) || !is_array($payload['metadata'])) throw new RuntimeException('Malformed object metadata');
            $metadata = $payload['metadata'];
            $oldTemperature = (string) ($metadata['temperature'] ?? $entry['temperature'] ?? 'hot');
            if ($oldTemperature === $temperature) {
                return [
                    'object_id' => $entry['object_id'],
                    'storage_hash' => $entry['storage_hash'],
                    'logical_ref' => $logicalRef,
                    'temperature' => $temperature,
                    'unchanged' => true,
                ];
            }

            $metadata['updated_at'] = self::now();
            $metadata['temperature'] = $temperature;
            $metadata['revision'] = max(1, (int) ($metadata['revision'] ?? 1)) + 1;
            $metadata['previous_storage_hash'] = $entry['storage_hash'];
            $payload['metadata'] = $metadata;

            $result = $this->commitRevision($index, $entryPosition, $entry['object_id'], $payload, $logicalRef);
            $result['temperature'] = $temperature;
            $result['previous_temperature'] = $oldTemperature;
            return $result;
        });
    }

    public function importHistorical(
        string $logicalRef,
        mixed $content,
        array $sourceEnvelope,
        string $contentFormat = 'text',
        string $temperature = 'cold',
        string $cognitiveLayer = '40-semantic',
        string $scope = 'user',
        string $maturity = 'observed',
        ?string $sourceRef = null
    ): array {
        return $this->withWriteLock(function () use ($logicalRef, $content, $sourceEnvelope, $contentFormat, $temperature, $cognitiveLayer, $scope, $maturity, $sourceRef): array {
            self::validateLogicalRef($logicalRef);
            self::validateMetadata($contentFormat, $temperature, $cognitiveLayer, $scope, $maturity);

            $sourceFormat = (string) ($sourceEnvelope['format'] ?? '');
            if (!in_array($sourceFormat, ['mcma-v1', 'mcma-v2'], true)) throw new RuntimeException('Unsupported historical source format');

            $index = $this->loadRootIndex();
            $this->assertLogicalRefAvailable($index['payload'], $logicalRef);
            $fingerprint = self::historicalFingerprint($sourceEnvelope);
            foreach ($index['payload']['entries'] as $existing) {
                if (($existing['migration_fingerprint'] ?? null) === $fingerprint) {
                    throw new RuntimeException('Historical source appears to have already been migrated');
                }
            }

            $objectId = Crypto::uuidV4('obj');
            $payload = [
                'content_format' => $contentFormat,
                'metadata' => [
                    'created_at' => self::now(),
                    'temperature' => $temperature,
                    'cognitive_layer' => $cognitiveLayer,
                    'scope' => $scope,
                    'maturity' => $maturity,
                    'logical_refs' => [$logicalRef],
                    'revision' => 1,
                    'provenance' => [[
                        'type' => 'migration',
                        'source_format' => $sourceFormat,
                        'source_key_id' => (string) ($sourceEnvelope['key_id'] ?? ''),
                        'source_logical_path' => (string) ($sourceEnvelope['logical_path'] ?? ''),
                        'source_file' => (string) ($sourceEnvelope['file'] ?? ''),
                        'source_ref' => $sourceRef ?? (string) ($sourceEnvelope['file'] ?? ''),
                        'migrated_at' => self::now(),
                    ]],
                ],
                'content' => self::normalizeContent($content, $contentFormat),
            ];

            $envelope = Crypto::encryptPayload($this->masterKey, $this->libraryId(), $objectId, 'object', $payload);
            self::writeObjectEnvelope($this->root, $envelope);

            $entry = self::indexEntryFromPayload($objectId, $envelope['storage_hash'], $payload);
            $entry['migration_fingerprint'] = $fingerprint;
            $index['payload']['entries'][] = $entry;
            self::sortEntries($index['payload']['entries']);
            $this->replaceRootIndex($index['envelope']['protected']['object_id'], $index['payload']);

            return [
                'object_id' => $objectId,
                'storage_hash' => $envelope['storage_hash'],
                'logical_ref' => $logicalRef,
                'source_format' => $sourceFormat,
                'source_preserved' => true,
            ];
        });
    }

    public function read(string $logicalRef): array
    {
        self::validateLogicalRef($logicalRef);
        $entry = $this->findEntry($logicalRef);
        $envelope = $this->readObjectByHash($entry['storage_hash']);
        if (($envelope['protected']['object_id'] ?? null) !== $entry['object_id']) throw new RuntimeException('Index/object identity mismatch');
        return ['object_id' => $entry['object_id'], 'storage_hash' => $entry['storage_hash'], 'payload' => Crypto::decryptPayload($this->masterKey, $envelope)];
    }

    public function list(): array
    {
        return $this->loadRootIndex()['payload']['entries'];
    }

    public function tree(): array
    {
        $root = [];
        foreach ($this->list() as $entry) {
            foreach ($entry['logical_refs'] as $ref) {
                $segments = explode('/', substr($ref, strlen('memory://')));
                $node =& $root;
                foreach ($segments as $segment) {
                    if (!isset($node[$segment])) $node[$segment] = [];
                    $node =& $node[$segment];
                }
                $node['@object_id'] = $entry['object_id'];
                unset($node);
            }
        }
        return $root;
    }

    public function verify(): array
    {
        Crypto::verifyEnvelope($this->manifestEnvelope);
        self::validateManifestPayload(Crypto::decryptPayload($this->masterKey, $this->manifestEnvelope));
        $index = $this->loadRootIndex();

        $verified = 0;
        $seenObjects = [];
        $seenRefs = [];
        foreach ($index['payload']['entries'] as $entry) {
            self::validateIndexEntry($entry);
            if (isset($seenObjects[$entry['object_id']])) throw new RuntimeException('Duplicate object_id in root index');
            $seenObjects[$entry['object_id']] = true;

            foreach ($entry['logical_refs'] as $ref) {
                self::validateLogicalRef($ref);
                if (isset($seenRefs[$ref])) throw new RuntimeException('Duplicate logical reference in root index: ' . $ref);
                $seenRefs[$ref] = true;
            }

            $envelope = $this->readObjectByHash($entry['storage_hash']);
            if (($envelope['protected']['object_id'] ?? null) !== $entry['object_id']) throw new RuntimeException('Indexed object_id does not match envelope object_id');
            Crypto::decryptPayload($this->masterKey, $envelope);
            $verified++;
        }

        return [
            'ok' => true,
            'library_id' => $this->libraryId(),
            'objects_verified' => $verified,
            'manifest_verified' => true,
            'root_index_verified' => true,
        ];
    }

    public function libraryId(): string
    {
        return $this->manifestEnvelope['protected']['library_id'];
    }

    private function withWriteLock(callable $callback): mixed
    {
        return LibraryLock::exclusive($this->root, function () use ($callback): mixed {
            $this->reload();
            return $callback();
        });
    }

    private function reload(): void
    {
        $manifestEnvelope = self::readEnvelopeFile($this->root . '/manifest.mcma');
        Crypto::verifyEnvelope($manifestEnvelope);
        $libraryId = (string) ($manifestEnvelope['protected']['library_id'] ?? '');
        if (!hash_equals($this->libraryId(), $libraryId)) throw new RuntimeException('Library identity changed while open');
        $manifestPayload = Crypto::decryptPayload($this->masterKey, $manifestEnvelope);
        self::validateManifestPayload($manifestPayload);
        $this->manifestEnvelope = $manifestEnvelope;
        $this->manifestPayload = $manifestPayload;
    }

    private function commitRevision(array $index, int $entryPosition, string $objectId, array $payload, string $logicalRef): array
    {
        $oldHash = $index['payload']['entries'][$entryPosition]['storage_hash'];
        $envelope = Crypto::encryptPayload($this->masterKey, $this->libraryId(), $objectId, 'object', $payload);
        self::writeObjectEnvelope($this->root, $envelope);

        $newEntry = self::indexEntryFromPayload($objectId, $envelope['storage_hash'], $payload);
        if (isset($index['payload']['entries'][$entryPosition]['migration_fingerprint'])) {
            $newEntry['migration_fingerprint'] = $index['payload']['entries'][$entryPosition]['migration_fingerprint'];
        }
        $index['payload']['entries'][$entryPosition] = $newEntry;
        self::sortEntries($index['payload']['entries']);
        $this->replaceRootIndex($index['envelope']['protected']['object_id'], $index['payload']);

        return [
            'object_id' => $objectId,
            'storage_hash' => $envelope['storage_hash'],
            'previous_storage_hash' => $oldHash,
            'logical_ref' => $logicalRef,
            'revision' => (int) ($payload['metadata']['revision'] ?? 1),
        ];
    }

    private function findEntry(string $logicalRef): array
    {
        [, $entry] = $this->findEntryWithPosition($this->loadRootIndex()['payload'], $logicalRef);
        return $entry;
    }

    private function findEntryWithPosition(array $indexPayload, string $logicalRef): array
    {
        foreach ($indexPayload['entries'] as $position => $entry) {
            if (in_array($logicalRef, $entry['logical_refs'] ?? [], true)) return [$position, $entry];
        }
        throw new RuntimeException('Memory not found: ' . $logicalRef);
    }

    private function assertLogicalRefAvailable(array $indexPayload, string $logicalRef): void
    {
        foreach ($indexPayload['entries'] as $entry) {
            if (in_array($logicalRef, $entry['logical_refs'] ?? [], true)) throw new RuntimeException('Logical reference already exists: ' . $logicalRef);
        }
    }

    private function loadRootIndex(): array
    {
        $ref = $this->manifestPayload['root_index'];
        $envelope = $this->readObjectByHash($ref['storage_hash']);
        if (($envelope['protected']['container'] ?? null) !== 'index') throw new RuntimeException('Root index is not an index container');
        if (($envelope['protected']['object_id'] ?? null) !== $ref['object_id']) throw new RuntimeException('Manifest/root-index object identity mismatch');

        $payload = Crypto::decryptPayload($this->masterKey, $envelope);
        if (($payload['index_version'] ?? null) !== '1.0' || ($payload['index_type'] ?? null) !== 'root') throw new RuntimeException('Invalid root index payload');
        if (!isset($payload['entries'], $payload['shards']) || !is_array($payload['entries']) || !is_array($payload['shards'])) throw new RuntimeException('Malformed root index payload');

        return ['envelope' => $envelope, 'payload' => $payload];
    }

    private function replaceRootIndex(string $rootIndexId, array $payload): void
    {
        $newIndexEnvelope = Crypto::encryptPayload($this->masterKey, $this->libraryId(), $rootIndexId, 'index', $payload);
        self::writeObjectEnvelope($this->root, $newIndexEnvelope);

        $this->manifestPayload['root_index']['storage_hash'] = $newIndexEnvelope['storage_hash'];
        $manifestId = $this->manifestEnvelope['protected']['object_id'];
        $newManifestEnvelope = Crypto::encryptPayload(
            $this->masterKey,
            $this->libraryId(),
            $manifestId,
            'manifest',
            $this->manifestPayload,
            $this->manifestPayload['crypto_policy']['active_key_version']
        );
        self::atomicWrite($this->root . '/manifest.mcma', Jcs::encode($newManifestEnvelope) . PHP_EOL, 0600);
        $this->manifestEnvelope = $newManifestEnvelope;
    }

    private function readObjectByHash(string $storageHash): array
    {
        $envelope = self::readEnvelopeFile(self::objectPath($this->root, $storageHash));
        if (($envelope['storage_hash'] ?? null) !== $storageHash) throw new RuntimeException('Requested storage hash does not match stored envelope');
        Crypto::verifyEnvelope($envelope);
        return $envelope;
    }

    private static function writeObjectEnvelope(string $root, array $envelope): void
    {
        Crypto::verifyEnvelope($envelope);
        $path = self::objectPath($root, $envelope['storage_hash']);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Unable to create object storage directory');

        if (file_exists($path)) {
            $existing = self::readEnvelopeFile($path);
            if (Jcs::encode($existing) !== Jcs::encode($envelope)) throw new RuntimeException('Storage-hash collision or conflicting object bytes');
            return;
        }
        self::atomicWrite($path, Jcs::encode($envelope) . PHP_EOL, 0600);
    }

    public static function objectPath(string $root, string $storageHash): string
    {
        if (!preg_match('/^sha256:([0-9a-f]{64})$/', $storageHash, $m)) throw new RuntimeException('Invalid storage_hash locator');
        $digest = $m[1];
        return rtrim($root, DIRECTORY_SEPARATOR) . '/objects/' . substr($digest, 0, 2) . '/' . substr($digest, 2, 2) . '/' . $digest . '.mcma';
    }

    private static function indexEntryFromPayload(string $objectId, string $storageHash, array $payload): array
    {
        $metadata = $payload['metadata'] ?? [];
        if (!is_array($metadata) || !isset($metadata['logical_refs']) || !is_array($metadata['logical_refs'])) throw new RuntimeException('Payload lacks logical references');

        return [
            'object_id' => $objectId,
            'storage_hash' => $storageHash,
            'logical_refs' => $metadata['logical_refs'],
            'temperature' => (string) ($metadata['temperature'] ?? 'cold'),
            'cognitive_layer' => (string) ($metadata['cognitive_layer'] ?? '40-semantic'),
            'scope' => (string) ($metadata['scope'] ?? 'user'),
        ];
    }

    private static function sortEntries(array &$entries): void
    {
        usort($entries, static fn(array $a, array $b): int => strcmp((string) ($a['logical_refs'][0] ?? ''), (string) ($b['logical_refs'][0] ?? '')));
    }

    private static function historicalFingerprint(array $sourceEnvelope): string
    {
        $copy = [
            'format' => $sourceEnvelope['format'] ?? null,
            'key_id' => $sourceEnvelope['key_id'] ?? null,
            'logical_path' => $sourceEnvelope['logical_path'] ?? null,
            'file' => $sourceEnvelope['file'] ?? null,
            'iv_b64' => $sourceEnvelope['iv_b64'] ?? null,
            'tag_b64' => $sourceEnvelope['tag_b64'] ?? null,
            'ciphertext_b64' => $sourceEnvelope['ciphertext_b64'] ?? null,
        ];
        return 'sha256:' . hash('sha256', Jcs::encode($copy));
    }

    private static function normalizeContent(mixed $content, string $format): mixed
    {
        if ($format === 'binary') {
            if (!is_string($content)) throw new RuntimeException('Binary content must be raw bytes');
            return Crypto::b64uEncode($content);
        }
        if (in_array($format, ['text', 'markdown', 'xml'], true) && !is_string($content)) {
            throw new RuntimeException($format . ' content must be a string');
        }
        return $content;
    }

    private static function readEnvelopeFile(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) throw new RuntimeException('Unable to read MCMA file: ' . $path);
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Invalid MCMA JSON: ' . $path, 0, $e);
        }
        if (!is_array($data)) throw new RuntimeException('MCMA envelope must be a JSON object');
        return $data;
    }

    private static function validateManifestPayload(array $payload): void
    {
        foreach (['library_version', 'created_at', 'metadata_mode', 'root_index', 'entrypoints', 'crypto_policy', 'extensions'] as $field) {
            if (!array_key_exists($field, $payload)) throw new RuntimeException('Missing manifest payload field: ' . $field);
        }
        if ($payload['library_version'] !== '1.0') throw new RuntimeException('Unsupported MCMA library version');
        if (!in_array($payload['metadata_mode'], ['normal', 'private'], true)) throw new RuntimeException('Invalid manifest metadata mode');
        if (!is_array($payload['root_index']) || !isset($payload['root_index']['object_id'], $payload['root_index']['storage_hash'])) throw new RuntimeException('Invalid manifest root_index');
        Crypto::validateObjectId((string) $payload['root_index']['object_id']);
        if (!preg_match('/^sha256:[0-9a-f]{64}$/', (string) $payload['root_index']['storage_hash'])) throw new RuntimeException('Invalid manifest root index hash');
    }

    private static function validateIndexEntry(array $entry): void
    {
        foreach (['object_id', 'storage_hash', 'logical_refs'] as $field) {
            if (!array_key_exists($field, $entry)) throw new RuntimeException('Malformed index entry');
        }
        Crypto::validateObjectId((string) $entry['object_id']);
        if (!preg_match('/^sha256:[0-9a-f]{64}$/', (string) $entry['storage_hash'])) throw new RuntimeException('Malformed indexed storage hash');
        if (!is_array($entry['logical_refs']) || $entry['logical_refs'] === []) throw new RuntimeException('Index entry requires logical_refs');
    }

    private static function validateLogicalRef(string $ref): void
    {
        if (!preg_match('#^memory://[a-z][a-z0-9-]{0,31}(?:/[a-z0-9][a-z0-9._-]{0,127})+$#', $ref)) throw new RuntimeException('Invalid canonical memory:// reference');
    }

    private static function validateMetadata(string $format, string $temperature, string $layer, string $scope, string $maturity): void
    {
        if (!in_array($format, ['json', 'xml', 'text', 'markdown', 'binary'], true)) throw new RuntimeException('Unsupported content format');
        if (!in_array($temperature, ['hot', 'warm', 'cold', 'frozen'], true)) throw new RuntimeException('Invalid temperature');
        if (!preg_match('/^(00-system|10-self|20-working|30-episodic|40-semantic|50-procedural|60-relational|70-preferences|80-goals|90-projects|95-world-model|99-meta)$/', $layer)) throw new RuntimeException('Invalid cognitive layer');
        if ($scope === '' || strlen($scope) > 128) throw new RuntimeException('Invalid scope');
        if (!in_array($maturity, ['raw', 'observed', 'classified', 'knowledge', 'confirmed'], true)) throw new RuntimeException('Invalid maturity state');
    }

    private static function atomicWrite(string $path, string $bytes, int $mode): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Unable to create directory: ' . $dir);

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $bytes, LOCK_EX) === false) throw new RuntimeException('Unable to write temporary MCMA file');
        @chmod($tmp, $mode);

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to atomically install MCMA file');
        }
        @chmod($path, $mode);
    }

    private static function normalizeRoot(string $root): string
    {
        $root = rtrim(trim($root), DIRECTORY_SEPARATOR);
        if ($root === '') throw new RuntimeException('Library path is required');
        if (str_contains($root, "\0")) throw new RuntimeException('Invalid library path');
        return $root;
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
