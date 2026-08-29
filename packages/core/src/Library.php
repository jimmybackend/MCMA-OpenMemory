<?php
declare(strict_types=1);

namespace MCMA\Core;

use JsonException;
use MCMA\Core\Storage\StorageAdapter;
use MCMA\Core\Security\PermissionEngine;
use MCMA\Core\Security\VaultPayload;
use RuntimeException;

final class Library
{
    private array $manifestEnvelope;
    private array $manifestPayload;
    private string $masterKey;
    private string $manifestVersion;

    private function __construct(private readonly StorageAdapter $storage, array $manifestEnvelope, array $manifestPayload, string $masterKey, string $manifestVersion)
    {
        $this->manifestEnvelope = $manifestEnvelope;
        $this->manifestPayload = $manifestPayload;
        $this->masterKey = $masterKey;
        $this->manifestVersion = $manifestVersion;
    }

    public static function init(StorageAdapter $storage, string $metadataMode = 'private'): self
    {
        if (!in_array($metadataMode, ['normal', 'private'], true)) throw new RuntimeException('metadata mode must be normal or private');
        if ($storage->exists('manifest.mcma')) throw new RuntimeException('Storage already contains an MCMA manifest');
        $existing = $storage->list('');
        if ($existing !== []) throw new RuntimeException('Refusing to initialize non-empty storage');

        $libraryId = Crypto::uuidV4('lib');
        $rootIndexId = Crypto::uuidV4('obj');
        $manifestId = Crypto::uuidV4('obj');
        $masterKey = KeyStore::createOrUse($libraryId);
        $createdAt = self::now();

        $indexPayload = ['index_version' => '1.0', 'index_type' => 'root', 'entries' => [], 'shards' => []];
        $indexEnvelope = Crypto::encryptPayload($masterKey, $libraryId, $rootIndexId, 'index', $indexPayload);
        self::putEnvelope($storage, $indexEnvelope);

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
        $manifestBytes = Jcs::encode($manifestEnvelope) . PHP_EOL;
        $manifestVersion = $storage->put('manifest.mcma', $manifestBytes, null, true);

        return new self($storage, $manifestEnvelope, $manifestPayload, $masterKey, $manifestVersion);
    }

    public static function open(StorageAdapter $storage): self
    {
        $manifestObject = $storage->get('manifest.mcma');
        $manifestEnvelope = self::decodeEnvelope($manifestObject['bytes'], 'manifest.mcma');
        $protected = $manifestEnvelope['protected'] ?? null;
        if (!is_array($protected) || ($protected['container'] ?? null) !== 'manifest') throw new RuntimeException('Invalid MCMA manifest container');

        $libraryId = (string)($protected['library_id'] ?? '');
        Crypto::validateLibraryId($libraryId);
        $masterKey = KeyStore::load($libraryId);
        $manifestPayload = Crypto::decryptPayload($masterKey, $manifestEnvelope);
        self::validateManifestPayload($manifestPayload);
        return new self($storage, $manifestEnvelope, $manifestPayload, $masterKey, $manifestObject['version']);
    }

    public function storage(): StorageAdapter { return $this->storage; }
    public function storageId(): string { return $this->storage->id(); }
    public function libraryId(): string { return $this->manifestEnvelope['protected']['library_id']; }

    public function info(): array
    {
        $index = $this->loadRootIndex();
        return [
            'library_id' => $this->libraryId(),
            'library_version' => $this->manifestPayload['library_version'],
            'metadata_mode' => $this->manifestPayload['metadata_mode'],
            'created_at' => $this->manifestPayload['created_at'],
            'storage' => $this->storage->id(),
            'storage_capabilities' => $this->storage->capabilities(),
            'manifest_object_id' => $this->manifestEnvelope['protected']['object_id'],
            'root_index_object_id' => $this->manifestPayload['root_index']['object_id'],
            'root_index_storage_hash' => $this->manifestPayload['root_index']['storage_hash'],
            'objects_indexed' => count($index['payload']['entries']),
        ];
    }

