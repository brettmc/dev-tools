<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Util;

class Path
{
    /**
     * $path made absolute against the working directory, with `.` and `..` collapsed textually and
     * no trailing slash. A stream wrapper URL (vfs:// in the tests) is already absolute.
     */
    public static function absolute(string $path): string
    {
        $scheme = self::scheme($path);
        if ($scheme !== '') {
            $path = substr($path, strlen($scheme));
        } elseif (!str_starts_with($path, '/')) {
            $path = (getcwd() ?: throw new \RuntimeException('The working directory cannot be read')) . "/{$path}";
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return $scheme . ($scheme === '' ? '/' : '') . implode('/', $segments);
    }

    /**
     * The directory $path, by default the working directory, made absolute; null when it is not a
     * directory.
     */
    public static function directory(?string $path): ?string
    {
        $absolute = self::absolute($path ?? '.');

        return is_dir($absolute) ? $absolute : null;
    }

    /**
     * The file $name in the split package at $prefix, or null when it lies outside $root.
     *
     * The prefixes are read from the checkout's own .gitsplit.yml, which is committed content: a
     * prefix of `../other-project`, or one that is a symlink pointing out of the tree, would
     * otherwise let a command rewrite a file that the caller never selected with --path.
     */
    public static function contained(string $root, string $prefix, string $name): ?string
    {
        $canonicalRoot = self::canonical($root);
        $canonicalTarget = self::canonical("{$root}/{$prefix}/{$name}");

        return str_starts_with($canonicalTarget, rtrim($canonicalRoot, '/') . '/') ? $canonicalTarget : null;
    }

    /**
     * $path with the symlinks of its deepest existing ancestor resolved. realpath() alone returns
     * false for a file not written yet, and for every stream wrapper.
     */
    private static function canonical(string $path): string
    {
        $absolute = self::absolute($path);
        if (self::scheme($absolute) !== '') {
            return $absolute;
        }
        $existing = $absolute;
        $missing = '';
        $real = realpath($existing);
        //ends at `/` at worst, which always exists
        while ($real === false) {
            $missing = '/' . basename($existing) . $missing;
            $existing = dirname($existing);
            $real = realpath($existing);
        }

        return rtrim($real, '/') . $missing;
    }

    private static function scheme(string $path): string
    {
        return preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $path, $matches) === 1 ? $matches[0] : '';
    }
}
