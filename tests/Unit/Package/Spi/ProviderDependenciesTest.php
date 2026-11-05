<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Spi;

use OpenTelemetry\DevTools\Package\Spi\ProviderDependencies;
use OpenTelemetry\DevTools\Package\Spi\ProviderDependency;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Spi\ProviderDependencies
 */
class ProviderDependenciesTest extends TestCase
{
    public function test_constraints_are_grouped_by_the_split_prefix_of_the_provider(): void
    {
        $dependencies = new ProviderDependencies([
            new ProviderDependency('A', 'o/exporter-otlp', '^1.0.5', 'src/Config/SDK/A.php'),
            new ProviderDependency('B', 'o/exporter-otlp', '^1.5', 'src/Config/SDK/B.php'),
            new ProviderDependency('C', 'o/transport-grpc', '^1', 'src/Config/SDK/C.php'),
            new ProviderDependency('D', 'o/sdk-configuration', '*', 'src/SDK/D.php'),
        ]);

        $this->assertSame(
            [
                'src/Config/SDK' => ['o/exporter-otlp' => ['^1.0.5', '^1.5'], 'o/transport-grpc' => ['^1']],
                'src/SDK' => ['o/sdk-configuration' => ['*']],
            ],
            $dependencies->byPrefix(['src/SDK', 'src/Config/SDK', 'src/API'])
        );
    }

    public function test_a_nested_prefix_claims_its_own_files(): void
    {
        $dependencies = new ProviderDependencies([
            new ProviderDependency('A', 'o/api', '^1', 'src/SDK/Nested/A.php'),
            new ProviderDependency('B', 'o/api', '^2', 'src/SDK/B.php'),
        ]);

        $this->assertSame(
            ['src/SDK/Nested' => ['o/api' => ['^1']], 'src/SDK' => ['o/api' => ['^2']]],
            $dependencies->byPrefix(['src/SDK', 'src/SDK/Nested'])
        );
    }

    public function test_providers_outside_every_prefix_are_left_out(): void
    {
        $dependencies = new ProviderDependencies([
            new ProviderDependency('A', 'o/api', '^1', 'src/SDKExtra/A.php'),
            new ProviderDependency('B', 'o/api', '^1', null),
        ]);

        $this->assertSame([], $dependencies->byPrefix(['src/SDK']));
    }
}