    public function write(string $logicalRef, mixed $content, string $contentFormat = 'text', string $temperature = 'hot', string $cognitiveLayer = '40-semantic', string $scope = 'user', string $maturity = 'raw', ?string $actor = null): array
    {
        return $this->withWriteLock(function () use ($logicalRef, $content, $contentFormat, $temperature, $cognitiveLayer, $scope, $maturity, $actor): array {
            self::validateLogicalRef($logicalRef);
            if ($actor !== null) $this->assertPermission($actor, 'write', $logicalRef);
            self::assertOrdinaryRef($logicalRef);
            self::validateMetadata($contentFormat, $temperature, $cognitiveLayer, $scope, $maturity);
            $index = $this->loadRootIndex();
            $this->assertLogicalRefAvailable($index['payload'], $logicalRef);
            $objectId = Crypto::uuidV4('obj');
            $payload = [
                'content_format' => $contentFormat,
                'metadata' => [
                    'created_at' => self::now(), 'temperature' => $temperature, 'cognitive_layer' => $cognitiveLayer,
                    'scope' => $scope, 'maturity' => $maturity, 'logical_refs' => [$logicalRef], 'revision' => 1,
                ],
                'content' => self::normalizeContent($content, $contentFormat),
            ];
            $envelope = Crypto::encryptPayload($this->masterKey, $this->libraryId(), $objectId, 'object', $payload);
            self::putEnvelope($this->storage, $envelope);
            $index['payload']['entries'][] = self::indexEntryFromPayload($objectId, $envelope['storage_hash'], $payload);
            self::sortEntries($index['payload']['entries']);
            $this->replaceRootIndex($index['envelope']['protected']['object_id'], $index['payload']);
            return ['object_id' => $objectId, 'storage_hash' => $envelope['storage_hash'], 'logical_ref' => $logicalRef, 'revision' => 1];
        });
    }

    public function update(string $logicalRef, mixed $content, ?string $contentFormat = null, ?string $temperature = null, ?string $cognitiveLayer = null, ?string $scope = null, ?string $maturity = null, ?string $actor = null): array
    {
        return $this->withWriteLock(function () use ($logicalRef, $content, $contentFormat, $temperature, $cognitiveLayer, $scope, $maturity, $actor): array {
            self::validateLogicalRef($logicalRef);
            if ($actor !== null) $this->assertPermission($actor, 'update', $logicalRef);
            self::assertOrdinaryRef($logicalRef);
            $index = $this->loadRootIndex();
            [$entryPosition, $entry] = $this->findEntryWithPosition($index['payload'], $logicalRef);
            $oldPayload = Crypto::decryptPayload($this->masterKey, $this->readObjectByHash($entry['storage_hash']));
            $format = $contentFormat ?? (string)($oldPayload['content_format'] ?? 'text');
            $metadata = $oldPayload['metadata'] ?? [];
            if (!is_array($metadata)) throw new RuntimeException('Malformed object metadata');
            $newTemperature = $temperature ?? (string)($metadata['temperature'] ?? 'hot');
            $newLayer = $cognitiveLayer ?? (string)($metadata['cognitive_layer'] ?? '40-semantic');
            $newScope = $scope ?? (string)($metadata['scope'] ?? 'user');
            $newMaturity = $maturity ?? (string)($metadata['maturity'] ?? 'raw');
            self::validateMetadata($format, $newTemperature, $newLayer, $newScope, $newMaturity);
            $metadata['updated_at'] = self::now();
            $metadata['temperature'] = $newTemperature; $metadata['cognitive_layer'] = $newLayer; $metadata['scope'] = $newScope; $metadata['maturity'] = $newMaturity;
            $metadata['logical_refs'] = $entry['logical_refs']; $metadata['revision'] = max(1, (int)($metadata['revision'] ?? 1)) + 1; $metadata['previous_storage_hash'] = $entry['storage_hash'];
            $newPayload = ['content_format' => $format, 'metadata' => $metadata, 'content' => self::normalizeContent($content, $format)];
            return $this->commitRevision($index, $entryPosition, $entry['object_id'], $newPayload, $logicalRef);
        });
    }

    public function mutateJson(string $logicalRef, callable $mutator, ?string $actor = null): array
    {
        return $this->withWriteLock(function () use ($logicalRef, $mutator, $actor): array {
            self::validateLogicalRef($logicalRef);
            if ($actor !== null) $this->assertPermission($actor, 'update', $logicalRef);
            self::assertOrdinaryRef($logicalRef);

            $index = $this->loadRootIndex();
            [$entryPosition, $entry] = $this->findEntryWithPosition($index['payload'], $logicalRef);
            $payload = Crypto::decryptPayload($this->masterKey, $this->readObjectByHash($entry['storage_hash']));

            if (($payload['content_format'] ?? null) !== 'json') {
                throw new RuntimeException('mutateJson requires a JSON memory object');
            }

            $current = $payload['content'] ?? null;
            $next = $mutator($current);
            $payload['content'] = self::normalizeContent($next, 'json');

            if (!isset($payload['metadata']) || !is_array($payload['metadata'])) {
                throw new RuntimeException('Malformed object metadata');
            }
            $payload['metadata']['updated_at'] = self::now();
            $payload['metadata']['revision'] = max(1, (int)($payload['metadata']['revision'] ?? 1)) + 1;
            $payload['metadata']['previous_storage_hash'] = $entry['storage_hash'];

            return $this->commitRevision($index, $entryPosition, $entry['object_id'], $payload, $logicalRef);
        });
    }

