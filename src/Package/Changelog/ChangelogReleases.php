<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Changelog;

/**
 * Reads the dated release sections (`## 1.2.3 - 2026-01-31`) of a Keep a Changelog style file, as
 * written by ChangelogRewriter. Used to turn a section into release notes.
 */
final class ChangelogReleases
{
    private const HEADING = '/^## \[?v?(\d+\.\d+\.\d+[0-9A-Za-z.+-]*)\]? - \d{4}-\d{2}-\d{2}\s*$/';
    private const DEFINITION = '/^\[([^\]]+)\]:\s*\S+/';

    public function __construct(private readonly string $markdown)
    {
    }

    /**
     * The version of the first dated section, which is the newest: changelogs list newest first.
     */
    public function latest(): ?string
    {
        foreach ($this->lines() as [$line, $fenced]) {
            if (!$fenced && preg_match(self::HEADING, $line, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * The body of the section dated for $version, followed by the link definitions it uses. Those
     * live at the foot of the file, so they would otherwise be lost and `[#12]` left unresolved.
     * Null when there is no such section, or it has no body.
     */
    public function notes(string $version): ?string
    {
        $version = ltrim($version, 'v');
        $body = [];
        $inSection = false;
        foreach ($this->lines() as [$line, $fenced]) {
            if (!$fenced && str_starts_with($line, '## ')) {
                if ($inSection) {
                    break;
                }
                $inSection = preg_match(self::HEADING, $line, $m) === 1 && $m[1] === $version;

                continue;
            }
            if ($inSection && ($fenced || preg_match(self::DEFINITION, $line) !== 1)) {
                $body[] = $line;
            }
        }
        $text = trim(implode("\n", $body));
        if ($text === '') {
            return null;
        }
        $definitions = $this->definitionsUsedBy($text);

        return $definitions === [] ? $text : $text . "\n\n" . implode("\n", $definitions);
    }

    /**
     * @return list<string>
     */
    private function definitionsUsedBy(string $text): array
    {
        $used = [];
        foreach ($this->lines() as [$line, $fenced]) {
            if (!$fenced && preg_match(self::DEFINITION, $line, $m) === 1 && str_contains($text, "[{$m[1]}]")) {
                $used[] = rtrim($line);
            }
        }

        return $used;
    }

    /**
     * @return list<array{string, bool}> each line, and whether it is part of a code block
     */
    private function lines(): array
    {
        $fences = new CodeFences();
        $lines = [];
        foreach (preg_split('/\R/', $this->markdown) ?: [] as $index => $line) {
            $lines[] = [$line, $fences->read($line, $index + 1)];
        }

        return $lines;
    }
}
