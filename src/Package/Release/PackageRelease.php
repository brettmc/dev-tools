<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Release;

/**
 * A package as the release-order check sees it: the version it is about to be released as (null when
 * it is not being released), its latest tag, and its composer constraints on other packages.
 */
final class PackageRelease
{
    /**
     * @param array<string, string> $require constraint by package name
     * @param array<string, string> $conflict constraint by package name
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $version,
        public readonly ?string $latest,
        public readonly array $require = [],
        public readonly array $conflict = [],
    ) {
    }
}
