<?php

declare(strict_types=1);

namespace Fixture;

use Nevay\SPI\ServiceProviderDependency\PackageDependency;

#[PackageDependency(version: '^7.0', package: 'symfony/dotenv')]
final class ProviderWithNamedArguments
{
}
