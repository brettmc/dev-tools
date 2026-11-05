<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Spi;

final class SplitDependencyRow
{
    /**
     * @param list<string> $problems empty when the alias and the root replace both satisfy the constraint
     */
    public function __construct(
        public readonly ProviderDependency $dependency,
        public readonly ?string $alias,
        public readonly ?string $replace,
        public readonly array $problems,
    ) {
    }
}
