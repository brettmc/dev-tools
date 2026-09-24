<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Release;

use Http\Discovery\Psr18ClientDiscovery;
use OpenTelemetry\DevTools\Console\Release\Repository;
use OpenTelemetry\DevTools\Package\Composer\BranchAliasStatus;
use OpenTelemetry\DevTools\Package\Composer\BranchAliasUpdater;
use OpenTelemetry\DevTools\Package\Composer\ConfigAttributes;
use OpenTelemetry\DevTools\Package\Composer\PackageAttributeResolver;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
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
    private ?ClientInterface $clientOverride;
    private string $branch;
    private bool $dry_run;
    /** Packages this command was asked to update but could not, which makes the run a failure. */
    private int $failures = 0;

    public function __construct(?BranchAliasUpdater $updater = null, ?ClientInterface $client = null)
    {
        parent::__construct();
        $this->updater = $updater ?? new BranchAliasUpdater();
        //held aside rather than assigned to $this->client, so that discovery stays lazy: it throws
        //when no PSR-18 client is installed, which should not happen merely by listing the commands
        $this->clientOverride = $client;
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

        $this->client = $this->clientOverride ?? Psr18ClientDiscovery::find();
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
     * The composer.json of the split package at $prefix, or null when it lies outside $root.
     *
     * The prefixes are read from the checkout's own .gitsplit.yml, which is committed content: a
     * prefix of `../other-project`, or one that is a symlink pointing out of the tree, would
     * otherwise let this command rewrite a composer.json that the caller never selected with --path.
     */
    private function contained_composer_file(string $root, string $prefix): ?string
    {
        $target = "{$root}/{$prefix}/" . self::COMPOSER_FILE;

        //realpath resolves symlinks, so prefer it where it answers; it returns false for a path that
        //does not exist and for every stream wrapper (vfs:// in the tests), hence the lexical
        //fallback, which is what makes containment checkable without touching the real filesystem.
        $canonicalRoot = realpath($root) ?: self::normalize_path($root);
        $canonicalTarget = realpath($target) ?: self::normalize_path($target);

        $prefixOfRoot = rtrim($canonicalRoot, '/') . '/';

        return str_starts_with($canonicalTarget, $prefixOfRoot) ? $canonicalTarget : null;
    }

    /**
     * Collapse `.` and `..` segments textually, so that a path can be compared against a root
     * without the filesystem being consulted. Any stream wrapper scheme is preserved.
     */
    private static function normalize_path(string $path): string
    {
        $scheme = '';
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $path, $matches)) {
            $scheme = $matches[0];
            $path = substr($path, strlen($scheme));
        }
        $absolute = str_starts_with($path, '/');

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                //popping past the start cannot be used to escape: the result is then shorter than
                //the root it is compared against, so containment fails
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return $scheme . ($absolute ? '/' : '') . implode('/', $segments);
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

        $composerFile = $this->contained_composer_file($path, $repository->upstream->path);
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
