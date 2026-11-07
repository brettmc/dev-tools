<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\GitSplit;

use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The split packages of a monorepo checkout, from its own .gitsplit.yml, and as composer sees them in
 * the tree: each package's `dev-main` alias, and the root `replace` that stands in for them while
 * developing.
 */
final class MonorepoCheckout
{
    private const GITSPLIT_FILE = '.gitsplit.yml';
    private const COMPOSER_FILE = 'composer.json';
    private const BRANCH_KEY = 'dev-main';

    /**
     * @param string $name the root composer.json name, '' when it has none. A monorepo is not named
     *        after its repository: core's is `open-telemetry/opentelemetry`.
     * @param array<string, string> $splits downstream repository (`org/project`) by prefix, in
     *        .gitsplit.yml order
     * @param array<string, ?string> $aliases `dev-main` alias by package name, in .gitsplit.yml order
     * @param array<string, string> $replace
     * @param list<string> $prefixes every split's directory, in .gitsplit.yml order
     */
    private function __construct(
        public readonly string $name,
        public readonly array $splits,
        public readonly array $aliases,
        public readonly array $replace,
        public readonly array $prefixes,
    ) {
    }

    /**
     * @throws RuntimeException when $path is not a monorepo checkout, or its .gitsplit.yml is malformed
     */
    public static function read(string $path): self
    {
        if (!self::isCheckout($path)) {
            throw new RuntimeException(sprintf('%s is not a monorepo checkout (no %s and %s found)', $path, self::GITSPLIT_FILE, self::COMPOSER_FILE));
        }
        $splits = self::splits($path . '/' . self::GITSPLIT_FILE);

        $aliases = [];
        foreach (array_keys($splits) as $prefix) {
            $composer = self::json("{$path}/{$prefix}/" . self::COMPOSER_FILE);
            if (!is_string($composer['name'] ?? null)) {
                continue;
            }
            $alias = $composer['extra']['branch-alias'][self::BRANCH_KEY] ?? null;
            $aliases[$composer['name']] = is_string($alias) ? $alias : null;
        }
        $root = self::json($path . '/' . self::COMPOSER_FILE);
        $replace = $root['replace'] ?? [];

        return new self(
            is_string($root['name'] ?? null) ? $root['name'] : '',
            $splits,
            $aliases,
            is_array($replace) ? array_filter($replace, 'is_string') : [],
            array_keys($splits),
        );
    }

    /**
     * Whether $path has the files of a monorepo checkout, malformed or not.
     */
    public static function isCheckout(string $path): bool
    {
        return is_file($path . '/' . self::GITSPLIT_FILE) && is_file($path . '/' . self::COMPOSER_FILE);
    }

    /**
     * @return array<string, string>
     */
    private static function splits(string $file): array
    {
        try {
            $config = Yaml::parse((string) file_get_contents($file));
        } catch (ParseException $e) {
            throw new RuntimeException("{$file} does not parse: {$e->getMessage()}", 0, $e);
        }
        $entries = is_array($config) ? $config['splits'] ?? [] : [];
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new RuntimeException("{$file} splits is not a list");
        }

        $splits = [];
        foreach ($entries as $index => $entry) {
            $number = $index + 1;
            $prefix = is_array($entry) ? $entry['prefix'] ?? null : null;
            if (!is_string($prefix) || trim($prefix, '/') === '') {
                throw new RuntimeException("{$file} split {$number} has no prefix");
            }
            $target = $entry['target'] ?? null;
            if (!is_string($target)) {
                throw new RuntimeException("{$file} split {$number} has no target");
            }
            //eg https://${GH_TOKEN}@github.com/opentelemetry-php/api.git
            if (preg_match('#([^/:@]+)/([^/]+?)(?:\.git)?/?$#', $target, $matches) !== 1) {
                throw new RuntimeException("{$file} split {$number} target \"{$target}\" does not name a repository");
            }
            $splits[trim($prefix, '/')] = "{$matches[1]}/{$matches[2]}";
        }

        return $splits;
    }

    private static function json(string $file): array
    {
        $json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($json) ? $json : [];
    }
}
