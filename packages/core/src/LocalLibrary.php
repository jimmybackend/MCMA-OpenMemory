<?php
declare(strict_types=1);

namespace MCMA\Core;

use MCMA\Core\Storage\LocalFilesystemAdapter;

final class LocalLibrary
{
    public static function init(string $root, string $metadataMode = 'private'): Library { return Library::init(new LocalFilesystemAdapter(rtrim($root, DIRECTORY_SEPARATOR)), $metadataMode); }
    public static function open(string $root): Library { return Library::open(new LocalFilesystemAdapter(rtrim($root, DIRECTORY_SEPARATOR))); }
    public static function objectPath(string $root, string $storageHash): string { return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, Library::objectLocator($storageHash)); }
}
