<?php
declare(strict_types=1);

namespace MCMA\Core;

use RuntimeException;

final class LibraryLock
{
    public static function exclusive(string $root, callable $callback): mixed
    {
        $path = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.mcma.lock';
        $handle = @fopen($path, 'c+');
        if ($handle === false) throw new RuntimeException('Unable to open MCMA library lock');
        @chmod($path, 0600);

        $timeout = (float) (getenv('MCMA_LOCK_TIMEOUT_SECONDS') ?: '10');
        if ($timeout <= 0 || $timeout > 300) $timeout = 10.0;
        $deadline = microtime(true) + $timeout;

        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($handle);
                throw new RuntimeException('Timed out waiting for MCMA library write lock');
            }
            usleep(100000);
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, getmypid() . ' ' . gmdate('c') . PHP_EOL);
        fflush($handle);

        try {
            return $callback();
        } finally {
            ftruncate($handle, 0);
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
