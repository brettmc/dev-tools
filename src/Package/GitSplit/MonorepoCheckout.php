<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\GitSplit;

use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The split packages of a monorepo checkout, from its own .gitsplit.yml, and as composer sees them in
 * the tree: each package's `dev-main` alias, and the root `replace` that stands in for them while
 * developing. The root composer.json is optional: only core's has a `replace`.
 */
final class MonorepoCheckout
{
    private const GITSPLIT_FILE = '.gitsplit.yml';
    private const COMPOSER_FILE = 'composer.json';
    private const BRANCH_KEY = 'dev-main';

    /**
     * @param ?string $repository the monorepo, `org/project`, from .gitsplit.yml's `project_url`
     * @param array<string, string> $splits downstream repository (`org/project`) by prefix, in
     *        .gitsplit.yml order
     * @param array<string, ?string> $aliases `dev-main` alias by package name, in .gitsplit.yml order
     * @param array<string, string> $replace
     * @param list<string> $prefixes every split's directory, in .gitsplit.yml order
     */
    private function __construct(
        public readonly ?string $repository,
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
            throw new RuntimeException(sprintf('%s is not a monorepo checkout (no %s found)', $path, self::GITSPLIT_FILE));
        }
        $file = $path . '/' . self::GITSPLIT_FILE;
        $config = self::gitsplit($file);
        $splits = self::splits($file, $config);

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
            self::repository($file, $config),
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
        return is_file($path . '/' . self::GITSPLIT_FILE);
    }

    private static function gitsplit(string $file): mixed
    {
        try {
            return Yaml::parse((string) file_get_contents($file));
        } catch (ParseException $e) {
            throw new RuntimeException("{$file} does not parse: {$e->getMessage()}", 0, $e);
        }
    }

    private static function repository(string $file, array $config): ?string
    {
        $url = $config['project_url'] ?? null;
        if ($url === null) {
            return null;
        }
        $repository = is_string($url) ? self::repositoryOf($url) : null;
        if ($repository === null) {
            throw new RuntimeException(sprintf('%s project_url %s does not name a repository', $file, (string) json_encode($url, JSON_UNESCAPED_SLASHES)));
        }

        return $repository;
    }

    /**
     * `org/project` from a repository URL, eg https://${GH_TOKEN}@github.com/opentelemetry-php/api.git
     */
    private static function repositoryOf(string $url): ?string
    {
        return preg_match('#([^/:@]+)/([^/]+?)(?:\.git)?/?$#', $url, $matches) === 1 ? "{$matches[1]}/{$matches[2]}" : null;
    }

    /**
     * @return array<string, string>
     */
    private static function splits(string $file, mixed $config): array
    {
        if (!is_array($config) || !array_key_exists('splits', $config)) {
            throw new RuntimeException("{$file} has no splits");
        }
        $entries = $config['splits'] ?? [];
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
            $repository = self::repositoryOf($target);
            if ($repository === null) {
                throw new RuntimeException("{$file} split {$number} target \"{$target}\" does not name a repository");
            }
            $splits[trim($prefix, '/')] = $repository;
        }

        return $splits;
    }

    private static function json(string $file): array
    {
        $json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($json) ? $json : [];
    }
}
