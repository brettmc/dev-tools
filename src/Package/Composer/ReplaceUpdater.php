<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer;

use RuntimeException;

/**
 * Keeps the `replace` of a monorepo's root composer.json in step with the branch aliases of its split
 * packages. Inside the monorepo composer resolves a split package to its `replace` entry, so that
 * entry is the version a `#[PackageDependency]` on the package is checked against at runtime.
 *
 * Only the values change, as in BranchAliasUpdater, so the file's formatting survives.
 */
class ReplaceUpdater
{
    private const KEY = 'replace';

    /**
     * @param array<string, string> $versions new version by package name; packages not already in
     *        `replace` are ignored
     * @return array<string, array{0: string, 1: string}> previous and new version of each entry changed
     */
    public function update(string $composerFilePath, array $versions, bool $dryRun = false): array
    {
        $contents = (string) file_get_contents($composerFilePath);
        $config = json_decode($contents, true);
        $replace = is_array($config) && is_array($config[self::KEY] ?? null) ? $config[self::KEY] : [];

        $changes = [];
        foreach ($versions as $package => $version) {
            $current = $replace[$package] ?? null;
            if (is_string($current) && $current !== $version) {
                $changes[$package] = [$current, $version];
            }
        }
        if ($changes === []) {
            return [];
        }

        //replace holds only strings, so its object ends at the first closing brace
        if (!preg_match('/"' . self::KEY . '"\s*:\s*\{[^}]*\}/', $contents, $block, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException("Could not find \"replace\" in {$composerFilePath}");
        }
        [$text, $offset] = $block[0];
        foreach ($changes as $package => [$current, $version]) {
            $pattern = sprintf('/("%s"\s*:\s*)"%s"/', preg_quote($package, '/'), preg_quote($current, '/'));
            $text = (string) preg_replace($pattern, '${1}"' . $version . '"', $text, 1);
        }
        $updated = substr_replace($contents, $text, $offset, strlen($block[0][0]));

        $expected = $config;
        foreach ($changes as $package => [, $version]) {
            $expected[self::KEY][$package] = $version;
        }
        if (json_decode($updated, true) !== $expected) {
            throw new RuntimeException("Could not rewrite \"replace\" in {$composerFilePath} without changing anything else, update it by hand");
        }
        if (!$dryRun && @file_put_contents($composerFilePath, $updated) !== strlen($updated)) {
            throw new RuntimeException("Could not write {$composerFilePath}");
        }

        return $changes;
    }
}
