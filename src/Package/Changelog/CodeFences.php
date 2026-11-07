<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Changelog;

/**
 * Tracks fenced code blocks line by line, so that a `## ` line inside one is not taken as a heading.
 */
final class CodeFences
{
    private ?string $fence = null;
    private string $indent = '';
    private int $openedAt = 0;

    /**
     * Whether $line opens, closes or is inside a code block.
     */
    public function read(string $line, int $number): bool
    {
        if ($this->fence === null) {
            if (preg_match('/^(\s*)(`{3,}|~{3,})/', $line, $m) !== 1) {
                return false;
            }
            $this->indent = $m[1];
            $this->fence = $m[2];
            $this->openedAt = $number;

            return true;
        }
        if (preg_match('/^\s*(`{3,}|~{3,})\s*$/', $line, $m) === 1
            && $m[1][0] === $this->fence[0]
            && strlen($m[1]) >= strlen($this->fence)) {
            $this->fence = null;
        }

        return true;
    }

    /**
     * $line without the indentation of the fence that opened its block.
     */
    public function unindent(string $line): string
    {
        $length = strspn($line, " \t", 0, strlen($this->indent));

        return substr($line, $length);
    }

    /**
     * @throws ChangelogException when a code block is still open
     */
    public function end(): void
    {
        if ($this->fence !== null) {
            throw new ChangelogException('code block is not closed', $this->openedAt);
        }
    }
}
