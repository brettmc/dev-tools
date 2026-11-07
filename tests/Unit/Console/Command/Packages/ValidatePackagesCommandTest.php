<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Command\Packages;

use Composer\Util\Filesystem;
use OpenTelemetry\DevTools\Console\Command\Packages\ValidatePackagesCommand;
use OpenTelemetry\DevTools\Package\Composer\ConfigResolverInterface;
use OpenTelemetry\DevTools\Util\WorkingDirectoryResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Packages\ValidatePackagesCommand
 */
class ValidatePackagesCommandTest extends TestCase
{
    private const DIRECTORY = __DIR__ . '/_files';
    private const VALID_COMPOSER_FILE = __DIR__ . '/_files/composer.valid.json';
    private const INVALID_COMPOSER_FILE = __DIR__ . '/_files/composer.invalid.json';
    private const BROKEN_COMPOSER_FILE = __DIR__ . '/_files/composer.broken.json';

    public function test_execute_valid(): void
    {
        $paths = (static function () {
            yield self::DIRECTORY => self::VALID_COMPOSER_FILE;
            yield self::DIRECTORY => self::VALID_COMPOSER_FILE;
            yield self::DIRECTORY => self::VALID_COMPOSER_FILE;
        })();

        $commandTester = new CommandTester(
            $this->createValidatePackagesCommand(
                $paths
            )
        );
        $commandTester->execute([]);

        $this->assertSame(
            Command::SUCCESS,
            $commandTester->getStatusCode()
        );
    }

    public function test_execute_valid_relative(): void
    {
        $paths = (static function () {
            $fs = new Filesystem();
            $cwd = WorkingDirectoryResolver::create()->resolve();

            yield self::DIRECTORY => self::VALID_COMPOSER_FILE;
            yield $fs->findShortestPath($cwd, self::DIRECTORY, true) => $fs->findShortestPath($cwd, self::VALID_COMPOSER_FILE, true);
            yield self::DIRECTORY => self::VALID_COMPOSER_FILE;
        })();

        $commandTester = new CommandTester(
            $this->createValidatePackagesCommand(
                $paths
            )
        );
        $commandTester->execute([]);

        $this->assertSame(
            Command::SUCCESS,
            $commandTester->getStatusCode()
        );
    }

    public function test_execute_invalid(): void
    {
        $paths = (static function () {
            yield self::DIRECTORY => self::VALID_COMPOSER_FILE;
            yield self::DIRECTORY => self::INVALID_COMPOSER_FILE;
            yield self::DIRECTORY => self::VALID_COMPOSER_FILE;
        })();

        $commandTester = new CommandTester(
            $this->createValidatePackagesCommand(
                $paths
            )
        );
        $commandTester->execute([]);

        $this->assertSame(
            Command::FAILURE,
            $commandTester->getStatusCode()
        );
    }

    public function test_execute_broken(): void
    {
        $paths = (static function () {
            yield self::DIRECTORY => self::VALID_COMPOSER_FILE;
            yield self::DIRECTORY => self::BROKEN_COMPOSER_FILE;
            yield self::DIRECTORY => self::VALID_COMPOSER_FILE;
        })();

        $commandTester = new CommandTester(
            $this->createValidatePackagesCommand(
                $paths
            )
        );
        $commandTester->execute([]);

        $this->assertSame(
            Command::FAILURE,
            $commandTester->getStatusCode()
        );
    }

    /**
     * @psalm-suppress PossiblyInvalidArgument
     */
    private function createValidatePackagesCommand(iterable $paths): ValidatePackagesCommand
    {
        return new \OpenTelemetry\DevTools\Console\Command\Packages\ValidatePackagesCommand(
            $this->createConfigResolverMock($paths)
        );
    }

    private function createConfigResolverMock(iterable $paths): ConfigResolverInterface
    {
        $mock = $this->createMock(ConfigResolverInterface::class);

        $mock->method('resolve')
            ->willReturn($paths);

        return $mock;
    }
}
