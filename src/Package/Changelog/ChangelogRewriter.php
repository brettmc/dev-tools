<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Changelog;

/**
 * Prepares a changelog for tagging. Only the Unreleased heading line changes, so the rest of the
 * file, including its link definitions, is left as it was.
 */
final class ChangelogRewriter
{
    /**
     * @throws ChangelogException when there is no Unreleased section
     */
    public function release(string $markdown, string $version, string $date): string
    {
        // Odd indexes hold the line endings, so the file can be put back together byte for byte.
        $parts = preg_split('/(\r\n|\n|\r)/', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $fences = new CodeFences();
        for ($i = 0; $i < count($parts); $i += 2) {
            if ($fences->read($parts[$i], intdiv($i, 2) + 1)
                || preg_match('/^## Unreleased[ \t]*$/i', $parts[$i]) !== 1) {
                continue;
            }
            $eol = $parts[$i + 1] ?? "\n";
            $parts[$i] = "## Unreleased{$eol}{$eol}## {$version} - {$date}";

            return implode('', $parts);
        }

        throw new ChangelogException('No "## Unreleased" section found');
    }
}
