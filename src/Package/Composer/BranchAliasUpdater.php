<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer;

/**
 * Keeps `extra.branch-alias` in a package's composer.json in step with its released versions.
 *
 * Composer only applies a branch alias to a dev branch: a tagged release takes its version from the
 * tag, and the alias committed in that tag is ignored. The alias therefore describes the version
 * line that the branch belongs to, and is updated after a release rather than before it.
 */
class BranchAliasUpdater
{
    /**
     * The alias for a released version, eg `1.10.0` or `v1.10.0` becomes `1.10.x-dev`.
     * Returns null for a version that does not start with major.minor.
     */
    public static function aliasForVersion(string $version): ?string
    {
        if (!preg_match('/^v?(\d+)\.(\d+)/', $version, $matches)) {
            return null;
        }

        return "{$matches[1]}.{$matches[2]}.x-dev";
    }

    /**
     * Point `extra.branch-alias.<branchKey>` at $alias, replacing only that value so that the rest
     * of the file (formatting, key order, comments composer itself would drop) survives untouched.
     */
    public function update(string $composerFilePath, string $branchKey, string $alias, bool $dryRun = false): BranchAliasUpdate
    {
        if (!file_exists($composerFilePath)) {
            return new BranchAliasUpdate(BranchAliasStatus::NotFound);
        }
        $extra = PackageAttributeResolver::create($composerFilePath)->resolve(ConfigAttributes::EXTRA);
        $current = is_array($extra)
            ? ($extra[ConfigAttributes::BRANCH_ALIAS][$branchKey] ?? null)
            : null;
        if (!is_string($current)) {
            //not aliased: inserting one would mean re-encoding the file, so leave it for a human
            return new BranchAliasUpdate(BranchAliasStatus::Missing);
        }
        if ($current === $alias) {
            return new BranchAliasUpdate(BranchAliasStatus::Unchanged, $current);
        }
        if ($this->isAhead($current, $alias)) {
            //an alias higher than anything released is aspirational rather than stale, and lowering
            //it would break constraints that currently resolve against the branch
            return new BranchAliasUpdate(BranchAliasStatus::Ahead, $current);
        }

        $contents = (string) file_get_contents($composerFilePath);
        $pattern = sprintf(
            '/"%s"(\s*:\s*)"%s"/',
            preg_quote($branchKey, '/'),
            preg_quote($current, '/')
        );
        if (preg_match_all($pattern, $contents) !== 1) {
            //the value we decoded is not uniquely identifiable in the raw file
            return new BranchAliasUpdate(BranchAliasStatus::Ambiguous, $current);
        }
        $updated = (string) preg_replace(
            $pattern,
            sprintf('"%s"${1}"%s"', $branchKey, $alias),
            $contents,
            1
        );
        if (!$this->onlyAliasChanged($contents, $updated, $branchKey, $alias)) {
            return new BranchAliasUpdate(BranchAliasStatus::Ambiguous, $current);
        }
        if (!$dryRun) {
            file_put_contents($composerFilePath, $updated);
        }

        return new BranchAliasUpdate(BranchAliasStatus::Updated, $current);
    }

    /**
     * Whether the existing alias describes a higher version line than the one calculated from the
     * latest release, eg an alias of `1.0.x-dev` for a package whose latest release is `0.0.5`.
     */
    private function isAhead(string $current, string $alias): bool
    {
        $currentVersion = $this->versionOf($current);
        if ($currentVersion === null) {
            return false;
        }

        return version_compare($currentVersion, (string) $this->versionOf($alias), '>');
    }

    /**
     * The comparable part of an alias, eg `1.10` for `1.10.x-dev` and `1` for `1.x-dev`.
     */
    private function versionOf(string $alias): ?string
    {
        return preg_match('/^(\d+(?:\.\d+)?)\./', $alias, $matches)
            ? $matches[1]
            : null;
    }

    /**
     * Safety net: the rewritten file must decode to the original config with nothing but the alias
     * changed.
     */
    private function onlyAliasChanged(string $before, string $after, string $branchKey, string $alias): bool
    {
        $expected = json_decode($before, true);
        $actual = json_decode($after, true);
        if (!is_array($expected) || !is_array($actual)) {
            return false;
        }
        $expected[ConfigAttributes::EXTRA][ConfigAttributes::BRANCH_ALIAS][$branchKey] = $alias;

        return $expected === $actual;
    }
}