    public function setTemperature(string $logicalRef, string $temperature, ?string $actor = null): array
    {
        if (!in_array($temperature, ['hot', 'warm', 'cold', 'frozen'], true)) throw new RuntimeException('Invalid temperature');
        return $this->withWriteLock(function () use ($logicalRef, $temperature, $actor): array {
            self::validateLogicalRef($logicalRef);
            if ($actor !== null) $this->assertPermission($actor, 'temperature', $logicalRef);
            self::assertOrdinaryRef($logicalRef);
            $index = $this->loadRootIndex();
            [$entryPosition, $entry] = $this->findEntryWithPosition($index['payload'], $logicalRef);
            $payload = Crypto::decryptPayload($this->masterKey, $this->readObjectByHash($entry['storage_hash']));
            if (!isset($payload['metadata']) || !is_array($payload['metadata'])) throw new RuntimeException('Malformed object metadata');
            $oldTemperature = (string)($payload['metadata']['temperature'] ?? $entry['temperature'] ?? 'hot');
            if ($oldTemperature === $temperature) return ['object_id' => $entry['object_id'], 'storage_hash' => $entry['storage_hash'], 'logical_ref' => $logicalRef, 'temperature' => $temperature, 'unchanged' => true];
            $payload['metadata']['updated_at'] = self::now(); $payload['metadata']['temperature'] = $temperature;
            $payload['metadata']['revision'] = max(1, (int)($payload['metadata']['revision'] ?? 1)) + 1; $payload['metadata']['previous_storage_hash'] = $entry['storage_hash'];
            $result = $this->commitRevision($index, $entryPosition, $entry['object_id'], $payload, $logicalRef);
            $result['temperature'] = $temperature; $result['previous_temperature'] = $oldTemperature; return $result;
        });
    }

    public function importHistorical(string $logicalRef, mixed $content, array $sourceEnvelope, string $contentFormat = 'text', string $temperature = 'cold', string $cognitiveLayer = '40-semantic', string $scope = 'user', string $maturity = 'observed', ?string $sourceRef = null): array
    {
        return $this->withWriteLock(function () use ($logicalRef, $content, $sourceEnvelope, $contentFormat, $temperature, $cognitiveLayer, $scope, $maturity, $sourceRef): array {
            self::validateLogicalRef($logicalRef); self::assertOrdinaryRef($logicalRef); self::validateMetadata($contentFormat, $temperature, $cognitiveLayer, $scope, $maturity);
            $sourceFormat = (string)($sourceEnvelope['format'] ?? '');
            if (!in_array($sourceFormat, ['mcma-v1', 'mcma-v2'], true)) throw new RuntimeException('Unsupported historical source format');
            $index = $this->loadRootIndex(); $this->assertLogicalRefAvailable($index['payload'], $logicalRef);
            $fingerprint = self::historicalFingerprint($sourceEnvelope);
            foreach ($index['payload']['entries'] as $existing) if (($existing['migration_fingerprint'] ?? null) === $fingerprint) throw new RuntimeException('Historical source appears to have already been migrated');
            $objectId = Crypto::uuidV4('obj');
            $payload = [
                'content_format' => $contentFormat,
                'metadata' => [
                    'created_at' => self::now(), 'temperature' => $temperature, 'cognitive_layer' => $cognitiveLayer, 'scope' => $scope, 'maturity' => $maturity,
                    'logical_refs' => [$logicalRef], 'revision' => 1,
                    'provenance' => [[
                        'type' => 'migration', 'source_format' => $sourceFormat, 'source_key_id' => (string)($sourceEnvelope['key_id'] ?? ''),
                        'source_logical_path' => (string)($sourceEnvelope['logical_path'] ?? ''), 'source_file' => (string)($sourceEnvelope['file'] ?? ''),
                        'source_ref' => $sourceRef ?? (string)($sourceEnvelope['file'] ?? ''), 'migrated_at' => self::now(),
                    ]],
                ],
                'content' => self::normalizeContent($content, $contentFormat),
            ];
            $envelope = Crypto::encryptPayload($this->masterKey, $this->libraryId(), $objectId, 'object', $payload); self::putEnvelope($this->storage, $envelope);
            $entry = self::indexEntryFromPayload($objectId, $envelope['storage_hash'], $payload); $entry['migration_fingerprint'] = $fingerprint;
            $index['payload']['entries'][] = $entry; self::sortEntries($index['payload']['entries']); $this->replaceRootIndex($index['envelope']['protected']['object_id'], $index['payload']);
            return ['object_id' => $objectId, 'storage_hash' => $envelope['storage_hash'], 'logical_ref' => $logicalRef, 'source_format' => $sourceFormat, 'source_preserved' => true];
        });
    }

