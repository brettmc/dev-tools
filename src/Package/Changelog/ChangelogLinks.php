<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Changelog;

/**
 * Resolves the reference links of a changelog (`[#12]`, defined as `[#12]: https://...` at the foot
 * of the file) in text taken out of it, where the definitions would not follow.
 */
final class ChangelogLinks
{
    private const DEFINITION = '/^\[([^\]]+)\]:\s*(\S+)/';
    /** a backslash before ASCII punctuation, which makes it literal */
    private const ESCAPE = '\\\\[!-\/:-@\[-`{-~]';
    /**
     * a shortcut reference: not part of `[text][label]` or `[text](url)`. Escapes are matched too,
     * without a label, so `\[` opens nothing and `\\[` still does.
     */
    private const REFERENCE = '/' . self::ESCAPE . '|(?<!\])\[([^\[\]]+)\](?![\[(])/';
    /** an inline destination, which may hold parentheses as long as they are balanced */
    private const DESTINATION = '(?<destination>(?:[^\s()<>]++|(?<nested>\((?:[^()]++|(?&nested))*+\)))++)';
    /**
     * an inline link with an optional title, or a full, collapsed or shortcut reference. A `[text]`
     * that opens no inline link is a shortcut, as markdown reads it.
     */
    private const LINK = '/\[(?<text>[^\[\]]*)\](?:\(\s*(?:<(?<angled>[^<>\n]*)>|' . self::DESTINATION . ')(?:\s+(?:"[^"]*"|\'[^\']*\'|\([^()]*\)))?\s*\)|\[(?<label>[^\[\]]*)\])?/';
    /** a code span, or an escape, neither of which can open or close a link */
    private const CODE_OR_ESCAPE = '/(`+).*?\1|' . self::ESCAPE . '/';

    /** @var array<string, string> URL by lowercased label */
    private array $urls = [];

    public function __construct(string $changelog)
    {
        $fences = new CodeFences();
        foreach (preg_split('/\R/', $changelog) ?: [] as $index => $line) {
            if (!$fences->read($line, $index + 1) && preg_match(self::DEFINITION, $line, $m) === 1) {
                $this->urls[strtolower($m[1])] ??= $m[2];
            }
        }
    }

    /**
     * $text with each defined reference made an inline link. One to a pull request or issue of
     * $repository (`org/project`) becomes a bare `#12`, which GitHub links itself within that
     * repository.
     */
    public function inline(string $text, string $repository): string
    {
        return MarkdownText::outsideCode($text, fn (string $run): string => (string) preg_replace_callback(
            self::REFERENCE,
            fn (array $m): string => isset($m[1]) ? $this->resolve($m[0], $m[1], $repository) : $m[0],
            $run
        ));
    }

    /**
     * The labels of the defined references in $text, outside code, in the order they appear.
     *
     * @return list<string>
     */
    public function references(string $text): array
    {
        $labels = [];
        MarkdownText::outsideCode($text, function (string $run) use (&$labels): string {
            preg_match_all(self::REFERENCE, $run, $m);
            foreach ($m[1] as $label) {
                isset($this->urls[strtolower($label)]) && $labels[] = $label;
            }

            return $run;
        });

        return array_values(array_unique($labels));
    }

    /**
     * Whether $text, outside code, links to $url: by a reference defined as $url, or inline. A
     * link's text may hold code, so code spans are blanked out rather than split on.
     */
    public function linksTo(string $text, string $url): bool
    {
        $fences = new CodeFences();
        foreach (explode("\n", $text) as $index => $line) {
            if ($fences->read($line, $index + 1)) {
                continue;
            }
            preg_match_all(self::LINK, (string) preg_replace(self::CODE_OR_ESCAPE, "\0", $line), $links, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
            foreach ($links as $link) {
                $label = $link['label'] ?? '';
                $label = $label === '' ? (string) $link['text'] : $label;
                if (($link['angled'] ?? $link['destination'] ?? $this->urls[strtolower($label)] ?? null) === $url) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The reference `[$label]` as an inline link, or a bare `#12` for a pull request or issue of $repository.
     */
    public function link(string $label, string $repository): string
    {
        return $this->resolve("[{$label}]", $label, $repository);
    }

    private function resolve(string $reference, string $label, string $repository): string
    {
        $url = $this->urls[strtolower($label)] ?? null;
        if ($url === null) {
            return $reference;
        }
        $own = sprintf('#^https://github\.com/%s/(?:pull|issues)/(\d+)$#', preg_quote($repository, '#'));
        if (preg_match($own, $url, $number) === 1 && $label === "#{$number[1]}") {
            return $label;
        }

        return "[{$label}]({$url})";
    }
}
