<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Release;

/**
 * The versions a maintainer sets in the description of a release pull request, one
 * `<package>=<version|skip>` per line in a fenced block:
 *
 *     ```overrides
 *     sdk=1.16.0
 *     ```
 *
 * The description is untrusted text. Each line goes to `release:prep` as one `--set` value, with no
 * shell in between, and invalid() names the lines that do not look like one.
 */
final class Overrides
{
    public const FENCE = '```overrides';
    private const LINE = '/^([a-z0-9][a-z0-9_.-]*\/)?[a-z0-9][a-z0-9_.-]*=(skip|[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?)$/';

    /**
     * @param list<string> $lines
     */
    public function __construct(public readonly array $lines = [])
    {
    }

    /**
     * The lines of the first overrides block in $body, blank ones dropped, each trimmed and with
     * runs of blanks made one space. The block opens at a line that is exactly the fence, and closes
     * at the next line starting with ```.
     */
    public static function fromBody(string $body): self
    {
        $lines = [];
        $inside = false;
        foreach (explode("\n", str_replace("\r", '', $body)) as $line) {
            //as in the workflow's awk, another opening fence is skipped rather than closing the block
            if (preg_match('/^' . self::FENCE . '[ \t]*$/', $line) === 1) {
                $inside = true;

                continue;
            }
            if (!$inside) {
                continue;
            }
            if (str_starts_with($line, '```')) {
                break;
            }
            $words = preg_split('/[ \t]+/', trim($line, " \t"), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $words !== [] && $lines[] = implode(' ', $words);
        }

        return new self($lines);
    }

    /**
     * @return list<string> the lines that are not `<package>=<version|skip>`
     */
    public function invalid(): array
    {
        return array_values(array_filter($this->lines, static fn (string $line): bool => preg_match(self::LINE, $line) !== 1));
    }

    public function equals(self $other): bool
    {
        return $this->lines === $other->lines;
    }
}