    public function initializeAccessControl(?array $policy = null, string $actor = 'owner'): array
    {
        return $this->withWriteLock(function () use ($policy, $actor): array {
            $existingPolicy = $this->permissionPolicyRaw();
            if ($existingPolicy === null) {
                if ($actor !== 'owner') throw new RuntimeException('Only owner can bootstrap MCMA access control');
            } else {
                PermissionEngine::assertAllowed($existingPolicy, $actor, 'manage_permissions', 'memory://access/permissions');
                PermissionEngine::assertAllowed($existingPolicy, $actor, 'manage_vault', 'memory://access/vault');
            }

            $policy ??= PermissionEngine::defaultPolicy();
            PermissionEngine::validate($policy);
            self::assertOwnerRecoveryControl($policy);
            $index = $this->loadRootIndex();
            $created = [];

            if ($this->findEntryOptional($index['payload'], 'memory://access/permissions') === null) {
                $objectId = Crypto::uuidV4('obj');
                $payload = self::systemPayload('memory://access/permissions', $policy);
                $envelope = Crypto::encryptPayload($this->masterKey, $this->libraryId(), $objectId, 'object', $payload);
                self::putEnvelope($this->storage, $envelope);
                $index['payload']['entries'][] = self::indexEntryFromPayload($objectId, $envelope['storage_hash'], $payload);
                $created['permissions'] = ['object_id'=>$objectId,'storage_hash'=>$envelope['storage_hash']];
            }

            if ($this->findEntryOptional($index['payload'], 'memory://access/vault') === null) {
                $objectId = Crypto::uuidV4('obj');
                $payload = self::systemPayload('memory://access/vault', VaultPayload::empty());
                $envelope = Crypto::encryptPayload($this->masterKey, $this->libraryId(), $objectId, 'vault', $payload);
                self::putEnvelope($this->storage, $envelope);
                $index['payload']['entries'][] = self::indexEntryFromPayload($objectId, $envelope['storage_hash'], $payload);
                $created['vault'] = ['object_id'=>$objectId,'storage_hash'=>$envelope['storage_hash']];
            }

            if ($created !== []) {
                self::sortEntries($index['payload']['entries']);
                $this->replaceRootIndex($index['envelope']['protected']['object_id'], $index['payload']);
            }

            return ['initialized'=>true,'created'=>$created,'policy_version'=>$policy['policy_version']];
        });
    }

    public function permissionDecision(string $actor, string $action, string $resource): array
    {
        PermissionEngine::validateRequest($actor, $action, $resource);
        $policy = $this->permissionPolicyRaw();
        if ($policy === null) {
            return ['allowed'=>$actor==='owner','subject'=>$actor,'action'=>$action,'resource'=>$resource,'source'=>'bootstrap-owner-only'];
        }
        return PermissionEngine::decision($policy, $actor, $action, $resource);
    }

    public function permissions(string $actor = 'owner'): array
    {
        $policy = $this->permissionPolicyRaw();
        if ($policy === null) throw new RuntimeException('MCMA access control is not initialized');
        PermissionEngine::assertAllowed($policy, $actor, 'read', 'memory://access/permissions');
        return $policy;
    }

    public function setPermissions(array $policy, string $actor = 'owner'): array
    {
        PermissionEngine::validate($policy);
        self::assertOwnerRecoveryControl($policy);
        return $this->withWriteLock(function () use ($policy, $actor): array {
            $current = $this->permissionPolicyRaw();
            if ($current === null) throw new RuntimeException('MCMA access control is not initialized');
            PermissionEngine::assertAllowed($current, $actor, 'manage_permissions', 'memory://access/permissions');

            $index = $this->loadRootIndex();
            [$position, $entry] = $this->findEntryWithPosition($index['payload'], 'memory://access/permissions');
            $payload = Crypto::decryptPayload($this->masterKey, $this->readObjectByHash($entry['storage_hash']));
            $payload['content'] = $policy;
            $payload['metadata']['updated_at'] = self::now();
            $payload['metadata']['revision'] = max(1, (int)($payload['metadata']['revision'] ?? 1)) + 1;
            $payload['metadata']['previous_storage_hash'] = $entry['storage_hash'];
            return $this->commitRevision($index, $position, $entry['object_id'], $payload, 'memory://access/permissions', 'object');
        });
    }

    public function readAs(string $actor, string $logicalRef): array
    {
        $this->assertPermission($actor, 'read', $logicalRef);
        return $this->read($logicalRef);
    }

    public function writeAs(string $actor, string $logicalRef, mixed $content, string $contentFormat = 'text', string $temperature = 'hot', string $cognitiveLayer = '40-semantic', string $scope = 'user', string $maturity = 'raw'): array
    {
        return $this->write($logicalRef, $content, $contentFormat, $temperature, $cognitiveLayer, $scope, $maturity, $actor);
    }

    public function updateAs(string $actor, string $logicalRef, mixed $content, ?string $contentFormat = null, ?string $temperature = null, ?string $cognitiveLayer = null, ?string $scope = null, ?string $maturity = null): array
    {
        return $this->update($logicalRef, $content, $contentFormat, $temperature, $cognitiveLayer, $scope, $maturity, $actor);
    }

