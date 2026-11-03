<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Release;

/**
 * The body of a GitHub release. The dated section of the package's CHANGELOG.md is preferred, as it
 * is written by the people who made the change. The merged pull request titles are the fallback.
 */
final class ReleaseNotes
{
    /**
     * @param array<Commit> $commits unreleased commits of the package
     * @param string|null $changelog notes from the package's CHANGELOG.md, when it has a section for $version
     */
    public function build(string $downstream, ?string $latest, string $version, array $commits, ?string $changelog): string
    {
        if ($latest === null) {
            return $changelog ?? 'Initial release';
        }
        $notes = [];
        if ($changelog !== null) {
            $notes[] = $changelog;
        } else {
            $notes[] = "What's Changed:";
            foreach ($commits as $commit) {
                $notes[] = "* {$commit->pullRequest->title} by @{$commit->pullRequest->author} in [{$commit->pullRequest->id}]({$commit->pullRequest->url})";
            }
        }
        $notes[] = '';
        $notes[] = "**Full Changelog**: https://github.com/{$downstream}/compare/{$latest}...{$version}";

        return implode(PHP_EOL, $notes);
    }
}
