<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Php;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * An issue written as a Markdown file: YAML front matter with the `title` and `labels`, then the body.
 *
 *     ---
 *     title: "Drop support for PHP {{ version }}"
 *     labels: [php-version-support]
 *     ---
 *     PHP {{ version }} stopped receiving security fixes on {{ security_support_end }}.
 *
 * `{{ name }}` is replaced by the value of that name. A name without a value is an error, so that a
 * typo cannot end up in an issue.
 */
final class IssueTemplate
{
    /**
     * @param list<string> $labels
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $labels = [],
    ) {
    }

    public static function fromFile(string $file): self
    {
        $contents = is_file($file) ? file_get_contents($file) : false;
        if ($contents === false) {
            throw new InvalidArgumentException("Issue template {$file} not found");
        }

        try {
            return self::parse($contents);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("{$file}: {$e->getMessage()}", 0, $e);
        }
    }

    public static function parse(string $contents): self
    {
        if (!preg_match('/\A---\R(.*?)\R---(?:\R(.*))?\z/s', $contents, $matches)) {
            throw new InvalidArgumentException('expected front matter between --- lines');
        }

        try {
            $meta = Yaml::parse($matches[1]);
        } catch (ParseException $e) {
            throw new InvalidArgumentException("invalid front matter: {$e->getMessage()}", 0, $e);
        }
        $title = is_array($meta) ? ($meta['title'] ?? null) : null;
        if (!is_string($title) || trim($title) === '' || str_contains($title, "\n")) {
            throw new InvalidArgumentException('front matter needs a one-line "title"');
        }
        $labels = $meta['labels'] ?? [];
        if (!is_array($labels) || array_filter($labels, static fn ($label): bool => !is_string($label) || $label === '') !== []) {
            throw new InvalidArgumentException('"labels" must be a list of names');
        }
        if (array_filter($labels, static fn (string $label): bool => str_contains($label, ',')) !== []) {
            //the issues API reads a comma as a list of labels
            throw new InvalidArgumentException('a label cannot contain a comma');
        }

        return new self(trim($title), trim($matches[2] ?? '') . "\n", array_values($labels));
    }

    /**
     * @param array<string, string> $values
     */
    public function render(array $values): self
    {
        //GitHub keeps a title trimmed, and the issues that exist are found by their title
        return new self(trim($this->replace($this->title, $values)), $this->replace($this->body, $values), $this->labels);
    }

    /**
     * @param array<string, string> $values
     */
    private function replace(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
            static fn (array $match): string => $values[$match[1]]
                ?? throw new InvalidArgumentException("unknown placeholder {{ {$match[1]} }}, expected one of: " . implode(', ', array_keys($values))),
            $text,
        );
    }
}