    public function setTemperatureAs(string $actor, string $logicalRef, string $temperature): array
    {
        return $this->setTemperature($logicalRef, $temperature, $actor);
    }

    public function listAs(string $actor): array
    {
        $entries = [];
        foreach ($this->list() as $entry) {
            $allowedRefs = [];
            foreach ($entry['logical_refs'] as $ref) if (($this->permissionDecision($actor, 'read', $ref)['allowed'] ?? false) === true) $allowedRefs[] = $ref;
            if ($allowedRefs !== []) { $copy = $entry; $copy['logical_refs'] = $allowedRefs; $entries[] = $copy; }
        }
        return $entries;
    }

    public function treeAs(string $actor): array
    {
        $root = [];
        foreach ($this->listAs($actor) as $entry) foreach ($entry['logical_refs'] as $ref) {
            $segments = explode('/', substr($ref, strlen('memory://'))); $node =& $root;
            foreach ($segments as $segment) { if (!isset($node[$segment])) $node[$segment] = []; $node =& $node[$segment]; }
            $node['@object_id'] = $entry['object_id']; unset($node);
        }
        return $root;
    }

    public function vaultPut(string $name, string $secret, string $type = 'secret', string $actor = 'owner'): array
    {
        return $this->withWriteLock(function () use ($name, $secret, $type, $actor): array {
            $this->assertPermission($actor, 'manage_vault', 'memory://access/vault');
            [$index, $position, $entry, $payload] = $this->loadVaultForUpdate();
            $payload['content'] = VaultPayload::put($payload['content'], $name, $secret, $type);
            $payload['metadata']['updated_at'] = self::now();
            $payload['metadata']['revision'] = max(1, (int)($payload['metadata']['revision'] ?? 1)) + 1;
            $payload['metadata']['previous_storage_hash'] = $entry['storage_hash'];
            $result = $this->commitRevision($index, $position, $entry['object_id'], $payload, 'memory://access/vault', 'vault');
            return ['name'=>$name,'type'=>$type,'object_id'=>$result['object_id'],'storage_hash'=>$result['storage_hash'],'revision'=>$result['revision']];
        });
    }

    public function vaultList(string $actor = 'owner'): array
    {
        $this->assertPermission($actor, 'vault_metadata', 'memory://access/vault');
        [, , , $payload] = $this->loadVaultForUpdate();
        return VaultPayload::metadata($payload['content']);
    }

    public function vaultDelete(string $name, string $actor = 'owner'): array
    {
        return $this->withWriteLock(function () use ($name, $actor): array {
            $this->assertPermission($actor, 'manage_vault', 'memory://access/vault');
            [$index, $position, $entry, $payload] = $this->loadVaultForUpdate();
            $payload['content'] = VaultPayload::delete($payload['content'], $name);
            $payload['metadata']['updated_at'] = self::now();
            $payload['metadata']['revision'] = max(1, (int)($payload['metadata']['revision'] ?? 1)) + 1;
            $payload['metadata']['previous_storage_hash'] = $entry['storage_hash'];
            $result = $this->commitRevision($index, $position, $entry['object_id'], $payload, 'memory://access/vault', 'vault');
            return ['deleted'=>$name,'object_id'=>$result['object_id'],'storage_hash'=>$result['storage_hash'],'revision'=>$result['revision']];
        });
    }

    public function useVaultSecret(string $name, string $actor, callable $operation): mixed
    {
        $this->assertPermission($actor, 'use_secret', 'memory://access/vault');
        [, , , $payload] = $this->loadVaultForUpdate();
        $secret = VaultPayload::secret($payload['content'], $name);
        try {
            return $operation($secret);
        } finally {
            $secret = str_repeat("\0", strlen($secret));
        }
    }

    public function refresh(): void
    {
        $this->reload();
    }

    public function read(string $logicalRef): array
    {
        self::validateLogicalRef($logicalRef);
        if ($logicalRef === 'memory://access/vault') throw new RuntimeException('Vault contents cannot be read through the ordinary memory API');
        $entry = $this->findEntry($logicalRef); $envelope = $this->readObjectByHash($entry['storage_hash']);
        if (($envelope['protected']['object_id'] ?? null) !== $entry['object_id']) throw new RuntimeException('Index/object identity mismatch');
        return ['object_id' => $entry['object_id'], 'storage_hash' => $entry['storage_hash'], 'payload' => Crypto::decryptPayload($this->masterKey, $envelope)];
    }

    public function list(): array { return $this->loadRootIndex()['payload']['entries']; }

    public function tree(): array
    {
        $root = [];
        foreach ($this->list() as $entry) foreach ($entry['logical_refs'] as $ref) {
            $segments = explode('/', substr($ref, strlen('memory://'))); $node =& $root;
            foreach ($segments as $segment) { if (!isset($node[$segment])) $node[$segment] = []; $node =& $node[$segment]; }
            $node['@object_id'] = $entry['object_id']; unset($node);
        }
        return $root;
    }

