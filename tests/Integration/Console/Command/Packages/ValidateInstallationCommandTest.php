<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Integration\Console\Command\Packages;

use OpenTelemetry\DevTools\Console\Command\Packages\ValidateInstallationCommand;
use OpenTelemetry\DevTools\Package\Composer\MultiRepositoryInfoResolver;
use OpenTelemetry\DevTools\Package\Composer\PackageAttributeResolverFactory;
use OpenTelemetry\DevTools\Package\Composer\TestConfigFactory;
use OpenTelemetry\DevTools\Package\Composer\TestInstallationFactory;
use OpenTelemetry\DevTools\Package\Composer\TestInstaller;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\PackageFactory;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\RepositoryFactory;
use OpenTelemetry\DevTools\Package\GitSplit\ConfigResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Packages\ValidateInstallationCommand
 */
class ValidateInstallationCommandTest extends TestCase
{
    private const SOURCE_DIRECTORY = __DIR__ . '/_files/monorepo/valid/src';
    private const PACKAGE_OPTION_NAME = '--' . ValidateInstallationCommand::PACKAGE_OPTION_NAME;
    private const PACKAGE_OPTION_VALUE = [];
    private const DIRECTORY_OPTION_NAME = '--' . ValidateInstallationCommand::DIRECTORY_OPTION_NAME;
    private const DIRECTORY_OPTION_VALUE = __DIR__ . '/../../../../../var/package/install';
    private const GITHUB_DIRECTORY_OPTION_VALUE = '~';
    private const BRANCH_OPTION_NAME = '--' . ValidateInstallationCommand::BRANCH_OPTION_NAME;
    private const BRANCH_OPTION_VALUE = 'main';

    private const DEFAULT_DEPENDENCIES = [
        'composer/composer:^2.0.0',
    ];

    public function test_execute_valid(): void
    {
        $oldWorkingDirectory = getcwd();
        chdir(self::SOURCE_DIRECTORY);

        $commandTester = new CommandTester(
            $this->createValidateInstallationCommand()
        );

        $input = [
            self::DIRECTORY_OPTION_NAME => realpath(self::getInstallDirectory()),
            self::BRANCH_OPTION_NAME => self::BRANCH_OPTION_VALUE,
            self::PACKAGE_OPTION_NAME => self::PACKAGE_OPTION_VALUE,
        ];

        foreach (self::DEFAULT_DEPENDENCIES as $dependency) {
            $input[self::PACKAGE_OPTION_NAME][] = $dependency;
        }

        $commandTester->execute($input);

        chdir($oldWorkingDirectory);

        $this->assertSame(
            Command::SUCCESS,
            $commandTester->getStatusCode()
        );
    }

    private function createValidateInstallationCommand(): ValidateInstallationCommand
    {
        return new ValidateInstallationCommand(
            $this->createMultiRepositoryInfoResolver(),
            $this->createTestInstallationFactory(),
            $this->createTestInstaller(),
        );
    }

    private function createMultiRepositoryInfoResolver(): MultiRepositoryInfoResolver
    {
        return MultiRepositoryInfoResolver::create(
            new ConfigResolver(),
            new PackageAttributeResolverFactory(),
            new RepositoryFactory(
                new PackageFactory()
            )
        );
    }

    private function createTestInstallationFactory(): TestInstallationFactory
    {
        return new TestInstallationFactory(
            new TestConfigFactory()
        );
    }

    private function createTestInstaller(): TestInstaller
    {
        return new TestInstaller(__DIR__);
    }

    private static function getInstallDirectory(): string
    {
        return self::getEnv('GITHUB_ACTIONS')
            ? self::GITHUB_DIRECTORY_OPTION_VALUE
            : self::DIRECTORY_OPTION_VALUE;
    }

    /**
     * @psalm-suppress InvalidArrayOffset
     */
    private static function getEnv(string $name)
    {
        if (array_key_exists($name, $_SERVER)) {
            return (string) $_SERVER[$name];
        }
        if (array_key_exists($name, $_ENV)) {
            return (string) $_ENV[$name];
        }

        return getenv($name);
    }
}
