<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Changelog;

final class UnreleasedChanges
{
    /**
     * @param array<string, list<string>> $entries entries keyed by ChangelogSection value
     */
    public function __construct(private readonly array $entries)
    {
    }

    /**
     * @return list<string>
     */
    public function entries(ChangelogSection $section): array
    {
        return $this->entries[$section->value] ?? [];
    }

    /**
     * @return array<string, list<string>> entries keyed by ChangelogSection value, in the order of
     *         ChangelogSection, so breaking changes come first whatever order the file has
     */
    public function all(): array
    {
        $all = [];
        foreach (ChangelogSection::cases() as $section) {
            if (($this->entries[$section->value] ?? []) !== []) {
                $all[$section->value] = $this->entries[$section->value];
            }
        }

        return $all;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }
}
