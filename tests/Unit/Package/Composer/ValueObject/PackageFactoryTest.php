<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer\ValueObject;

use Generator;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\GenericPackage;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\PackageFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\ValueObject\PackageFactory
 */
class PackageFactoryTest extends TestCase
{
    public function test_create_generic_package(): void
    {
        $this->assertInstanceOf(
            GenericPackage::class,
            PackageFactory::create()->build('foo', 'bar')
        );
    }

    /**
     * @dataProvider providePackageType
     * @psalm-param class-string $class
     */
    public function test_create_specific_packages(string $type, string $class): void
    {
        $this->assertInstanceOf(
            $class,
            PackageFactory::create()->build('foo', $type)
        );
    }

    public function providePackageType(): Generator
    {
        foreach (PackageFactory::TYPES as $type => $class) {
            yield [$type, $class];
        }
    }
}
