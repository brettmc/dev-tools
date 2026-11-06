<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\GitSplit;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * The split packages of a monorepo checkout as composer sees them in the tree: each package's
 * `dev-main` alias, and the root `replace` that stands in for them while developing.
 */
final class MonorepoCheckout
{
    private const GITSPLIT_FILE = '.gitsplit.yml';
    private const COMPOSER_FILE = 'composer.json';
    private const BRANCH_KEY = 'dev-main';

    /**
     * @param array<string, ?string> $aliases `dev-main` alias by package name, in .gitsplit.yml order
     * @param array<string, string> $replace
     * @param list<string> $prefixes every split's directory, in .gitsplit.yml order
     */
    private function __construct(
        public readonly array $aliases,
        public readonly array $replace,
        public readonly array $prefixes,
    ) {
    }

    public static function read(string $path): self
    {
        $gitsplitFile = $path . '/' . self::GITSPLIT_FILE;
        $rootFile = $path . '/' . self::COMPOSER_FILE;
        if (!is_file($gitsplitFile) || !is_file($rootFile)) {
            throw new RuntimeException(sprintf('%s is not a monorepo checkout (no %s and %s found)', $path, self::GITSPLIT_FILE, self::COMPOSER_FILE));
        }
        $config = Yaml::parse((string) file_get_contents($gitsplitFile));

        $aliases = [];
        $prefixes = [];
        foreach ($config['splits'] ?? [] as $split) {
            if (!is_string($split['prefix'] ?? null)) {
                continue;
            }
            $prefixes[] = trim($split['prefix'], '/');
            $composer = self::json("{$path}/{$split['prefix']}/" . self::COMPOSER_FILE);
            if (!is_string($composer['name'] ?? null)) {
                continue;
            }
            $alias = $composer['extra']['branch-alias'][self::BRANCH_KEY] ?? null;
            $aliases[$composer['name']] = is_string($alias) ? $alias : null;
        }
        $replace = self::json($rootFile)['replace'] ?? [];

        return new self($aliases, is_array($replace) ? array_filter($replace, 'is_string') : [], $prefixes);
    }

    private static function json(string $file): array
    {
        $json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($json) ? $json : [];
    }
}
