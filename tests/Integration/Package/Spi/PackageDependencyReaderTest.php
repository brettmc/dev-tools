<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Integration\Package\Spi;

use OpenTelemetry\DevTools\Package\Spi\PackageDependencyReader;
use OpenTelemetry\DevTools\Package\Spi\ProviderDependencies;
use OpenTelemetry\DevTools\Package\Spi\ProviderDependency;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OpenTelemetry\DevTools\Package\Spi\PackageDependencyReader
 */
class PackageDependencyReaderTest extends TestCase
{
    private const MONOREPO = __DIR__ . '/_files/monorepo';

    public function test_reads_the_attributes_of_each_provider(): void
    {
        $result = (new PackageDependencyReader())->read(self::MONOREPO);

        $this->assertEquals(
            [
                new ProviderDependency('Fixture\ProviderWithDependencies', 'open-telemetry/exporter-otlp', '^1.5', 'src/ProviderWithDependencies.php'),
                new ProviderDependency('Fixture\ProviderWithDependencies', 'open-telemetry/transport-grpc', '^1', 'src/ProviderWithDependencies.php'),
                new ProviderDependency('Fixture\ProviderWithNamedArguments', 'symfony/dotenv', '^7.0', 'src/ProviderWithNamedArguments.php'),
            ],
            $result->dependencies
        );
    }

    public function test_reports_providers_that_do_not_load(): void
    {
        $result = (new PackageDependencyReader())->read(self::MONOREPO);

        $this->assertSame(['Fixture\MissingProvider'], $result->missing);
    }

    public function test_reads_a_relative_path_from_another_working_directory(): void
    {
        $cwd = (string) getcwd();
        chdir(dirname(self::MONOREPO));

        try {
            $result = (new PackageDependencyReader())->read(basename(self::MONOREPO));
        } finally {
            chdir($cwd);
        }

        $this->assertCount(3, $result->dependencies);
        $this->assertSame('src/ProviderWithDependencies.php', $result->dependencies[0]->file);
    }

    public function test_checkout_without_spi_providers_needs_no_autoloader(): void
    {
        $result = (new PackageDependencyReader())->read(__DIR__ . '/_files/without-spi');

        $this->assertEquals(new ProviderDependencies(), $result);
    }

    public function test_checkout_without_a_composer_json_needs_no_autoloader(): void
    {
        $result = (new PackageDependencyReader())->read(__DIR__ . '/_files');

        $this->assertEquals(new ProviderDependencies(), $result);
    }

    public function test_fails_without_an_installed_vendor_directory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer install');

        (new PackageDependencyReader())->read(__DIR__ . '/_files/without-vendor');
    }
}
