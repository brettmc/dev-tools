<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer;

class BranchAliasUpdate
{
    public function __construct(
        public readonly BranchAliasStatus $status,
        public readonly ?string $current = null,
    ) {
    }
}
