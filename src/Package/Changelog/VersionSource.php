<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Changelog;

/**
 * A version that a package takes from a file in the checkout, rather than from its changelog. The
 * package is released only when the version is newer than the latest tag, whatever the changelog says.
 */
final class VersionSource
{
    public function __construct(
        public readonly string $version,
    ) {
    }
}
