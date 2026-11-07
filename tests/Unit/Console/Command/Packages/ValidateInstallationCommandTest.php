<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Command\Packages;

use ArrayIterator;
use Exception;
use OpenTelemetry\DevTools\Console\Command\CommandRunner;
use OpenTelemetry\DevTools\Console\Command\Packages\ValidateInstallationCommand;
use OpenTelemetry\DevTools\Package\Composer\MultiRepositoryInfoResolver;
use OpenTelemetry\DevTools\Package\Composer\TestInstallationFactory;
use OpenTelemetry\DevTools\Package\Composer\TestInstaller;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\RepositoryCollection;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\SingleRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Packages\ValidateInstallationCommand
 */
class ValidateInstallationCommandTest extends TestCase
{
    private const PACKAGE_OPTION = '--' . ValidateInstallationCommand::PACKAGE_OPTION_NAME;
    private const DIRECTORY_OPTION = '--' . ValidateInstallationCommand::DIRECTORY_OPTION_NAME;
    private const BRANCH_OPTION = '--' . \OpenTelemetry\DevTools\Console\Command\Packages\ValidateInstallationCommand::BRANCH_OPTION_NAME;

    private const DEFAULT_DEPENDENCIES = [
        'composer/composer:^2.0.0',
    ];

    public function test_execute_valid(): void
    {
        $command = $this->createValidateInstallationCommand();
        $runner = $this->createMock(CommandRunner::class);
        $runner
            ->method('run')
            ->willReturn(Command::SUCCESS);
        $command->setCommandRunner($runner);

        $commandTester = new CommandTester($command);

        $input = [
            self::DIRECTORY_OPTION => '/tmp/_test/' . md5((string) microtime(true)),
            self::BRANCH_OPTION => 'main',
            self::PACKAGE_OPTION => [],
        ];

        foreach (self::DEFAULT_DEPENDENCIES as $dependency) {
            $input[self::PACKAGE_OPTION][] = $dependency;
        }

        $commandTester->execute($input);

        $this->assertSame(
            Command::SUCCESS,
            $commandTester->getStatusCode()
        );
    }

    public function test_execute_invalid(): void
    {
        $commandTester = new CommandTester(
            $this->createValidateInstallationCommand(true)
        );

        $commandTester->execute([]);

        $this->assertSame(
            Command::FAILURE,
            $commandTester->getStatusCode()
        );
    }

    public function test_execute_error(): void
    {
        $commandTester = new CommandTester(
            $this->createValidateInstallationCommandFailureMock()
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
    private function createValidateInstallationCommand(bool $throwException = false): ValidateInstallationCommand
    {
        return new ValidateInstallationCommand(
            $this->createMultiRepositoryInfoResolverMock(),
            $this->createTestInstallationFactoryMock($throwException),
            $this->createTestInstallerMock(),
        );
    }

    private function createValidateInstallationCommandFailureMock(): ValidateInstallationCommand
    {
        $command = $this->getMockBuilder(ValidateInstallationCommand::class)
            ->setConstructorArgs([
                $this->createMultiRepositoryInfoResolverMock(),
                $this->createTestInstallationFactoryMock(),
                $this->createTestInstallerMock(),
            ])
            ->setMethods(['installPackage'])
            ->getMock();
        $command->expects($this->once())
            ->method('installPackage')
            ->willReturn(Command::FAILURE);

        return $command;
    }

    private function createMultiRepositoryInfoResolverMock(): MultiRepositoryInfoResolver
    {
        $mock = $this->createMock(MultiRepositoryInfoResolver::class);

        $mock->method('resolve')
            ->willReturn(
                $this->createRepositoryCollectionMock()
            );

        return $mock;
    }

    private function createTestInstallationFactoryMock(bool $throwException = false): TestInstallationFactory
    {
        $mock = $this->createMock(TestInstallationFactory::class);

        if ($throwException) {
            $mock->method('build')
                ->willThrowException(new Exception());
        }

        return $mock;
    }

    private function createTestInstallerMock(): TestInstaller
    {
        return $this->createMock(TestInstaller::class);
    }

    private function createRepositoryCollectionMock(): RepositoryCollection
    {
        $mock = $this->createMock(RepositoryCollection::class);

        $mock->method('getIterator')
            ->willReturn(new ArrayIterator([
                $this->createRepositoryInterfaceMock(),
                $this->createRepositoryInterfaceMock(),
                $this->createRepositoryInterfaceMock(),
            ]));

        return $mock;
    }

    private function createRepositoryInterfaceMock(): SingleRepositoryInterface
    {
        return $this->createMock(SingleRepositoryInterface::class);
    }
}
