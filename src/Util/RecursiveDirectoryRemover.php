<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Util;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class RecursiveDirectoryRemover
{
    public static function create(): self
    {
        return new self();
    }

    public function remove(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $result = true;

        foreach (self::createIterator($directory) as $fileInfo) {
            $path = $fileInfo->getPathname();
            $action = is_dir($path) && !is_link($path) ? 'rmdir' : 'unlink';
            $success = $action($fileInfo->getPathname());
            $result = $success ? $result : false;
        }

        $success = rmdir($directory);

        return $success ? $result : false;
    }

    private static function createIterator(string $directory): RecursiveIteratorIterator
    {
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
    }
}
