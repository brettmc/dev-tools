<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer\ValueObject;

use Generator;
use InvalidArgumentException;
use OpenTelemetry\DevTools\Package\Composer\RepositoryTypes;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\GenericRepository;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\PackageFactory;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\PackageInterface;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\RepositoryFactory;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\ValueObject\RepositoryFactory
 */
class RepositoryFactoryTest extends AbstractRepositoryTest
{
    private RepositoryFactory $instance;
    private PackageInterface $package;

    #[\Override]
    public function setUp(): void
    {
        $this->instance = RepositoryFactory::create(
            $this->createPackageFactoryMock()
        );
    }

    public function test_build_generic_repository(): void
    {
        $this->assertInstanceOf(
            GenericRepository::class,
            $this->instance->build(
                'foo/bar',
                RepositoryTypes::COMPOSER_TYPE
            )
        );
    }

    /**
     * @dataProvider provideRepositoryTypes
     * @psalm-param class-string $class
     */
    public function test_build_specific_repositories(string $type, string $class): void
    {
        $this->assertInstanceOf(
            $class,
            $this->instance->build(
                'foo',
                $type,
                [
                    $this->getPackage()->toArray(),
                ]
            )
        );
    }

    /**
     * @dataProvider provideSingleRepositoryTypes
     */
    public function test_build_repository_throws_exception_on_single_repository(string $type): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->instance->buildRepository('foo', $type);
    }

    /**
     * @dataProvider provideMultiRepositoryTypes
     */
    public function test_build_single_repository_throws_exception_on_non_single_repository(string $type): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->instance->buildSingleRepository('foo', $type, 'foo', 'bar');
    }

    /**
     * @dataProvider provideSingleRepositoryTypes
     */
    public function test_build_throws_exception_on_single_repository_without_package(string $type): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->instance->build('foo', $type);
    }

    public function test_build_throws_exception_on_invalid_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->instance->build('foo', 'bar');
    }

    /**
     * @dataProvider provideRepositoryTypes
     */
    public function test_build_throws_exception_on_wrong_package_config_type(string $type): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->instance->build('foo', $type, ['foo']);
    }

    /**
     * @dataProvider provideRepositoryTypesWithInvalidPackageConfig
     */
    public function test_build_throws_exception_on_missing_package_attributes(string $type, array $packageConfig): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->instance->build('foo', $type, [$packageConfig]);
    }

    public function provideRepositoryTypes(): Generator
    {
        foreach (RepositoryFactory::TYPES as $type => $class) {
            yield [$type, $class];
        }
    }

    public function provideSingleRepositoryTypes(): Generator
    {
        foreach (RepositoryFactory::SINGLE_TYPES as $type) {
            yield [$type];
        }
    }

    public function provideMultiRepositoryTypes(): Generator
    {
        foreach (array_keys(RepositoryFactory::TYPES) as $type) {
            if (!in_array($type, RepositoryFactory::SINGLE_TYPES, true)) {
                yield [$type];
            }
        }
    }

    public function provideRepositoryTypesWithInvalidPackageConfig(): Generator
    {
        foreach ($this->provideRepositoryTypes() as $typeConfig) {
            [$type] = $typeConfig;
            $config = [];
            foreach (PackageInterface::MANDATORY_ATTRIBUTES as $missing) {
                foreach (PackageInterface::MANDATORY_ATTRIBUTES as $attribute) {
                    $config[$attribute] = 'foo';
                }
                unset($config[$missing]);

                yield [$type, $config];
            }
        }
    }

    private function createPackageFactoryMock(): PackageFactory
    {
        $mock = $this->createMock(PackageFactory::class);

        $mock->method('build')
            ->willReturn(
                $this->getPackage()
            );

        return $mock;
    }

    /**
     * @psalm-suppress RedundantPropertyInitializationCheck
     */
    private function getPackage(): PackageInterface
    {
        return $this->package ?? $this->package = $this->createPackageInterfaceMock();
    }
}
