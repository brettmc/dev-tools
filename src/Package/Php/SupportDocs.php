<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Php;

use RuntimeException;

/**
 * Generates the PHP support text of a project's docs, and writes it between markers:
 *
 *     <!-- php-support-badges:start -->
 *     <!-- php-support-badges:end -->
 *
 * The blocks are `php-support-badges` (one badge per supported branch), `php-support-summary`
 * (the lowest supported branch and its date) and `php-support-table` (all supported branches).
 */
final class SupportDocs
{
    public const DEFAULT_POLICY_LINK = 'docs/versioning.md#php-version-support';

    public function __construct(private readonly string $policyLink = self::DEFAULT_POLICY_LINK)
    {
    }

    /**
     * @param string $file the file the blocks are for, relative to the project: the policy link is relative to
     *                     the project too, and is made relative to the file
     * @return array<string, string> the text of each block, by name
     */
    public function blocks(SupportReport $report, string $file = ''): array
    {
        $supported = $report->supported();
        if ($supported === []) {
            throw new RuntimeException('No PHP version is supported: none of the released branches is in the CI matrix');
        }

        $link = $this->linkFrom($file);

        return [
            'php-support-badges' => $this->badges($supported, $link),
            'php-support-summary' => $this->summary($supported[0], $link),
            'php-support-table' => $this->table($supported),
        ];
    }

    /**
     * Replaces the text of every block that has markers in $text.
     *
     * @param array<string, string> $blocks
     * @throws RuntimeException when $text has no block at all, or a block whose markers are missing, repeated or out of order
     */
    public function apply(string $text, array $blocks): string
    {
        $found = 0;
        foreach ($blocks as $name => $content) {
            $start = "<!-- {$name}:start -->";
            $end = "<!-- {$name}:end -->";
            //keep the line endings of the file
            $eol = str_contains($text, "\r\n") ? "\r\n" : "\n";
            $content = str_replace("\n", $eol, $content);
            $starts = substr_count($text, $start);
            $ends = substr_count($text, $end);
            if ($starts === 0 && $ends === 0) {
                continue;
            }
            if ($starts !== 1 || $ends !== 1 || strpos($text, $start) > strpos($text, $end)) {
                throw new RuntimeException("The {$name} block needs its markers {$start} and {$end} exactly once, in that order");
            }
            $found++;
            $replaced = preg_replace_callback(
                '/' . preg_quote($start, '/') . '.*?' . preg_quote($end, '/') . '/s',
                static fn (): string => "{$start}{$eol}{$content}{$eol}{$end}",
                $text,
            );
            if ($replaced === null) {
                throw new RuntimeException("Could not replace the {$name} block: " . preg_last_error_msg());
            }
            $text = $replaced;
        }
        if ($found === 0) {
            throw new RuntimeException('No PHP support block found, expected markers such as <!-- php-support-table:start -->');
        }

        return $text;
    }

    /**
     * The policy link as seen from a file: a link in a subdirectory climbs back to the project root first.
     * A URL, an absolute path or an anchor is kept as it is.
     */
    private function linkFrom(string $file): string
    {
        $file = (string) preg_replace('~^(?:\./)+~', '', $file);
        $dir = dirname($file);
        //a file outside the project, given by an absolute path, has no place to link from
        if ($file === '' || $file[0] === '/' || $dir === '.' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|/|#)~i', $this->policyLink)) {
            return $this->policyLink;
        }

        return str_repeat('../', substr_count($dir, '/') + 1) . $this->policyLink;
    }

    /**
     * @param list<SupportRow> $supported
     */
    private function badges(array $supported, string $link): string
    {
        return implode("\n", array_map(
            static fn (SupportRow $row): string => sprintf(
                '[![PHP %1$s](https://img.shields.io/badge/PHP-%1$s-777BB4?logo=php&logoColor=white)](%2$s)',
                $row->branch->version,
                $link,
            ),
            $supported,
        ));
    }

    private function summary(SupportRow $lowest, string $link): string
    {
        return sprintf(
            'The lowest supported PHP version, %s, is supported until %s. See [PHP version support](%s) for the policy and the full schedule.',
            $lowest->branch->version,
            $lowest->supportedUntil->format('Y-m-d'),
            $link,
        );
    }

    /**
     * @param list<SupportRow> $supported
     */
    private function table(array $supported): string
    {
        $lines = ['| PHP | Security support ends | Supported until |', '|-----|-----------------------|-----------------|'];
        foreach ($supported as $row) {
            $lines[] = sprintf(
                '| %s | %s | %s |',
                $row->branch->version,
                $row->branch->securitySupportEnd->format('Y-m-d'),
                $row->supportedUntil->format('Y-m-d'),
            );
        }

        return implode("\n", $lines);
    }
}
