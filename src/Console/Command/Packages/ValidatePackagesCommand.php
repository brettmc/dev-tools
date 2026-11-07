<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Packages;

use Composer\Command\ValidateCommand;
use OpenTelemetry\DevTools\Console\Command\BaseCommand;
use OpenTelemetry\DevTools\Console\Command\CommandRunner;
use OpenTelemetry\DevTools\Console\Command\Packages\Behavior\UsesThirdPartyCommandTrait;
use OpenTelemetry\DevTools\Package\GitSplit\MonorepoCheckout;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ValidatePackagesCommand extends BaseCommand
{
    use UsesThirdPartyCommandTrait;

    public const NAME = 'packages:composer:validate';
    public const DESCRIPTION = 'Validates composer files of the individual packages';

    /**
     * @param ?string $root the monorepo checkout, by default the working directory when the command runs
     */
    public function __construct(private readonly ?string $root = null, ?CommandRunner $commandRunner = null)
    {
        parent::__construct(self::NAME);

        $this->commandRunner = $commandRunner ?? CommandRunner::create();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setDescription(self::DESCRIPTION);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->registerInputAndOutput($input, $output);

        $root = $this->root ?? (getcwd() ?: throw new RuntimeException('The working directory cannot be read'));
        $this->writeIntro($root);

        try {
            $prefixes = MonorepoCheckout::read($root)->prefixes;
        } catch (RuntimeException $e) {
            $this->writeError($e->getMessage());

            return self::FAILURE;
        }

        foreach ($prefixes as $prefix) {
            $composerFile = "{$prefix}/composer.json";

            try {
                $this->writeBlankLine();
                $this->writeSection(sprintf(
                    'Validating: %s',
                    $composerFile
                ));

                $res = $this->runValidateCommand("{$root}/{$composerFile}", "{$root}/{$prefix}");

                if ($res !== 0) {
                    $this->writeError(sprintf(
                        'Composer file is invalid %s',
                        $composerFile
                    ));

                    return self::FAILURE;
                }

                $this->writeOk();
            } catch (Throwable $t) {
                $this->writeThrowable($t);

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function runValidateCommand(string $composerFile, string $directory): int
    {
        return $this->createAndRunCommand(
            ValidateCommand::class,
            new ArrayInput([
                'file' => $composerFile,
            ]),
            $this->getOutput(),
            $directory,
        );
    }

    private function writeIntro(string $root): void
    {
        $this->writeTitle();
        $this->writeSection('Validating composer files in: ' . $root);
        $this->writeBlankLine();
    }
}
