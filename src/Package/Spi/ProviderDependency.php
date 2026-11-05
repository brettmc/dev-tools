<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Spi;

/**
 * One `#[PackageDependency]` on a service provider: the provider is only loaded when $package is
 * installed at a version matching $constraint.
 */
final class ProviderDependency
{
    /**
     * @param ?string $file the provider's source, relative to the checkout; null when it lies outside
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $package,
        public readonly string $constraint,
        public readonly ?string $file = null,
    ) {
    }
}
