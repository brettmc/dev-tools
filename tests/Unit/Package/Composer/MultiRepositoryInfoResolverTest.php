<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer;

use OpenTelemetry\DevTools\Package\Composer\ConfigResolverInterface;
use OpenTelemetry\DevTools\Package\Composer\MultiRepositoryInfoResolver;
use OpenTelemetry\DevTools\Package\Composer\PackageAttributeResolver;
use OpenTelemetry\DevTools\Package\Composer\PackageAttributeResolverFactory;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\PackageInterface;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\RepositoryFactory;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\SingleRepositoryInterface;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsTrait;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\MultiRepositoryInfoResolver
 */
class MultiRepositoryInfoResolverTest extends TestCase
{
    use UsesVfsTrait;

    private const NAME_ATTRIBUTE = 'name';
    private const TYPE_ATTRIBUTE = 'type';
    private const AUTHORS_ATTRIBUTE = 'authors';
    private const REQUIRE_ATTRIBUTE = 'require';
    private const COMPOSER_CONFIG = [
        self::TYPE_ATTRIBUTE => 'library',
        self::AUTHORS_ATTRIBUTE => [[
            'name' => 'Foo Bar',
        ]],
        self::REQUIRE_ATTRIBUTE => [
            'foo/bar' => '1.0.0',
        ],
    ];
    private const COMPOSER_FILE_NAME = 'composer.json';
    private const COMPOSER_FILE_PATHS = [
        'A/foo',
        'A/bar',
        'A/baz',
    ];
    private const ROOT_PATH = 'vfs://root/';

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpVcs();
    }

    public function test_resolve(): void
    {
        $collection = $this->createInstance(
            $this->createPackageAttributeResolverFactoryMock(),
            $this->createRepositoryFactoryMock()
        )->resolve();

        foreach (self::COMPOSER_FILE_PATHS as $path) {
            $this->assertArrayHasKey($path, $collection);
        }
    }

    public function test_resolve_with_dependency_defaults(): void
    {
        $collection = $this->createInstance()->resolve();

        foreach (self::COMPOSER_FILE_PATHS as $path) {
            $this->assertArrayHasKey($path, $collection);
        }
    }

    public function test_working_directory_accessors(): void
    {
        $directory = 'foo://bar';

        $instance = $this->createInstance();
        $instance->setWorkingDirectory($directory);

        $this->assertSame(
            $directory,
            $instance->getWorkingDirectory()
        );
    }

    private function createInstance(
        ?PackageAttributeResolverFactory $resolverFactory = null,
        ?RepositoryFactory $repositoryFactory = null
    ): MultiRepositoryInfoResolver {
        return MultiRepositoryInfoResolver::create(
            $this->createConfigResolverInterfaceMock(),
            $resolverFactory,
            $repositoryFactory,
        );
    }

    private function createConfigResolverInterfaceMock(): ConfigResolverInterface
    {
        $mock = $this->createMock(ConfigResolverInterface::class);

        $mock->method('resolve')
            ->willReturn(
                $this->createConfigFiles()
            );

        return $mock;
    }

    private function createPackageAttributeResolverFactoryMock(): PackageAttributeResolverFactory
    {
        $mock = $this->createMock(PackageAttributeResolverFactory::class);

        $mock->method('build')
            ->willReturnCallback(
                function (string $composerFile) {
                    return $this->createPackageAttributeResolverMock($composerFile);
                }
            );

        return $mock;
    }

    private function createPackageAttributeResolverMock(string $composerFile): PackageAttributeResolver
    {
        $config = self::COMPOSER_CONFIG;
        $config[self::NAME_ATTRIBUTE] = str_replace(
            [self::ROOT_PATH, DIRECTORY_SEPARATOR . self::COMPOSER_FILE_NAME],
            '',
            $composerFile
        );

        $mock = $this->createMock(PackageAttributeResolver::class);

        $mock->method('resolve')
            ->willReturnCallback(
                function (string $attribute) use ($config) {
                    return $config[$attribute] ?? '';
                }
            );

        return $mock;
    }

    private function createRepositoryFactoryMock(): RepositoryFactory
    {
        $mock = $this->createMock(RepositoryFactory::class);

        $mock->method('buildSingleRepository')
            ->willReturnCallback(function (string $url, string $type, string $packageName, string $packageType) {
                return $this->createSingleRepositoryInterfaceMock(
                    $url,
                    $type,
                    $packageName,
                    $packageType
                );
            });

        return $mock;
    }

    private function createSingleRepositoryInterfaceMock(string $url, string $type, string $packageName, string $packageType): SingleRepositoryInterface
    {
        $mock = $this->createMock(SingleRepositoryInterface::class);

        $mock->method('getPackage')
            ->willReturn(
                $this->createPackageInterfaceMock($packageName, $packageType)
            );

        return $mock;
    }

    private function createPackageInterfaceMock(string $name, string $type): PackageInterface
    {
        $mock = $this->createMock(PackageInterface::class);

        $mock->method('getName')
            ->willReturn($name);
        $mock->method('getType')
            ->willReturn($type);

        return $mock;
    }

    private function createConfigFiles(): array
    {
        $result = [];

        foreach (self::COMPOSER_FILE_PATHS as $path) {
            $config = self::COMPOSER_CONFIG;
            $config[self::NAME_ATTRIBUTE] = $path;
            $result[$path] = $this->createConfigFile(
                $path . DIRECTORY_SEPARATOR . self::COMPOSER_FILE_NAME,
                json_encode($config, JSON_THROW_ON_ERROR),
            );
        }

        return $result;
    }

    private function createConfigFile(string $path, string $content): string
    {
        return vfsStream::newFile($path)
            ->withContent($content)
            ->at($this->root)
            ->url();
    }
}
