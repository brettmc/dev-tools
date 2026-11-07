<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Packages;

use Composer\Command\UpdateCommand;
use Generator;
use OpenTelemetry\DevTools\Console\Command\BaseCommand;
use OpenTelemetry\DevTools\Console\Command\CommandRunner;
use OpenTelemetry\DevTools\Console\Command\Packages\Behavior\UsesThirdPartyCommandTrait;
use OpenTelemetry\DevTools\Package\Composer\MultiRepositoryInfoResolver;
use OpenTelemetry\DevTools\Package\Composer\TestInstallationFactory;
use OpenTelemetry\DevTools\Package\Composer\TestInstaller;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\LocalRepository;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\RepositoryCollection;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\SingleRepositoryInterface;
use OpenTelemetry\DevTools\Util\WorkingDirectoryResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ValidateInstallationCommand extends BaseCommand
{
    use UsesThirdPartyCommandTrait;

    public const NAME = 'packages:validate:installation';
    public const DESCRIPTION = 'Validates composer files of the individual mono-repo packages';
    public const DIRECTORY_OPTION_NAME = 'directory';
    public const DIRECTORY_OPTION_SHORT = 'd';
    public const DIRECTORY_OPTION_MODE = InputOption::VALUE_OPTIONAL;
    public const DIRECTORY_OPTION_DESCRIPTION = 'Test installation base directory';
    public const PACKAGE_OPTION_NAME = 'package';
    public const PACKAGE_OPTION_SHORT = 'p';
    public const PACKAGE_OPTION_MODE = InputOption::VALUE_IS_ARRAY + InputOption::VALUE_OPTIONAL;
    public const PACKAGE_OPTION_DESCRIPTION = 'Default package to add to test installation -> "vendor/package:version';
    public const BRANCH_OPTION_NAME = 'branch';
    public const BRANCH_OPTION_SHORT = 'b';
    public const BRANCH_OPTION_MODE = InputOption::VALUE_OPTIONAL;
    public const BRANCH_OPTION_DESCRIPTION = 'Branch to test packages from';
    public const COMPOSER_FILE_NAME = 'composer.json';
    public const DEFAULT_INSTALLATION_DIRECTORY = '/tmp/_test';
    public const TEST_DIRECTORY = '_validate_installation_command';
    private const UPDATE_COMMAND_OPTIONS = [
        '--dry-run' => true,
        '--no-progress' => true,
        '--no-interaction' => true,
        '--no-plugins' => true,
    ];

    private MultiRepositoryInfoResolver $resolver;
    private TestInstallationFactory $testInstallationFactory;
    private TestInstaller $testInstaller;
    private string $installationDirectory;
    private RepositoryCollection $packageInfos;
    private string $workingDirectory;

    public function __construct(
        MultiRepositoryInfoResolver $resolver,
        ?TestInstallationFactory $testInstallationFactory = null,
        ?TestInstaller $testInstaller = null,
        ?CommandRunner $commandRunner = null
    ) {
        parent::__construct(self::NAME);

        $this->resolver = $resolver;
        $this->testInstallationFactory = $testInstallationFactory ?? TestInstallationFactory::create();
        $this->testInstaller = $testInstaller ?? TestInstaller::create(self::DEFAULT_INSTALLATION_DIRECTORY);
        $this->commandRunner = $commandRunner ?? CommandRunner::create();
        $this->setInstallationDirectory($this->testInstaller->getRootDirectory());
        $this->initWorkingDirectory();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setDescription(self::DESCRIPTION);
        $this->addOption(
            self::DIRECTORY_OPTION_NAME,
            self::DIRECTORY_OPTION_SHORT,
            self::DIRECTORY_OPTION_MODE,
            self::DIRECTORY_OPTION_DESCRIPTION,
        );
        $this->addOption(
            self::PACKAGE_OPTION_NAME,
            self::PACKAGE_OPTION_SHORT,
            self::PACKAGE_OPTION_MODE,
            self::PACKAGE_OPTION_DESCRIPTION,
        );
        $this->addOption(
            self::BRANCH_OPTION_NAME,
            self::BRANCH_OPTION_SHORT,
            self::BRANCH_OPTION_MODE,
            self::BRANCH_OPTION_DESCRIPTION,
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->registerInputAndOutput($input, $output);

        try {
            $this->setUpWorkingDirectory();

            $this->writeIntro();
            $this->writeInstallDirectory();
            $this->writeDependencies(
                $this->setUpDefaultDependencies()
            );

            return $this->runInstallations();
        } catch (Throwable $t) {
            $this->writeThrowable($t);

            return Command::FAILURE;
        }
    }

    private function runInstallations(): int
    {

        /** @var SingleRepositoryInterface $repository */
        foreach ($this->getPackageInfos() as $repository) {
            $res = $this->runInstallation(
                $repository,
                $this->getPackageInfos(),
                self::resolveBranchOptionValue($this->getInput())
            );

            if ($res !== self::SUCCESS) {
                return $res;
            }
        }

        return Command::SUCCESS;
    }

    private function runInstallation(
        SingleRepositoryInterface $repository,
        RepositoryCollection $dependencies,
        ?string $branch = null
    ): int {
        if ($this->installPackage($repository, $dependencies, $branch) !== 0) {
            $this->writeError(
                sprintf(
                    'Failed to install %s',
                    $repository->getComposerFilePath()
                )
            );

            return Command::FAILURE;
        }

        $this->writeOk();

        return Command::SUCCESS;
    }

    protected function installPackage(
        SingleRepositoryInterface $repository,
        RepositoryCollection $dependencies,
        ?string $branch = null
    ): int {
        $composerFile = $repository->getComposerFilePath();
        $packageName = $repository->getPackageName();
        $installationDirectory = $this->getInstallDirectory($composerFile);

        $installation = $this->testInstallationFactory->build(
            LocalRepository::create($installationDirectory, $repository->getPackage()),
            $branch,
            $dependencies
        );

        $installationDirectory = $installation->getTestedRepository()->getUrl();

        $this->writeBlankLine();
        $this->writeSection($packageName);
        $this->writeLine('Install Dir: ' . $installationDirectory);
        $this->writeLine('Composer Source: ' . ($composerFile));

        $this->testInstaller->setRootDirectory($this->getInstallDirectory($composerFile));
        $this->testInstaller->install($installation);

        $res = $this->runUpdateCommand(
            $installationDirectory
        );

        $this->testInstaller->remove($installation);

        return $res;
    }

    private function getInstallDirectory(string $composerFilePath): string
    {
        return $this->installationDirectory . DIRECTORY_SEPARATOR . md5(str_replace('/', '_', dirname($composerFilePath)));
    }

    /**
     * @psalm-suppress RedundantPropertyInitializationCheck
     */
    protected function getPackageInfos(): RepositoryCollection
    {
        return $this->packageInfos ?? $this->packageInfos = $this->resolver->resolve();
    }

    private function initWorkingDirectory(): void
    {
        $this->workingDirectory = WorkingDirectoryResolver::create()->resolve();
    }

    private function setInstallationDirectory(string $directory): void
    {
        $this->installationDirectory = $directory . DIRECTORY_SEPARATOR . self::TEST_DIRECTORY;
    }

    private static function parsePackageString(string $package): array
    {
        return explode(':', $package);
    }

    private function setUpWorkingDirectory(): void
    {
        if ($this->getInput()->hasOption(self::DIRECTORY_OPTION_NAME)
            && is_string($this->getInput()->getOption(self::DIRECTORY_OPTION_NAME))) {
            $this->setInstallationDirectory(
                $this->getInput()->getOption(self::DIRECTORY_OPTION_NAME)
            );
        }
    }

    private function setUpDefaultDependencies(): array
    {
        $dependencies = [];

        foreach (self::resolveDefaultDependencies($this->getInput()) as $packageConfig) {
            $dependencies[] = $packageConfig;
            [$package, $version] = self::parsePackageString($packageConfig);
            $this->testInstallationFactory->addDefaultDependency($package, $version);
        }

        return $dependencies;
    }

    private static function resolveDefaultDependencies(InputInterface $input): Generator
    {
        foreach (self::resolveDependencyOptionValues($input) as $packageConfig) {
            yield $packageConfig;
        }
    }

    private static function resolveDependencyOptionValues(InputInterface $input): array
    {
        return $input->hasOption(self::PACKAGE_OPTION_NAME)
        && is_array($input->getOption(self::PACKAGE_OPTION_NAME))
            ? $input->getOption(self::PACKAGE_OPTION_NAME)
            : [];
    }

    private static function resolveBranchOptionValue(InputInterface $input): ?string
    {
        return $input->hasOption(self::BRANCH_OPTION_NAME)
            ? (string) $input->getOption(self::BRANCH_OPTION_NAME)
            : null;
    }

    private function runUpdateCommand(?string $workingDirectory = null): int
    {
        return $this->createAndRunCommand(
            UpdateCommand::class,
            new ArrayInput(self::UPDATE_COMMAND_OPTIONS),
            $this->getOutput(),
            $workingDirectory
        );
    }

    private function writeIntro(): void
    {
        $this->writeTitle();
        $this->writeSection('Trying to install packages from: ' . $this->workingDirectory);
    }

    private function writeDependencies(array $dependencies): void
    {
        if (!empty($dependencies)) {
            $this->writeLine('Default packages: ');
            $this->writeListing($dependencies);
        }
    }

    private function writeInstallDirectory(): void
    {
        $this->writeLine('Installation directory: ' . $this->installationDirectory);
    }
}
