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

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }
}
