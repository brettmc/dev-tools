<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer\ValueObject;

use OpenTelemetry\DevTools\Package\Composer\ValueObject\DependencyCollection;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\DependencyInterface;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\PackageInterface;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\PackageTrait;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\ValueObject\PackageTrait
 */
class PackageTraitTest extends TestCase
{
    public const ATTRIBUTES = [
        PackageInterface::TYPE_ATTRIBUTE => 'library',
        PackageInterface::NAME_ATTRIBUTE => 'foo/bar',
        PackageInterface::DEPENDENCIES_ATTRIBUTE => [],
    ];

    public function test_to_array(): void
    {
        $this->assertSame(
            self::ATTRIBUTES,
            $this->createInstance()->toArray()
        );
    }

    /**
     * @psalm-suppress UndefinedInterfaceMethod
     */
    public function test_set_dependencies(): void
    {
        $instance = $this->createInstance();
        $data =[[
            DependencyInterface::VERSION_ATTRIBUTE => 'dev-main',
            DependencyInterface::PACKAGE_ATTRIBUTE => 'foo-bar',
        ]];
        $dependencies = $this->createMock(DependencyCollection::class);
        $dependencies->method('toArray')
            ->willReturn($data);
        /** @phpstan-ignore-next-line */
        $instance->setDependencies($dependencies);

        $this->assertSame(
            [
                PackageInterface::TYPE_ATTRIBUTE => 'library',
                PackageInterface::NAME_ATTRIBUTE => 'foo/bar',
                PackageInterface::DEPENDENCIES_ATTRIBUTE => $data,
            ],
            $instance->toArray()
        );
    }

    private function createInstance(): PackageInterface
    {
        return new class() implements PackageInterface {
            use PackageTrait;

            public function __construct()
            {
                $this->name = PackageTraitTest::ATTRIBUTES[PackageInterface::NAME_ATTRIBUTE];
            }

            #[\Override]
            public function getType(): string
            {
                return PackageTraitTest::ATTRIBUTES[PackageInterface::TYPE_ATTRIBUTE];
            }
        };
    }
}
