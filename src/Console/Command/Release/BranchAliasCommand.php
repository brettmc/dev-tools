<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Release;

use OpenTelemetry\DevTools\Console\Release\GitHub;
use OpenTelemetry\DevTools\Console\Release\Repository;
use OpenTelemetry\DevTools\Package\Composer\BranchAliasStatus;
use OpenTelemetry\DevTools\Package\Composer\BranchAliasUpdater;
use OpenTelemetry\DevTools\Util\Path;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Update `extra.branch-alias` in each package of a monorepo checkout, so that the alias for the
 * development branch matches the version line of the latest release of that package.
 */
class BranchAliasCommand extends AbstractReleaseCommand
{

    private BranchAliasUpdater $updater;
    private string $branch;
    /** Packages this command was asked to update but could not, which makes the run a failure. */
    private int $failures = 0;

    public function __construct(?BranchAliasUpdater $updater = null, ?GitHub $github = null)
    {
        parent::__construct($github);
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
        $this->token = $this->token($input);
        $this->branch = $input->getOption('branch') ?? 'main';
        $this->dry_run = $input->getOption('dry-run');
        $this->registerInputAndOutput($input, $output);
        $path = $this->checkout_path($input->getOption('path'));
        if ($path === null) {
            return Command::FAILURE;
        }

        $monorepo = $this->read_monorepo($path);
        if ($monorepo === null) {
            return Command::FAILURE;
        }
        $found = $this->split_repositories($monorepo, $monorepo->name, $input->getOption('filter'));
        if (count($found) === 0) {
            $this->output->writeln('<error>No packages found!</error>');

            return Command::FAILURE;
        }

        $branchKey = "dev-{$this->branch}";

        //the table grows a row per package as it is resolved, which is the progress report. Logging
        //goes to a section above it so that the two cannot redraw over each other.
        $logSection = $this->redrawable_section($output);
        $tableSection = $this->redrawable_section($output);
        if ($logSection !== null) {
            $this->registerOutput($logSection);
        }
        $reportSection = $tableSection ?? $this->output;

        $this->output->writeln(sprintf('<info>Updating %s aliases in %s</info>', $branchKey, $path));

        $table = new Table($reportSection);
        $table->setHeaders(['Package', 'Latest release', 'Current alias', 'New alias', 'Action']);
        foreach ($found as $repository) {
            $repository->latestRelease = $this->get_latest_release($repository);
            $row = $this->handle_repository($repository, $path, $branchKey);
            //appendRow redraws the table in place; without a section it can only be rendered once
            $tableSection === null ? $table->addRow($row) : $table->appendRow($row);
        }
        if ($tableSection === null) {
            $table->render();
        }
        if ($this->failures > 0) {
            //the report has the detail per package; this is so that the exit code, and the last line
            //of output, do not claim a success that some of the packages did not have
            $reportSection->writeln(sprintf(
                '<error>%d of %d packages could not be updated (see the report above)</error>',
                $this->failures,
                count($found)
            ));

            return Command::FAILURE;
        }
        $reportSection->writeln($this->dry_run
            ? '<comment>[DRY-RUN] no files were changed</comment>'
            : '<info>Review the changes with `git diff`, then commit them and open a pull request.</info>');

        return Command::SUCCESS;
    }

    /**
     * A region of the terminal that can be redrawn independently of the rest, or null when $output
     * cannot support one: a non-console output (eg a CommandTester stream), or an undecorated one,
     * where clearing is a no-op and an in-place redraw would append a whole extra copy instead.
     *
     * Sections are stacked in creation order, so the order they are requested is the layout.
     */
    private function redrawable_section(OutputInterface $output): ?ConsoleSectionOutput
    {
        return $output instanceof ConsoleOutputInterface && $output->isDecorated()
            ? $output->section()
            : null;
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

        $composerFile = Path::contained($path, $repository->upstream->path, self::COMPOSER_FILE);
        if ($composerFile === null) {
            $this->output->writeln(sprintf(
                '<error>[%s] split prefix "%s" resolves outside %s, refusing to write</error>',
                $package,
                $repository->upstream->path,
                $path
            ));
            $this->failures++;

            return [$package, $version, '', '', 'skipped, outside monorepo'];
        }
        $update = $this->updater->update($composerFile, $branchKey, $alias, $this->dry_run);
        if ($update->status === BranchAliasStatus::WriteFailed) {
            $this->output->writeln(sprintf('<error>[%s] could not write %s</error>', $package, $composerFile));
            $this->failures++;
        }
        $action = $update->status === BranchAliasStatus::Updated && $this->dry_run
            ? 'would update'
            : $update->status->description();
        $this->output->isVerbose() && $this->output->writeln("[{$package}] {$composerFile}: {$action}");

        //the "new alias" column states what the file now says, so a failed write must not fill it in
        $aliased = !in_array($update->status, [
            BranchAliasStatus::Missing,
            BranchAliasStatus::NotFound,
            BranchAliasStatus::WriteFailed,
        ], true);

        return [
            $package,
            $version,
            $update->current ?? '',
            $aliased ? $alias : '',
            $action,
        ];
    }
}
