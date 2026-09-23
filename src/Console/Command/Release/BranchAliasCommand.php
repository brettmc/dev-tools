<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Release;

use Http\Discovery\Psr18ClientDiscovery;
use OpenTelemetry\DevTools\Console\Release\Repository;
use OpenTelemetry\DevTools\Package\Composer\BranchAliasStatus;
use OpenTelemetry\DevTools\Package\Composer\BranchAliasUpdater;
use OpenTelemetry\DevTools\Package\Composer\ConfigAttributes;
use OpenTelemetry\DevTools\Package\Composer\PackageAttributeResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Parser;

/**
 * Update `extra.branch-alias` in each package of a monorepo checkout, so that the alias for the
 * development branch matches the version line of the latest release of that package.
 */
class BranchAliasCommand extends AbstractReleaseCommand
{
    private const GITSPLIT_FILE = '.gitsplit.yml';
    private const COMPOSER_FILE = 'composer.json';

    private BranchAliasUpdater $updater;
    private string $branch;
    private bool $dry_run;

    public function __construct(?BranchAliasUpdater $updater = null)
    {
        parent::__construct();
        $this->updater = $updater ?? new BranchAliasUpdater();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName('update:branch-alias')
            ->setDescription('Update composer branch aliases to match the latest release of each package')
            ->addOption('path', null, InputOption::VALUE_OPTIONAL, 'path to a monorepo checkout (default: working directory)')
            ->addOption('token', ['t'], InputOption::VALUE_OPTIONAL, 'github token')
            ->addOption('branch', null, InputOption::VALUE_OPTIONAL, 'branch the alias applies to (default: main)')
            ->addOption('filter', null, InputOption::VALUE_OPTIONAL, 'filter by repository prefix')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'report changes without writing them')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->token = $input->getOption('token');
        $this->branch = $input->getOption('branch') ?? 'main';
        $this->dry_run = $input->getOption('dry-run');
        $path = rtrim($input->getOption('path') ?? (string) getcwd(), '/');
        $this->registerInputAndOutput($input, $output);

        $this->client = Psr18ClientDiscovery::find();
        $this->parser = new Parser();

        try {
            $found = $this->find_split_packages($path, $input->getOption('filter'));
        } catch (\Exception $e) {
            $this->output->writeln("<error>{$e->getCode()} {$e->getMessage()}</error>");

            return Command::FAILURE;
        }
        if ($found === null) {
            return Command::FAILURE;
        }
        if (count($found) === 0) {
            $this->output->writeln('<error>No packages found!</error>');

            return Command::FAILURE;
        }

        $branchKey = "dev-{$this->branch}";
        $this->output->writeln(sprintf('<info>Updating %s aliases in %s</info>', $branchKey, $path));

        $table = new Table($this->output);
        $table->setHeaders(['Package', 'Latest release', 'Current alias', 'New alias', 'Action']);
        $bar = new ProgressBar($this->output, count($found));
        $bar->start();
        foreach ($found as $repository) {
            $repository->latestRelease = $this->get_latest_release($repository);
            $bar->advance();
            $table->addRow($this->handle_repository($repository, $path, $branchKey));
        }
        $bar->finish();
        $this->output->writeln('');
        $table->render();
        $this->output->writeln($this->dry_run
            ? '<comment>[DRY-RUN] no files were changed</comment>'
            : '<info>Review the changes with `git diff`, then commit them and open a pull request.</info>');

        return Command::SUCCESS;
    }

    /**
     * The packages of the monorepo checked out at $path, from its own .gitsplit.yml: the root
     * composer.json of a monorepo is not named after its repository (core is
     * `open-telemetry/opentelemetry`), so the presence of split config is what identifies it.
     *
     * @return array<Repository>|null null when $path is not a monorepo checkout
     */
    private function find_split_packages(string $path, ?string $filter): ?array
    {
        $gitsplitFile = $path . '/' . self::GITSPLIT_FILE;
        $composerFile = $path . '/' . self::COMPOSER_FILE;
        if (!file_exists($gitsplitFile) || !file_exists($composerFile)) {
            $repos = implode(' or ', self::AVAILABLE_REPOS);
            $this->output->writeln(sprintf(
                '<error>%s is not a monorepo checkout (no %s and %s found)</error>',
                $path,
                self::GITSPLIT_FILE,
                self::COMPOSER_FILE
            ));
            $this->output->writeln("<comment>Run this command from a {$repos} checkout, or pass --path=</comment>");

            return null;
        }
        $config = $this->parser->parse((string) file_get_contents($gitsplitFile));
        $name = (string) PackageAttributeResolver::create($composerFile)->resolve(ConfigAttributes::NAME);

        return $this->map_gitsplit_repositories($config['splits'] ?? [], $name, $filter);
    }

    /**
     * @return array<string>
     */
    private function handle_repository(Repository $repository, string $path, string $branchKey): array
    {
        $package = $repository->downstream->project;
        $version = $repository->latestRelease->version ?? null;
        if ($version === null) {
            return [$package, 'none', '', '', 'skipped, no release'];
        }
        $alias = BranchAliasUpdater::aliasForVersion($version);
        if ($alias === null) {
            return [$package, $version, '', '', 'skipped, cannot parse version'];
        }

        $composerFile = "{$path}/{$repository->upstream->path}/composer.json";
        $update = $this->updater->update($composerFile, $branchKey, $alias, $this->dry_run);
        $action = $update->status === BranchAliasStatus::Updated && $this->dry_run
            ? 'would update'
            : $update->status->description();
        $this->output->isVerbose() && $this->output->writeln("[{$package}] {$composerFile}: {$action}");

        $aliased = !in_array($update->status, [BranchAliasStatus::Missing, BranchAliasStatus::NotFound], true);

        return [
            $package,
            $version,
            $update->current ?? '',
            $aliased ? $alias : '',
            $action,
        ];
    }
}
