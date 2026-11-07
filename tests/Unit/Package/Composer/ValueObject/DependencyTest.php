<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer\ValueObject;

use OpenTelemetry\DevTools\Package\Composer\ValueObject\Dependency;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\DependencyInterface;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\PackageInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\ValueObject\Dependency
 */
class DependencyTest extends TestCase
{
    use UsesPackageInterfaceMockTrait;

    private const VERSION_CONSTRAINT = '^0.0.1|^0.2.1|^3.2.1|';
    public const ATTRIBUTES = [
        DependencyInterface::VERSION_ATTRIBUTE => self::VERSION_CONSTRAINT,
        DependencyInterface::PACKAGE_ATTRIBUTE => PackageAttributes::ATTRIBUTES,
    ];

    private Dependency $instance;
    private PackageInterface $package;

    #[\Override]
    public function setUp(): void
    {
        $this->instance = Dependency::create(
            self::VERSION_CONSTRAINT,
            $this->getPackage()
        );
    }

    public function test_get_version_constraint(): void
    {
        $this->assertSame(
            self::VERSION_CONSTRAINT,
            $this->instance->getVersionConstraint()
        );
    }

    public function test_get_package(): void
    {
        $this->assertSame(
            $this->getPackage(),
            $this->instance->getPackage()
        );
    }

    public function test_to_array(): void
    {
        $this->assertSame(
            self::ATTRIBUTES,
            $this->instance->toArray()
        );
    }

    /**
     * @psalm-suppress RedundantPropertyInitializationCheck
     */
    private function getPackage(): PackageInterface
    {
        return $this->package ?? $this->package = $this->createPackageInterfaceMock();
    }
}
