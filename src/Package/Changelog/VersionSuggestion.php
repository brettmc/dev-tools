<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Changelog;

final class VersionSuggestion
{
    /**
     * @param string|null $version null when there is no default to offer
     * @param list<string> $warnings entries the maintainer should look at before accepting
     * @param bool $undecided true when there are changes to release but no default to offer, so a person has to choose
     */
    public function __construct(
        public readonly ?string $version,
        public readonly string $reason,
        public readonly array $warnings = [],
        public readonly bool $undecided = false,
    ) {
    }
}