    public function verify(): array
    {
        Crypto::verifyEnvelope($this->manifestEnvelope); self::validateManifestPayload(Crypto::decryptPayload($this->masterKey, $this->manifestEnvelope)); $index = $this->loadRootIndex();
        $verified = 0; $seenObjects = []; $seenRefs = [];
        foreach ($index['payload']['entries'] as $entry) {
            self::validateIndexEntry($entry); if (isset($seenObjects[$entry['object_id']])) throw new RuntimeException('Duplicate object_id in root index'); $seenObjects[$entry['object_id']] = true;
            foreach ($entry['logical_refs'] as $ref) { self::validateLogicalRef($ref); if (isset($seenRefs[$ref])) throw new RuntimeException('Duplicate logical reference in root index: ' . $ref); $seenRefs[$ref] = true; }
            $envelope = $this->readObjectByHash($entry['storage_hash']); if (($envelope['protected']['object_id'] ?? null) !== $entry['object_id']) throw new RuntimeException('Indexed object_id does not match envelope object_id');
            Crypto::decryptPayload($this->masterKey, $envelope); $verified++;
        }
        return ['ok' => true, 'library_id' => $this->libraryId(), 'storage' => $this->storage->id(), 'objects_verified' => $verified, 'manifest_verified' => true, 'root_index_verified' => true];
    }

    private function withWriteLock(callable $callback): mixed
    {
        return $this->storage->withWriteLock(function () use ($callback): mixed { $this->reload(); return $callback(); });
    }

    private function reload(): void
    {
        $manifestObject = $this->storage->get('manifest.mcma'); $manifestEnvelope = self::decodeEnvelope($manifestObject['bytes'], 'manifest.mcma'); Crypto::verifyEnvelope($manifestEnvelope);
        $libraryId = (string)($manifestEnvelope['protected']['library_id'] ?? ''); if (!hash_equals($this->libraryId(), $libraryId)) throw new RuntimeException('Library identity changed while open');
        $manifestPayload = Crypto::decryptPayload($this->masterKey, $manifestEnvelope); self::validateManifestPayload($manifestPayload);
        $this->manifestEnvelope = $manifestEnvelope; $this->manifestPayload = $manifestPayload; $this->manifestVersion = $manifestObject['version'];
    }

    private function commitRevision(array $index, int $entryPosition, string $objectId, array $payload, string $logicalRef, string $container = 'object'): array
    {
        $oldHash = $index['payload']['entries'][$entryPosition]['storage_hash'];
        $envelope = Crypto::encryptPayload($this->masterKey, $this->libraryId(), $objectId, $container, $payload); self::putEnvelope($this->storage, $envelope);
        $newEntry = self::indexEntryFromPayload($objectId, $envelope['storage_hash'], $payload);
        if (isset($index['payload']['entries'][$entryPosition]['migration_fingerprint'])) $newEntry['migration_fingerprint'] = $index['payload']['entries'][$entryPosition]['migration_fingerprint'];
        $index['payload']['entries'][$entryPosition] = $newEntry; self::sortEntries($index['payload']['entries']); $this->replaceRootIndex($index['envelope']['protected']['object_id'], $index['payload']);
        return ['object_id' => $objectId, 'storage_hash' => $envelope['storage_hash'], 'previous_storage_hash' => $oldHash, 'logical_ref' => $logicalRef, 'revision' => (int)($payload['metadata']['revision'] ?? 1)];
    }

    private static function assertOwnerRecoveryControl(array $policy): void
    {
        PermissionEngine::assertAllowed($policy, 'owner', 'manage_permissions', 'memory://access/permissions');
        PermissionEngine::assertAllowed($policy, 'owner', 'manage_vault', 'memory://access/vault');
    }

    private function permissionPolicyRaw(): ?array
    {
        $index = $this->loadRootIndex();
        $found = $this->findEntryOptional($index['payload'], 'memory://access/permissions');
        if ($found === null) return null;
        [, $entry] = $found;
        $envelope = $this->readObjectByHash($entry['storage_hash']);
        if (($envelope['protected']['container'] ?? null) !== 'object') throw new RuntimeException('Permissions entry has invalid container role');
        $payload = Crypto::decryptPayload($this->masterKey, $envelope);
        $policy = $payload['content'] ?? null;
        if (!is_array($policy)) throw new RuntimeException('Invalid encrypted permissions payload');
        PermissionEngine::validate($policy);
        return $policy;
    }

    private function assertPermission(string $actor, string $action, string $resource): void
    {
        $decision = $this->permissionDecision($actor, $action, $resource);
        if (($decision['allowed'] ?? false) !== true) throw new RuntimeException('MCMA permission denied: ' . $actor . ' cannot ' . $action . ' ' . $resource);
    }

