<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Release;

/**
 * The body of a GitHub release: the dated section of the package's CHANGELOG.md, and a compare link.
 */
final class ReleaseNotes
{
    /**
     * @param string|null $changelog the section of the package's CHANGELOG.md for $version. Null
     *        only in a dry run, which has warned about it.
     */
    public function build(string $downstream, ?string $latest, string $version, ?string $changelog): string
    {
        if ($latest === null) {
            return $changelog ?? 'Initial release';
        }
        $notes = [];
        if ($changelog !== null) {
            $notes[] = $changelog;
        }
        $notes[] = '';
        $notes[] = "**Full Changelog**: https://github.com/{$downstream}/compare/{$latest}...{$version}";

        return implode(PHP_EOL, $notes);
    }
}
