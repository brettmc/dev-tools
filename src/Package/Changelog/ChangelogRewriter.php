<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Changelog;

use InvalidArgumentException;

/**
 * Prepares a changelog for tagging. Only the Unreleased heading line changes, so the rest of the
 * file, including its link definitions, is left as it was.
 */
final class ChangelogRewriter
{
    /**
     * @throws InvalidArgumentException when there is no Unreleased section
     */
    public function release(string $markdown, string $version, string $date): string
    {
        $result = preg_replace_callback(
            '/^## Unreleased[ \t]*(\r?\n)/mi',
            static fn (array $m): string => "## Unreleased{$m[1]}{$m[1]}## {$version} - {$date}{$m[1]}",
            $markdown,
            1,
            $count
        );
        if ($result === null || $count === 0) {
            throw new InvalidArgumentException('No "## Unreleased" section found');
        }

        return $result;
    }
}
