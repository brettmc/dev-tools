<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Changelog;

final class MarkdownText
{
    /** a code span, a run of text without backticks, or backticks that open no span */
    private const TOKEN = '/(`+).*?\1|[^`]+|`+/';

    /**
     * $text with $rewrite applied to each run of it outside code blocks and code spans, which
     * markdown shows as written.
     *
     * @param callable(string): string $rewrite
     */
    public static function outsideCode(string $text, callable $rewrite): string
    {
        $fences = new CodeFences();
        $lines = [];
        foreach (explode("\n", $text) as $index => $line) {
            $lines[] = $fences->read($line, $index + 1)
                ? $line
                : (string) preg_replace_callback(self::TOKEN, static fn (array $m): string => isset($m[1]) ? $m[0] : $rewrite($m[0]), $line);
        }

        return implode("\n", $lines);
    }

    /**
     * The first sentence of $text, up to a `.`, `!` or `?` followed by whitespace outside code, or
     * the first line when there is no such sentence end before the first code block.
     */
    public static function firstSentence(string $text): string
    {
        $prose = [];
        $fences = new CodeFences();
        foreach (explode("\n", $text) as $index => $line) {
            if ($fences->read($line, $index + 1)) {
                break;
            }
            $prose[] = $line;
        }
        $text = implode("\n", $prose);
        preg_match_all(self::TOKEN, $text, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($tokens as $token) {
            [$run, $offset] = $token[0];
            if (($token[1][0] ?? '') === '' && preg_match('/[.!?](?=\s)/', $run, $m, PREG_OFFSET_CAPTURE) === 1) {
                return substr($text, 0, $offset + $m[0][1] + 1);
            }
        }

        return explode("\n", $text, 2)[0];
    }
}
