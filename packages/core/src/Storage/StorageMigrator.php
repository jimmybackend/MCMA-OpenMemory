<?php
declare(strict_types=1);

namespace MCMA\Core\Storage;

use RuntimeException;

final class StorageMigrator
{
    public static function copy(StorageAdapter $source, StorageAdapter $destination): array
    {
        if (!$source->exists('manifest.mcma')) throw new RuntimeException('Source is not an MCMA library: manifest.mcma missing');
        if ($destination->exists('manifest.mcma')) throw new RuntimeException('Destination already contains manifest.mcma');

        $objects = $source->list('objects/');
        $copied = 0;
        foreach ($objects as $locator) {
            $sourceObject = $source->get($locator);
            $destination->put($locator, $sourceObject['bytes'], null, true);
            $roundTrip = $destination->get($locator);
            if (!hash_equals(hash('sha256', $sourceObject['bytes']), hash('sha256', $roundTrip['bytes']))) throw new RuntimeException('Provider migration byte mismatch: ' . $locator);
            $copied++;
        }

        // Publish the library only after all referenced/content-addressed objects are copied.
        $manifest = $source->get('manifest.mcma');
        $destination->put('manifest.mcma', $manifest['bytes'], null, true);
        $manifestCopy = $destination->get('manifest.mcma');
        if (!hash_equals(hash('sha256', $manifest['bytes']), hash('sha256', $manifestCopy['bytes']))) throw new RuntimeException('Provider migration manifest byte mismatch');

        return ['objects_copied' => $copied, 'manifest_copied' => true, 'source' => $source->id(), 'destination' => $destination->id()];
    }
}
