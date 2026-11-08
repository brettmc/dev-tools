<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Release;

use OpenTelemetry\DevTools\Package\Changelog\MarkdownText;
use OpenTelemetry\DevTools\Package\Release\ReleaseOrderReport;

/**
 * The body of a release dashboard issue: what `release:prep` would release, and why. Short by
 * default: a table with counts per section, the entries a maintainer must check, and the rest of
 * the entries collapsed per package.
 */
final class Dashboard
{
    /** the most an issue body can hold, in characters. Bytes are counted, which is never fewer. */
    public const LIMIT = 65536;

    private const COUNT_LABELS = [
        'Breaking changes' => 'breaking',
    ];

    /**
     * @param string $repository the monorepo, `org/project`, whose pull requests and issues `#12` links to
     */
    public function __construct(
        private readonly string $repository,
        private readonly string $branch,
        private readonly string $sha,
    ) {
    }

    /**
     * @param array<PrepEntry> $entries
     */
    public function render(array $entries, ReleaseOrderReport $order): string
    {
        $blocks = $this->summary($entries, $order);
        $details = array_map(fn (PrepEntry $entry): string => $this->details($entry), $this->changed($entries));
        $body = $this->join([...$blocks, ...$details]);
        if (strlen($body) <= self::LIMIT) {
            return $body;
        }

        return $this->join([...$blocks, 'The entries are left out, as they would make this too long for an issue.']);
    }

    /**
     * @param array<PrepEntry> $entries
     * @return list<string>
     */
    private function summary(array $entries, ReleaseOrderReport $order): array
    {
        $released = count(array_filter($entries, static fn (PrepEntry $entry): bool => $entry->version !== null));
        $blocks = [sprintf(
            '%s from `%s` at %s.',
            match ($released) {
                0 => 'Nothing would be released',
                1 => '1 package would be released',
                default => "{$released} packages would be released",
            },
            $this->branch,
            $this->sha,
        )];

        $changed = $this->changed($entries);
        if ($changed !== []) {
            $rows = ['| Package | Latest | Next | Changes |', '|---|---|---|---|'];
            foreach ($changed as $entry) {
                $rows[] = sprintf(
                    '| %s | %s | %s | %s |',
                    $this->name($entry),
                    $entry->latest ?? 'none',
                    $entry->version ?? $entry->reason,
                    $this->counts($entry) ?? $entry->reason,
                );
            }
            $blocks[] = implode("\n", $rows);
        }
        if ($order->order !== []) {
            $names = $this->names($entries);
            $blocks[] = 'Tag order: ' . implode(', ', array_map(static fn (string $package): string => $names[$package] ?? $package, $order->order)) . '.';
        }
        $unchanged = array_filter($entries, static fn (PrepEntry $entry): bool => $entry->changes?->isEmpty() === true && $entry->version === null);
        $unchanged !== [] && $blocks[] = 'No changes: ' . implode(', ', array_map($this->name(...), $unchanged)) . '.';
        $untracked = array_filter($entries, static fn (PrepEntry $entry): bool => $entry->changes === null);
        $untracked !== [] && $blocks[] = 'No CHANGELOG.md: ' . implode(', ', array_map($this->name(...), $untracked)) . '.';

        $warnings = [];
        foreach ($entries as $entry) {
            foreach ($entry->warnings as $warning) {
                $warnings[] = "- {$this->name($entry)}: {$this->shorten($warning, $entry)}";
            }
        }
        $warnings !== [] && $blocks[] = "### Check before releasing\n\n" . implode("\n", $warnings);
        if ($order->warnings !== []) {
            $blocks[] = "### Check the constraints between packages\n\n" . implode("\n", array_map(static fn (string $warning): string => "- {$warning}", $order->warnings));
        }
        $undecided = array_filter($entries, static fn (PrepEntry $entry): bool => $entry->undecided && $entry->version === null);
        if ($undecided !== []) {
            $blocks[] = "### No version suggested\n\nChoose one with `--set=<package>=<version>`:\n\n"
                . implode("\n", array_map(fn (PrepEntry $entry): string => "- {$this->name($entry)}", $undecided));
        }

        return $blocks;
    }

    /**
     * Packages with something to release: changelog entries, or a version without them, as from a
     * version file or --set.
     *
     * @param array<PrepEntry> $entries
     * @return list<PrepEntry>
     */
    private function changed(array $entries): array
    {
        return array_values(array_filter(
            $entries,
            static fn (PrepEntry $entry): bool => $entry->version !== null || $entry->changes?->isEmpty() === false,
        ));
    }

    /**
     * "1 breaking, 2 added", or null when there are no entries.
     */
    private function counts(PrepEntry $entry): ?string
    {
        $counts = [];
        foreach ($entry->changes?->all() ?? [] as $section => $items) {
            $counts[] = sprintf('%d %s', count($items), self::COUNT_LABELS[$section] ?? strtolower($section));
        }

        return $counts === [] ? null : implode(', ', $counts);
    }

    private function details(PrepEntry $entry): string
    {
        $sections = [];
        foreach ($entry->changes?->all() ?? [] as $section => $items) {
            $lines = array_map(fn (string $item): string => '- ' . str_replace("\n", "\n  ", $this->markdown($item, $entry)), $items);
            $sections[] = "#### {$section}\n\n" . implode("\n", $lines);
        }
        $summary = $entry->version === null ? $this->name($entry) : "{$this->name($entry)} {$entry->version}";

        return "<details><summary>{$summary}</summary>\n\n" . implode("\n\n", $sections) . "\n\n</details>";
    }

    /**
     * The first sentence of $warning, with the references the rest of it makes, on one line.
     */
    private function shorten(string $warning, PrepEntry $entry): string
    {
        $first = MarkdownText::firstSentence($warning);
        $rest = substr($warning, strlen($first));
        $line = $this->markdown($first, $entry);
        if (trim($rest) !== '') {
            $line .= ' ...';
            $links = $entry->links;
            $dropped = $links === null ? [] : array_diff($links->references($rest), $links->references($first));
            if ($links !== null && $dropped !== []) {
                $line .= ' (' . implode(', ', array_map(fn (string $label): string => $links->link($label, $this->repository), $dropped)) . ')';
            }
        }

        return (string) preg_replace('/\s*\n\s*/', ' ', $line);
    }

    /**
     * $text with its references resolved, and HTML outside code escaped so an entry cannot end the
     * `<details>` it is in.
     */
    private function markdown(string $text, PrepEntry $entry): string
    {
        $text = $entry->links?->inline($text, $this->repository) ?? $text;

        return MarkdownText::outsideCode($text, static fn (string $run): string => str_replace(['<', '>'], ['&lt;', '&gt;'], $run));
    }

    private function name(PrepEntry $entry): string
    {
        return $entry->repository->downstream->project;
    }

    /**
     * @param array<PrepEntry> $entries
     * @return array<string, string> short name by package name
     */
    private function names(array $entries): array
    {
        $names = [];
        foreach ($entries as $entry) {
            $names[$entry->package] = $this->name($entry);
        }

        return $names;
    }

    /**
     * @param list<string> $blocks
     */
    private function join(array $blocks): string
    {
        return implode("\n\n", $blocks) . "\n";
    }
}