    private function loadVaultForUpdate(): array
    {
        $index = $this->loadRootIndex();
        [$position, $entry] = $this->findEntryWithPosition($index['payload'], 'memory://access/vault');
        $envelope = $this->readObjectByHash($entry['storage_hash']);
        if (($envelope['protected']['container'] ?? null) !== 'vault') throw new RuntimeException('Vault entry has invalid container role');
        $payload = Crypto::decryptPayload($this->masterKey, $envelope);
        if (!isset($payload['content']) || !is_array($payload['content'])) throw new RuntimeException('Invalid encrypted vault payload');
        VaultPayload::validate($payload['content']);
        return [$index, $position, $entry, $payload];
    }

    private function findEntryOptional(array $indexPayload, string $logicalRef): ?array
    {
        foreach ($indexPayload['entries'] as $position => $entry) if (in_array($logicalRef, $entry['logical_refs'] ?? [], true)) return [$position, $entry];
        return null;
    }

    private static function systemPayload(string $logicalRef, array $content): array
    {
        return [
            'content_format' => 'json',
            'metadata' => [
                'created_at' => self::now(),
                'temperature' => 'hot',
                'cognitive_layer' => '00-system',
                'scope' => 'system',
                'maturity' => 'confirmed',
                'logical_refs' => [$logicalRef],
                'revision' => 1,
            ],
            'content' => $content,
        ];
    }

    private static function assertOrdinaryRef(string $logicalRef): void
    {
        if (in_array($logicalRef, ['memory://access/permissions','memory://access/vault'], true)) {
            throw new RuntimeException('Reserved access resource must be managed through the MCMA security API');
        }
    }

    private function findEntry(string $logicalRef): array { [, $entry] = $this->findEntryWithPosition($this->loadRootIndex()['payload'], $logicalRef); return $entry; }
    private function findEntryWithPosition(array $indexPayload, string $logicalRef): array { foreach ($indexPayload['entries'] as $position => $entry) if (in_array($logicalRef, $entry['logical_refs'] ?? [], true)) return [$position, $entry]; throw new RuntimeException('Memory not found: ' . $logicalRef); }
    private function assertLogicalRefAvailable(array $indexPayload, string $logicalRef): void { foreach ($indexPayload['entries'] as $entry) if (in_array($logicalRef, $entry['logical_refs'] ?? [], true)) throw new RuntimeException('Logical reference already exists: ' . $logicalRef); }

    private function loadRootIndex(): array
    {
        $ref = $this->manifestPayload['root_index']; $envelope = $this->readObjectByHash($ref['storage_hash']);
        if (($envelope['protected']['container'] ?? null) !== 'index' || ($envelope['protected']['object_id'] ?? null) !== $ref['object_id']) throw new RuntimeException('Manifest/root-index identity mismatch');
        $payload = Crypto::decryptPayload($this->masterKey, $envelope);
        if (($payload['index_version'] ?? null) !== '1.0' || ($payload['index_type'] ?? null) !== 'root' || !isset($payload['entries'], $payload['shards']) || !is_array($payload['entries']) || !is_array($payload['shards'])) throw new RuntimeException('Invalid root index payload');
        return ['envelope' => $envelope, 'payload' => $payload];
    }

    private function replaceRootIndex(string $rootIndexId, array $payload): void
    {
        $newIndexEnvelope = Crypto::encryptPayload($this->masterKey, $this->libraryId(), $rootIndexId, 'index', $payload); self::putEnvelope($this->storage, $newIndexEnvelope);
        $this->manifestPayload['root_index']['storage_hash'] = $newIndexEnvelope['storage_hash'];
        $newManifestEnvelope = Crypto::encryptPayload($this->masterKey, $this->libraryId(), $this->manifestEnvelope['protected']['object_id'], 'manifest', $this->manifestPayload, $this->manifestPayload['crypto_policy']['active_key_version']);
        $bytes = Jcs::encode($newManifestEnvelope) . PHP_EOL;
        $this->manifestVersion = $this->storage->put('manifest.mcma', $bytes, $this->manifestVersion, false);
        $this->manifestEnvelope = $newManifestEnvelope;
    }

    private function readObjectByHash(string $storageHash): array
    {
        $object = $this->storage->get(self::objectLocator($storageHash)); $envelope = self::decodeEnvelope($object['bytes'], self::objectLocator($storageHash));
        if (($envelope['storage_hash'] ?? null) !== $storageHash) throw new RuntimeException('Requested storage hash does not match stored envelope'); Crypto::verifyEnvelope($envelope); return $envelope;
    }

    public static function objectLocator(string $storageHash): string
    {
        if (!preg_match('/^sha256:([0-9a-f]{64})$/', $storageHash, $m)) throw new RuntimeException('Invalid storage_hash locator'); $digest = $m[1];
        return 'objects/' . substr($digest, 0, 2) . '/' . substr($digest, 2, 2) . '/' . $digest . '.mcma';
    }

