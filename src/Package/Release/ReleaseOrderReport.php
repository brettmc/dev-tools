<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Release;

final class ReleaseOrderReport
{
    /**
     * @param list<string> $order every package being released, in the order to tag them
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly array $order = [],
        public readonly array $warnings = [],
    ) {
    }
}
