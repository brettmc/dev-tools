<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Changelog;

/**
 * Reads the entries under `## Unreleased` in a Keep a Changelog style file.
 *
 * Anything under Unreleased that would not survive into the release notes is an error rather than
 * being dropped: a line that is not a heading, a `- ` entry or the continuation of one.
 */
final class ChangelogParser
{
    /**
     * @throws ChangelogException when there is no Unreleased section, or something under it is not an entry
     */
    public function parseUnreleased(string $markdown): UnreleasedChanges
    {
        /** @var array<string, list<string>> $entries */
        $entries = [];
        $inUnreleased = false;
        $foundUnreleased = false;
        $section = null;
        $current = null;
        $fences = new CodeFences();

        foreach (preg_split('/\R/', $markdown) ?: [] as $index => $line) {
            $number = $index + 1;
            if ($fences->read($line, $number)) {
                if ($inUnreleased && $section !== null && $current !== null) {
                    $entries[$section->value][$current] .= "\n" . $fences->unindent($line);
                } elseif ($inUnreleased) {
                    throw new ChangelogException('a code block must be part of a "- " entry', $number);
                }

                continue;
            }
            if (preg_match('/^## (.+?)\s*$/', $line, $m) === 1) {
                $inUnreleased = strcasecmp($m[1], 'Unreleased') === 0;
                if ($inUnreleased && $foundUnreleased) {
                    throw new ChangelogException('a second "## Unreleased" heading', $number);
                }
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
                    ?? throw new ChangelogException(sprintf('Unknown section "%s" under Unreleased', $m[1]), $number);
                $current = null;

                continue;
            }
            if (trim($line) === '') {
                $current = null;

                continue;
            }
            if ($section === null) {
                throw new ChangelogException('entries go under a ### heading', $number);
            }
            if (preg_match('/^- (.*)$/', $line, $m) === 1) {
                $entries[$section->value][] = $m[1];
                $current = array_key_last($entries[$section->value]);
            } elseif ($current !== null && preg_match('/^\s+- (.*)$/', $line, $m) === 1) {
                $entries[$section->value][$current] .= "\n- " . $m[1];
            } elseif ($current !== null && preg_match('/^\s+(\S.*)$/', $line, $m) === 1) {
                $entries[$section->value][$current] .= ' ' . $m[1];
            } else {
                throw new ChangelogException('expected a "- " entry, an indented continuation of one, or a ### heading', $number);
            }
        }
        $fences->end();
        if (!$foundUnreleased) {
            throw new ChangelogException('No "## Unreleased" section found');
        }

        return new UnreleasedChanges($entries);
    }
}
