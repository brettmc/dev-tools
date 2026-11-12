<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Php;

use DateTimeImmutable;

/**
 * A released PHP branch (such as 8.2), with the dates php.net publishes for it.
 */
final class PhpBranch
{
    public function __construct(
        public readonly string $version,
        public readonly DateTimeImmutable $released,
        public readonly DateTimeImmutable $securitySupportEnd,
    ) {
    }
}
