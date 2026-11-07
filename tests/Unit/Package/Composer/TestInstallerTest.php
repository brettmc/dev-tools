<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer;

use InvalidArgumentException;
use OpenTelemetry\DevTools\Package\Composer\TestInstallation;
use OpenTelemetry\DevTools\Package\Composer\TestInstaller;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\SingleRepositoryInterface;
use OpenTelemetry\DevTools\Util\RecursiveDirectoryRemover;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\TestInstaller
 */
class TestInstallerTest extends TestCase
{
    private const ROOT_DIR = 'root';
    private const TEST_DIR = 'test';
    public const COMPOSER_FILE_NAME = 'composer.json';

    private TestInstaller $instance;
    private RecursiveDirectoryRemover&MockObject $directoryRemover;
    private vfsStreamDirectory $root;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = vfsStream::setup(self::ROOT_DIR);

        $this->directoryRemover = $this->createMock(RecursiveDirectoryRemover::class);
        TestInstaller::setDirectoryRemover($this->directoryRemover);

        $this->instance = TestInstaller::create(
            $this->root->url() . DIRECTORY_SEPARATOR . self::TEST_DIR
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        TestInstaller::setDirectoryRemover(null);
    }

    public function test_install(): void
    {
        $composerDirectory = self::TEST_DIR;
        $testDirectory = vfsStream::newDirectory(self::TEST_DIR, 0777)
            ->at($this->root)
            ->url();

        $this->directoryRemover->method('remove')
            ->willReturnCallback(function () use ($testDirectory) {
                rmdir($testDirectory);

                return true;
            });

        $repository = $this->createMock(SingleRepositoryInterface::class);
        $repository->method('getComposerFilePath')
            ->willReturn($composerDirectory . DIRECTORY_SEPARATOR . self::COMPOSER_FILE_NAME);

        $installation = $this->createMock(TestInstallation::class);
        $installation->method('getTestedRepository')
            ->willReturn($repository);

        $this->assertTrue(
            $this->instance->install($installation)
        );
    }

    public function test_install_throws_exception_on_error(): void
    {
        $exception = new RuntimeException();

        $this->expectException(InvalidArgumentException::class);

        $installation = $this->createMock(TestInstallation::class);
        $installation->method('writeComposerFile')
            ->willThrowException($exception);

        $this->assertTrue(
            $this->instance->install($installation)
        );
    }

    public function test_install_throws_exception_when_not_able_to_create_install_directory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        vfsStream::newDirectory(self::TEST_DIR, 0700)
            ->at($this->root);
        $this->root->chown(12345);
        $this->root->chmod(0700);

        $this->directoryRemover->method('remove')
            ->willReturn(true);

        $repository = $this->createMock(SingleRepositoryInterface::class);
        $repository->method('getComposerFilePath')
            ->willReturn('foo' . DIRECTORY_SEPARATOR . self::COMPOSER_FILE_NAME);

        $installation = $this->createMock(TestInstallation::class);
        $installation->method('getTestedRepository')
            ->willReturn($repository);

        $this->instance->install($installation);
    }

    public function test_remove(): void
    {
        $composerDirectory = DIRECTORY_SEPARATOR . self::TEST_DIR;

        $repository = $this->createMock(SingleRepositoryInterface::class);
        $repository->method('getComposerFilePath')
            ->willReturn($composerDirectory . DIRECTORY_SEPARATOR . self::COMPOSER_FILE_NAME);

        $installation = $this->createMock(TestInstallation::class);
        $installation->method('getTestedRepository')
            ->willReturn($repository);

        $this->assertTrue(
            $this->instance->remove($installation)
        );
    }

    public function test_remove_throws_exception_on_error(): void
    {
        $exception = new RuntimeException();

        $this->expectException(InvalidArgumentException::class);

        $installation = $this->createMock(TestInstallation::class);

        $this->directoryRemover->method('remove')
            ->willThrowException($exception);

        $this->assertTrue(
            $this->instance->remove($installation)
        );
    }

    public function test_get_root_directory(): void
    {
        $this->assertSame(
            $this->root->url() . DIRECTORY_SEPARATOR . self::TEST_DIR,
            $this->instance->getRootDirectory()
        );
    }
}
