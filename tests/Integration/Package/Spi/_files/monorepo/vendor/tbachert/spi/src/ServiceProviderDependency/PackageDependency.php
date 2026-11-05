<?php

declare(strict_types=1);

namespace Nevay\SPI\ServiceProviderDependency;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class PackageDependency
{
    public function __construct(
        private readonly string $package,
        private readonly string $version,
        private readonly bool $sealed = false,
    ) {
    }
}
