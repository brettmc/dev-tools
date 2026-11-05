<?php

declare(strict_types=1);

namespace Fixture;

use Nevay\SPI\ServiceProviderDependency\PackageDependency;

#[PackageDependency('open-telemetry/exporter-otlp', '^1.5')]
#[PackageDependency('open-telemetry/transport-grpc', '^1')]
final class ProviderWithDependencies
{
}
