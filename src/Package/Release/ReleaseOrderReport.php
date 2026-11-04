<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Release;

final class ReleaseOrderReport
{
    /**
     * @param list<string> $order packages that depend on each other's new versions, in the order to tag them
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly array $order = [],
        public readonly array $warnings = [],
    ) {
    }
}
