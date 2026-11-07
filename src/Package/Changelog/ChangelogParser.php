<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Changelog;

use InvalidArgumentException;

/**
 * Reads the entries under `## Unreleased` in a Keep a Changelog style file.
 */
final class ChangelogParser
{
    /**
     * @throws InvalidArgumentException when there is no Unreleased section, or it has a heading that is not a ChangelogSection
     */
    public function parseUnreleased(string $markdown): UnreleasedChanges
    {
        /** @var array<string, list<string>> $entries */
        $entries = [];
        $inUnreleased = false;
        $foundUnreleased = false;
        $section = null;
        $current = null;

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^## (.+?)\s*$/', $line, $m) === 1) {
                $inUnreleased = strcasecmp($m[1], 'Unreleased') === 0;
                $foundUnreleased = $foundUnreleased || $inUnreleased;
                $section = null;
                $current = null;

                continue;
            }
            if (!$inUnreleased) {
                continue;
            }
            if (preg_match('/^### (.+?)\s*$/', $line, $m) === 1) {
                $section = ChangelogSection::tryFrom($m[1])
                    ?? throw new InvalidArgumentException(sprintf('Unknown section "%s" under Unreleased', $m[1]));
                $current = null;

                continue;
            }
            if ($section === null) {
                continue;
            }
            if (preg_match('/^- (.*)$/', $line, $m) === 1) {
                $entries[$section->value][] = $m[1];
                $current = array_key_last($entries[$section->value]);
            } elseif ($current !== null && preg_match('/^\s+- (.*)$/', $line, $m) === 1) {
                $entries[$section->value][$current] .= "\n- " . $m[1];
            } elseif ($current !== null && preg_match('/^\s+(\S.*)$/', $line, $m) === 1) {
                $entries[$section->value][$current] .= ' ' . $m[1];
            } else {
                $current = null;
            }
        }
        if (!$foundUnreleased) {
            throw new InvalidArgumentException('No "## Unreleased" section found');
        }

        return new UnreleasedChanges($entries);
    }
}
