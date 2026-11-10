<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Packages\Behavior;

use Composer\Command\BaseCommand as ComposerBaseCommand;
use Composer\Composer;
use Composer\Console\Application as ComposerApplication;
use Composer\Factory;
use Composer\IO\ConsoleIO;
use Composer\IO\NullIO;
use InvalidArgumentException;
use OpenTelemetry\DevTools\Console\Command\CommandRunner;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

trait UsesThirdPartyCommandTrait
{
    protected CommandRunner $commandRunner;

    protected function createAndRunCommand(
        string $commandClass,
        InputInterface $input,
        OutputInterface $output,
        ?string $workingDirectory = null
    ): int {
        return $this->runCommand(
            $this->createCommand($commandClass),
            $input,
            $output,
            $workingDirectory
        );
    }

    protected function runCommand(
        Command $command,
        InputInterface $input,
        OutputInterface $output,
        ?string $workingDirectory = null
    ): int {
        $oldWorkingDir = getcwd() ?: throw new RuntimeException('The working directory cannot be read');

        try {
            if (is_string($workingDirectory) && is_dir($workingDirectory)) {
                chdir($workingDirectory);
            }
            if ($command instanceof ComposerBaseCommand) {
                $this->setComposerIO($command, $input, $output);
            }

            return $this->getCommandRunner()->run($command, $input, $output);
        } catch (Throwable $t) {
            throw new RuntimeException(
                sprintf('Failed to run command "%s". Error: "%s" ', get_class($command), $t->getMessage()),
                (int) $t->getCode(),
                $t
            );
        } finally {
            chdir($oldWorkingDir);
        }
    }

    protected function setComposerIO(ComposerBaseCommand $command, InputInterface $input, OutputInterface $output): void
    {
        foreach (Factory::createAdditionalStyles() as $name => $style) {
            if (!$output->getFormatter()->hasStyle($name)) {
                $output->getFormatter()->setStyle($name, $style);
            }
        }
        $command->setIO(new ConsoleIO($input, $output, $command->getHelperSet() ?? new HelperSet()));
    }

    /**
     * @psalm-suppress ArgumentTypeCoercion
     */
    protected function createCommand(string $commandClass): Command
    {
        self::ensureCommandClass($commandClass);

        return $this->initCommand(new $commandClass());
    }

    protected function initCommand(Command $command): Command
    {
        $command->setApplication(
            $this->getApplication()
        );

        if ($command instanceof ComposerBaseCommand) {
            $this->addComposer($command);
        }

        return $command;
    }

    protected function addComposer(ComposerBaseCommand $command): void
    {
        $composer = $this->createComposer();
        $composer === null || $command->setComposer($composer);

        $command->setApplication(
            $this->createComposerApplication()
        );
    }

    /**
     * Null when the working directory has no composer.json, as a monorepo without a root one does.
     * The command then makes its own.
     */
    protected function createComposer(): ?Composer
    {
        return is_file(Factory::getComposerFile()) ? Factory::create(new NullIO()) : null;
    }

    protected function createComposerApplication(): ComposerApplication
    {
        return new ComposerApplication();
    }

    /**
     * @psalm-param class-string $commandClass
     */
    protected static function ensureCommandClass(string $commandClass): void
    {
        try {
            $classReflection = new ReflectionClass($commandClass);
            if (!$classReflection->isSubclassOf(Command::class)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Command class "%s" must extend "%s"',
                        $commandClass,
                        Command::class
                    )
                );
            }
        } catch (Throwable $t) {
            throw new InvalidArgumentException(
                sprintf(
                    'Could not validate class "%s"."',
                    $commandClass
                ),
                (int) $t->getCode(),
                $t
            );
        }
    }

    public function setCommandRunner(CommandRunner $commandRunner): void
    {
        $this->commandRunner = $commandRunner;
    }

    /**
     * @psalm-suppress RedundantPropertyInitializationCheck
     */
    public function getCommandRunner(): CommandRunner
    {
        return $this->commandRunner ?? $this->commandRunner = CommandRunner::create();
    }

    /**
     * @return Application|null
     */
    abstract public function getApplication();
}
