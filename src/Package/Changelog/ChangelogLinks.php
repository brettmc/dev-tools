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
    /** a shortcut reference: not part of `[text][label]` or `[text](url)` */
    private const REFERENCE = '/(?<!\])\[([^\[\]]+)\](?![\[(])/';

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
            fn (array $m): string => $this->resolve($m[0], $m[1], $repository),
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
