<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Changelog;

/**
 * Suggests the next version from the Unreleased entries of a changelog.
 *
 * Breaking changes suggest a minor, not a major: the project ships some in 1.x minors, so the
 * entries are returned as warnings and the maintainer decides. A major is never suggested.
 */
final class VersionSuggester
{
    private const MINOR_REASONS = [
        ['section' => ChangelogSection::BreakingChanges, 'reason' => 'breaking changes recorded'],
        ['section' => ChangelogSection::Removed, 'reason' => 'removals recorded'],
        ['section' => ChangelogSection::Added, 'reason' => 'new features'],
        ['section' => ChangelogSection::Changed, 'reason' => 'changes to existing behaviour'],
        ['section' => ChangelogSection::Deprecated, 'reason' => 'deprecations'],
    ];

    public function suggest(string $latest, UnreleasedChanges $changes, ?VersionSource $source = null): VersionSuggestion
    {
        if ($source === null && $changes->isEmpty()) {
            return new VersionSuggestion(null, 'no unreleased changes');
        }
        if (preg_match('/^v?(\d+)\.(\d+)\.(\d+)(.*)$/', $latest, $m) !== 1) {
            return new VersionSuggestion(null, sprintf('cannot parse latest release "%s"', $latest), [], true);
        }
        if ($m[4] !== '') {
            return new VersionSuggestion(null, sprintf('latest release %s is a pre-release', $latest), [], true);
        }
        [, $major, $minor, $patch] = $m;

        if ($source !== null) {
            if (version_compare($source->version, "{$major}.{$minor}.{$patch}", '>')) {
                return new VersionSuggestion($source->version, 'version source is newer than the latest release');
            }

            return new VersionSuggestion(null, 'version source is not newer than the latest release');
        }

        $warnings = [
            ...$changes->entries(ChangelogSection::BreakingChanges),
            ...$changes->entries(ChangelogSection::Removed),
        ];
        foreach (self::MINOR_REASONS as ['section' => $section, 'reason' => $reason]) {
            if ($changes->entries($section) !== []) {
                return new VersionSuggestion(
                    sprintf('%d.%d.0', $major, (int) $minor + 1),
                    $reason,
                    $warnings,
                );
            }
        }

        return new VersionSuggestion(sprintf('%d.%d.%d', $major, $minor, (int) $patch + 1), 'only fixes');
    }
}