    private static function putEnvelope(StorageAdapter $storage, array $envelope): void
    {
        Crypto::verifyEnvelope($envelope); $storage->put(self::objectLocator($envelope['storage_hash']), Jcs::encode($envelope) . PHP_EOL, null, true);
    }

    private static function decodeEnvelope(string $bytes, string $label): array
    {
        try { $data = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new RuntimeException('Invalid MCMA JSON: ' . $label, 0, $e); }
        if (!is_array($data)) throw new RuntimeException('MCMA envelope must be a JSON object'); return $data;
    }

    private static function indexEntryFromPayload(string $objectId, string $storageHash, array $payload): array
    {
        $metadata = $payload['metadata'] ?? []; if (!is_array($metadata) || !isset($metadata['logical_refs']) || !is_array($metadata['logical_refs'])) throw new RuntimeException('Payload lacks logical references');
        return ['object_id' => $objectId, 'storage_hash' => $storageHash, 'logical_refs' => $metadata['logical_refs'], 'temperature' => (string)($metadata['temperature'] ?? 'cold'), 'cognitive_layer' => (string)($metadata['cognitive_layer'] ?? '40-semantic'), 'scope' => (string)($metadata['scope'] ?? 'user')];
    }
    private static function sortEntries(array &$entries): void { usort($entries, static fn(array $a, array $b): int => strcmp((string)($a['logical_refs'][0] ?? ''), (string)($b['logical_refs'][0] ?? ''))); }
    private static function historicalFingerprint(array $sourceEnvelope): string { $copy = ['format'=>$sourceEnvelope['format']??null,'key_id'=>$sourceEnvelope['key_id']??null,'logical_path'=>$sourceEnvelope['logical_path']??null,'file'=>$sourceEnvelope['file']??null,'iv_b64'=>$sourceEnvelope['iv_b64']??null,'tag_b64'=>$sourceEnvelope['tag_b64']??null,'ciphertext_b64'=>$sourceEnvelope['ciphertext_b64']??null]; return 'sha256:' . hash('sha256', Jcs::encode($copy)); }
    private static function normalizeContent(mixed $content, string $format): mixed { if ($format === 'binary') { if (!is_string($content)) throw new RuntimeException('Binary content must be raw bytes'); return Crypto::b64uEncode($content); } if (in_array($format, ['text','markdown','xml'], true) && !is_string($content)) throw new RuntimeException($format . ' content must be a string'); return $content; }
    private static function validateManifestPayload(array $payload): void { foreach (['library_version','created_at','metadata_mode','root_index','entrypoints','crypto_policy','extensions'] as $field) if (!array_key_exists($field,$payload)) throw new RuntimeException('Missing manifest payload field: '.$field); if ($payload['library_version']!=='1.0'||!in_array($payload['metadata_mode'],['normal','private'],true)||!is_array($payload['root_index'])||!isset($payload['root_index']['object_id'],$payload['root_index']['storage_hash'])) throw new RuntimeException('Invalid manifest payload'); Crypto::validateObjectId((string)$payload['root_index']['object_id']); if(!preg_match('/^sha256:[0-9a-f]{64}$/',(string)$payload['root_index']['storage_hash'])) throw new RuntimeException('Invalid manifest root index hash'); }
    private static function validateIndexEntry(array $entry): void { foreach(['object_id','storage_hash','logical_refs'] as $field) if(!array_key_exists($field,$entry)) throw new RuntimeException('Malformed index entry'); Crypto::validateObjectId((string)$entry['object_id']); if(!preg_match('/^sha256:[0-9a-f]{64}$/',(string)$entry['storage_hash'])||!is_array($entry['logical_refs'])||$entry['logical_refs']===[]) throw new RuntimeException('Malformed index entry'); }
    private static function validateLogicalRef(string $ref): void { if(!preg_match('#^memory://[a-z][a-z0-9-]{0,31}(?:/[a-z0-9][a-z0-9._-]{0,127})+$#',$ref)) throw new RuntimeException('Invalid canonical memory:// reference'); }
    private static function validateMetadata(string $format,string $temperature,string $layer,string $scope,string $maturity): void { if(!in_array($format,['json','xml','text','markdown','binary'],true)) throw new RuntimeException('Unsupported content format'); if(!in_array($temperature,['hot','warm','cold','frozen'],true)) throw new RuntimeException('Invalid temperature'); if(!preg_match('/^(00-system|10-self|20-working|30-episodic|40-semantic|50-procedural|60-relational|70-preferences|80-goals|90-projects|95-world-model|99-meta)$/',$layer)) throw new RuntimeException('Invalid cognitive layer'); if($scope===''||strlen($scope)>128) throw new RuntimeException('Invalid scope'); if(!in_array($maturity,['raw','observed','classified','knowledge','confirmed'],true)) throw new RuntimeException('Invalid maturity state'); }
    private static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
}
