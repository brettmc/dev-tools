<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer;

use ArrayIterator;
use Exception;
use JsonException;
use OpenTelemetry\DevTools\Package\Composer\ConfigAttributes;
use OpenTelemetry\DevTools\Package\Composer\TestConfig;
use OpenTelemetry\DevTools\Package\Composer\TestInstallation;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\RepositoryCollection;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\SingleRepositoryInterface;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\TestInstallation
 */
class TestInstallationTest extends TestCase
{
    private const ROOT_DIR = 'root';
    private const TEST_DIR = 'test';
    private const TESTED_BRANCH = 'root';
    private const TEST_CONFIG = [
        ConfigAttributes::NAME => 'foo/bar',
        ConfigAttributes::DESCRIPTION => 'foo bar baz',
        ConfigAttributes::TYPE => 'foo',
        ConfigAttributes::LICENSE => 'Test License',
        ConfigAttributes::MINIMUM_STABILITY => 'dev',
        ConfigAttributes::PREFER_STABLE => true,
    ];

    private TestInstallation $instance;
    private SingleRepositoryInterface&MockObject $repository;
    private TestConfig&MockObject $config;
    private RepositoryCollection&MockObject $dependencies;
    private vfsStreamDirectory $root;
    private string $testDirectory;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = vfsStream::setup(self::ROOT_DIR);
        $this->testDirectory = vfsStream::newDirectory(self::TEST_DIR, 0777)
            ->at($this->root)
            ->url();

        $this->repository = $this->createMock(SingleRepositoryInterface::class);
        $dependency = $this->createMock(SingleRepositoryInterface::class);
        $dependency->method('getType')
            ->willReturn('foo');
        $dependency->method('getUrl')
            ->willReturn('bar');
        $this->repository->method('getUrl')
            ->willReturn('bar');

        $this->dependencies = $this->createMock(RepositoryCollection::class);
        $this->dependencies->method('getIterator')
            ->willReturn(new ArrayIterator([$dependency]));

        $this->config = $this->createMock(TestConfig::class);

        $this->instance = TestInstallation::create(
            $this->repository,
            $this->config,
            $this->dependencies,
            self::TESTED_BRANCH
        );
    }

    public function test_get_config(): void
    {
        $this->assertSame(
            $this->config,
            $this->instance->getConfig()
        );
    }

    public function test_get_tested_repository(): void
    {
        $this->assertSame(
            $this->repository,
            $this->instance->getTestedRepository()
        );
    }

    public function test_get_dependencies(): void
    {
        $this->assertSame(
            $this->dependencies,
            $this->instance->getDependencies()
        );
    }

    public function test_get_tested_branch(): void
    {
        $this->assertSame(
            self::TESTED_BRANCH,
            $this->instance->getTestedBranch()
        );
    }

    /**
     * @throws JsonException
     */
    public function test_to_json(): void
    {
        $this->config
            ->method('toArray')
            ->willReturn(self::TEST_CONFIG);

        $this->assertSame(
            self::TEST_CONFIG,
            json_decode($this->instance->toJson(), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function test_to_json_throws_exception_on_invalid_json(): void
    {
        $this->config
            ->method('toArray')
            ->willThrowException(
                new Exception()
            );

        $this->expectException(RuntimeException::class);

        $this->instance->toJson();
    }

    /**
     * @throws JsonException
     */
    public function test_to_string(): void
    {
        $this->config
            ->method('toArray')
            ->willReturn(self::TEST_CONFIG);

        $this->assertSame(
            self::TEST_CONFIG,
            json_decode((string) $this->instance, true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function test_write_composer_file(): void
    {
        $composerPath = $this->testDirectory . DIRECTORY_SEPARATOR . TestInstallation::COMPOSER_FILE_NAME;
        $this->repository
            ->method('getComposerFilePath')
            ->willReturn($composerPath);

        $this->config
            ->method('toArray')
            ->willReturn(self::TEST_CONFIG);

        $this->instance->writeComposerFile();

        $this->assertFileExists($composerPath);

        $this->assertSame(
            $this->instance->toJson(),
            file_get_contents($composerPath)
        );
    }

    public function test_write_composer_file_throws_exception_on_file_write_error(): void
    {
        $composerPath = $this->testDirectory . DIRECTORY_SEPARATOR . TestInstallation::COMPOSER_FILE_NAME;
        $this->repository->method('getComposerFilePath')
            ->willReturn('foo://bar.baz');

        $this->expectException(RuntimeException::class);

        $this->config
            ->method('toArray')
            ->willReturn(self::TEST_CONFIG);

        $this->instance->writeComposerFile();

        $this->assertFileDoesNotExist($composerPath);
    }
}
