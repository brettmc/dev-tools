<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Release;

use Composer\Semver\VersionParser;
use InvalidArgumentException;
use OpenTelemetry\DevTools\Console\Release\Dashboard;
use OpenTelemetry\DevTools\Console\Release\GitHub;
use OpenTelemetry\DevTools\Console\Release\PrepEntry;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogException;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogLinks;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogParser;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogRewriter;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogSection;
use OpenTelemetry\DevTools\Package\Changelog\VersionSourceResolver;
use OpenTelemetry\DevTools\Package\Changelog\VersionSuggester;
use OpenTelemetry\DevTools\Package\Composer\BranchAliasStatus;
use OpenTelemetry\DevTools\Package\Composer\BranchAliasUpdater;
use OpenTelemetry\DevTools\Package\Composer\ReplaceUpdater;
use OpenTelemetry\DevTools\Package\Git\GitRunner;
use OpenTelemetry\DevTools\Package\GitSplit\MonorepoCheckout;
use OpenTelemetry\DevTools\Package\Release\PackageRelease;
use OpenTelemetry\DevTools\Package\Release\ReleaseOrderChecker;
use OpenTelemetry\DevTools\Package\Release\ReleaseOrderReport;
use OpenTelemetry\DevTools\Package\Spi\PackageDependencyReader;
use OpenTelemetry\DevTools\Util\Path;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Suggest the next version of each package of a monorepo checkout from its CHANGELOG.md, and
 * prepare the checkout for tagging: the Unreleased section becomes the dated release, the
 * `dev-main` alias moves to the new version's line, and the root `replace` follows the aliases.
 */
class ReleasePrepCommand extends AbstractReleaseCommand
{
    private const CHANGELOG_FILE = 'CHANGELOG.md';
    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/';
    private const SKIP = 'skip';

    private const BRANCH_KEY = 'dev-main';

    private ChangelogParser $changelogParser;
    private ChangelogRewriter $rewriter;
    private VersionSuggester $suggester;
    private VersionSourceResolver $versionSources;
    private ReleaseOrderChecker $orderChecker;
    private VersionParser $versionParser;
    private PackageDependencyReader $dependencyReader;
    private BranchAliasUpdater $aliasUpdater;
    private ReplaceUpdater $replaceUpdater;
    private GitRunner $git;
    private int $failures = 0;
    /** heading of the list of file changes being printed */
    private ?string $changesHeading = null;
    /** @var array<PrepEntry> the assessed packages, for the JSON report */
    private array $entries = [];
    private ReleaseOrderReport $order;
    /** the checkout, and the upstream repository it is of, once read */
    private string $path = '';
    private string $upstream = '';

    public function __construct(?GitHub $github = null, ?PackageDependencyReader $dependencyReader = null, ?GitRunner $git = null)
    {
        parent::__construct($github);
        $this->changelogParser = new ChangelogParser();
        $this->rewriter = new ChangelogRewriter();
        $this->suggester = new VersionSuggester();
        $this->versionSources = new VersionSourceResolver();
        $this->orderChecker = new ReleaseOrderChecker();
        $this->versionParser = new VersionParser();
        $this->dependencyReader = $dependencyReader ?? new PackageDependencyReader();
        $this->aliasUpdater = new BranchAliasUpdater();
        $this->replaceUpdater = new ReplaceUpdater();
        $this->git = $git ?? new GitRunner();
        $this->order = new ReleaseOrderReport();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName('release:prep')
            ->setDescription('Suggest the next version of each package from its CHANGELOG.md, and prepare the changelogs for tagging')
            ->addOption('path', null, InputOption::VALUE_OPTIONAL, 'path to a monorepo checkout (default: working directory)')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'upstream branch the checkout must include (default: main)')
            ->addOption('token', ['t'], InputOption::VALUE_OPTIONAL, 'github token (default: GITHUB_TOKEN, optional)')
            ->addOption('filter', null, InputOption::VALUE_OPTIONAL, 'filter by repository prefix')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'report the suggestions without writing anything')
            ->addOption('date', null, InputOption::VALUE_OPTIONAL, 'release date, YYYY-MM-DD (default: today)')
            ->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'release a package as a given version, or not at all, <package>=<version|skip>')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'report format: text, markdown, json (on stdout with the rest on stderr), or dashboard (an issue body on stdout, needs --dry-run)', 'text')
            ->addOption('tracker', null, InputOption::VALUE_REQUIRED, 'with --format=dashboard, the <org>/<project> whose issue tracker holds the dashboard (default: the monorepo)')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        if (!in_array($format, ['text', 'markdown', 'json', 'dashboard'], true)) {
            $output->writeln('<error>--format must be text, markdown, json or dashboard</error>');

            return Command::FAILURE;
        }
        if ($format === 'text' || $format === 'markdown') {
            return $this->prepare($input, $output, $format === 'markdown');
        }
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        if ($format === 'dashboard') {
            return $this->dashboard($input, $output, $errors);
        }

        try {
            $status = $this->prepare($input, $errors, false);
        } catch (\Throwable $e) {
            //the JSON is printed however the prep ends, so a workflow can always parse it
            $errors->writeln("<error>{$e->getMessage()}</error>");
            $status = Command::FAILURE;
        }
        $output->writeln($this->json_report($status === Command::SUCCESS), OutputInterface::OUTPUT_RAW);

        return $status;
    }

    /**
     * Print the body of a release dashboard issue on $output, and only when the prep succeeds, so a
     * failed run never replaces a good body with a partial one.
     */
    private function dashboard(InputInterface $input, OutputInterface $output, OutputInterface $errors): int
    {
        if (!$input->getOption('dry-run')) {
            $errors->writeln('<error>--format=dashboard needs --dry-run, as it reports what would be released</error>');

            return Command::FAILURE;
        }
        $tracker = $input->getOption('tracker');
        if ($tracker !== null && preg_match('#^[\w.-]+/[\w.-]+$#', $tracker) !== 1) {
            $errors->writeln('<error>--tracker must be <org>/<project>, such as open-telemetry/opentelemetry-php</error>');

            return Command::FAILURE;
        }

        try {
            if ($this->prepare($input, $errors, false) !== Command::SUCCESS) {
                return Command::FAILURE;
            }
            $body = (new Dashboard($this->upstream, $this->source_branch, $this->git->head($this->path), $tracker))
                ->render($this->entries, $this->order);
        } catch (\Throwable $e) {
            $errors->writeln("<error>{$e->getMessage()}</error>");

            return Command::FAILURE;
        }
        $output->write($body, false, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }

    private function prepare(InputInterface $input, OutputInterface $output, bool $markdown): int
    {
        $this->registerInputAndOutput($input, $output);
        //optional: the release lookups are public, and a token only raises the rate limit
        $this->token = $this->token($input);
        $dryRun = $this->dry_run = (bool) $input->getOption('dry-run');
        $this->source_branch = $input->getOption('branch') ?? 'main';
        $path = $this->checkoutPath($input->getOption('path'));
        if ($path === null) {
            return Command::FAILURE;
        }
        $this->path = $path;

        $date = $input->getOption('date') ?? date('Y-m-d');
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
            $output->writeln("<error>--date must be YYYY-MM-DD, got \"{$date}\"</error>");

            return Command::FAILURE;
        }
        $overrides = $this->parse_overrides($input->getOption('set'));
        if ($overrides === null) {
            return Command::FAILURE;
        }

        $monorepo = $this->read_monorepo($path);
        if ($monorepo === null) {
            return Command::FAILURE;
        }
        $found = $this->split_repositories($monorepo, $monorepo->name, $input->getOption('filter'));
        if (count($found) === 0) {
            $output->writeln('<error>No packages found!</error>');

            return Command::FAILURE;
        }
        if (!$this->includes_branch_tip($path, $monorepo)) {
            return Command::FAILURE;
        }
        $entries = [];
        foreach ($found as $repository) {
            $entry = new PrepEntry($repository);
            [$entry->package, $entry->composer] = $this->split_composer($path, $entry->repository);
            $entries[] = $entry;
        }
        $unknown = array_diff(array_keys($overrides), $this->known_names($entries));
        if ($unknown !== []) {
            $output->writeln(sprintf('<error>--set names no package of this checkout: %s</error>', implode(', ', $unknown)));

            return Command::FAILURE;
        }

        try {
            $this->read_package_dependencies($entries, $path);
        } catch (RuntimeException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");

            return Command::FAILURE;
        }
        foreach ($entries as $entry) {
            $this->assess($entry, $path, $overrides);
        }
        $this->entries = $entries;

        $this->report($entries, $markdown);

        foreach ($entries as $entry) {
            if (!$dryRun && !$entry->forced) {
                $entry->version = $this->choose($entry);
            }
        }
        $this->order = $this->report_order($entries);

        $written = [];
        $aliases = [];
        foreach ($entries as $entry) {
            if ($entry->version === null) {
                continue;
            }
            if (!$dryRun) {
                if (!$this->write($entry, $date)) {
                    continue;
                }
                $written[] = $entry->repository->upstream->path . '/' . self::CHANGELOG_FILE;
            }
            $alias = $this->update_alias($entry, $path, $dryRun);
            if ($alias !== null) {
                $aliases[$entry->package] = $alias;
                $dryRun || $written[] = $entry->repository->upstream->path . '/' . self::COMPOSER_FILE;
            }
        }
        if ($this->update_replace($path, $aliases, $dryRun) && !$dryRun) {
            $written[] = self::COMPOSER_FILE;
        }

        if ($this->failures > 0) {
            $output->writeln(sprintf('<error>%d problems, see above</error>', $this->failures));

            return Command::FAILURE;
        }
        if ($dryRun) {
            $output->writeln('<comment>[DRY-RUN] no files were changed</comment>');
        } elseif ($written !== []) {
            $output->writeln(sprintf('<info>Changed %d files:</info>', count($written)));
            foreach ($written as $file) {
                $output->writeln("  {$file}");
            }
            $output->writeln('<info>Review with `git diff`, open a pull request, and once it is merged run `release:run` to tag.</info>');
        } else {
            $output->writeln('<info>Nothing to release.</info>');
        }

        return Command::SUCCESS;
    }

    private function json_report(bool $completed): string
    {
        $packages = array_map(static fn (PrepEntry $entry): array => [
            'package' => $entry->package,
            'repository' => $entry->label(),
            'latest' => $entry->latest,
            'version' => $entry->version,
            'reason' => $entry->reason,
            'warnings' => $entry->warnings,
            'undecided' => $entry->undecided && $entry->version === null,
            'changes' => (object) ($entry->changes?->all() ?? []),
        ], $this->entries);

        return json_encode([
            'packages' => $packages,
            'order' => $this->order->order,
            'constraint_warnings' => $this->order->warnings,
            'release' => in_array(true, array_map(static fn (PrepEntry $entry): bool => $entry->version !== null, $this->entries), true),
            'completed' => $completed,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Whether the checkout has everything on the upstream branch. A prep from a checkout behind it
     * dates the release without the changelog entries it is missing, and they stay under the new
     * Unreleased. Commits and uncommitted changes on top of the branch are fine: they are the prep.
     *
     * @return bool false when the prep should stop, having said why
     */
    private function includes_branch_tip(string $path, MonorepoCheckout $monorepo): bool
    {
        $upstream = $this->upstream($path, $monorepo);
        if ($upstream === null) {
            return false;
        }
        $this->upstream = $upstream;

        try {
            $tip = $this->branch_tip($upstream);
        } catch (\Exception $e) {
            $this->output->writeln("<error>{$e->getMessage()}</error>");

            return false;
        }

        try {
            if (!$this->git->hasCommit($path, $tip)) {
                return !$this->problem(sprintf(
                    '%s does not have %s %s at %s. Fetch it from %s, rebase onto it, and run release:prep again.',
                    $path,
                    $upstream,
                    $this->source_branch,
                    $tip,
                    "https://github.com/{$upstream}",
                ));
            }
            if (!$this->git->isAncestor($path, $tip, 'HEAD')) {
                return !$this->problem(sprintf(
                    '%s does not include %s %s at %s. Rebase onto it and run release:prep again.',
                    $path,
                    $upstream,
                    $this->source_branch,
                    $tip,
                ));
            }
        } catch (RuntimeException $e) {
            return !$this->problem("{$path} cannot be read by git: {$e->getMessage()}");
        }

        return true;
    }

    /**
     * @param array<string> $set values of --set
     * @return array<string, string>|null version, or `skip`, by package name, or null when a value is malformed
     */
    private function parse_overrides(array $set): ?array
    {
        $overrides = [];
        foreach ($set as $item) {
            [$name, $version] = array_pad(explode('=', $item, 2), 2, '');
            if ($name === '' || ($version !== self::SKIP && preg_match(self::VERSION_PATTERN, $version) !== 1)) {
                $this->output->writeln(sprintf('<error>--set expects <package>=<version|skip>, got "%s"</error>', $item));

                return null;
            }
            $overrides[$name] = $version;
        }

        return $overrides;
    }

    /**
     * @param array<PrepEntry> $entries
     * @return array<string>
     */
    private function known_names(array $entries): array
    {
        $names = [];
        foreach ($entries as $entry) {
            $names[] = $entry->repository->downstream->project;
            $names[] = $entry->package;
        }

        return $names;
    }

    /**
     * Attach the `#[PackageDependency]` constraints of the checkout's service providers to the package
     * each provider lives in.
     *
     * @param array<PrepEntry> $entries
     * @throws RuntimeException when the attributes cannot be read, eg no `composer install` yet
     */
    private function read_package_dependencies(array $entries, string $path): void
    {
        $prefixes = array_map(static fn (PrepEntry $entry): string => $entry->repository->upstream->path, $entries);
        $grouped = $this->dependencyReader->read($path)->byPrefix(array_values($prefixes));
        foreach ($entries as $entry) {
            $entry->spi = $grouped[$entry->repository->upstream->path] ?? [];
        }
    }

    /**
     * Point the package's `dev-main` alias at the line of its new version, unless it already covers
     * it. The version was chosen, so an alias ahead of it is lowered rather than kept.
     *
     * @return string|null the new alias, or null when it is left as it is
     */
    private function update_alias(PrepEntry $entry, string $path, bool $dryRun): ?string
    {
        $version = (string) $entry->version;
        $alias = BranchAliasUpdater::aliasForVersion($version);
        $current = $entry->composer['extra']['branch-alias'][self::BRANCH_KEY] ?? null;
        $file = Path::contained($path, $entry->repository->upstream->path, self::COMPOSER_FILE);
        if ($alias === null || $file === null || !is_string($current) || BranchAliasUpdater::covers($current, $version)) {
            return null;
        }

        $update = $this->aliasUpdater->update($file, self::BRANCH_KEY, $alias, $dryRun, allowLower: true);
        $line = "{$entry->package}: {$current} -> {$alias}";
        switch ($update->status) {
            case BranchAliasStatus::Updated:
                $this->changes('Branch aliases', $line);

                return $alias;
            case BranchAliasStatus::Lowered:
                $this->changes('Branch aliases', "<comment>{$line}, lowered</comment>");

                return $alias;
            case BranchAliasStatus::Ambiguous:
            case BranchAliasStatus::WriteFailed:
                $this->output->writeln("<error>[{$entry->label()}] {$file}: {$update->status->description()}</error>");
                $this->failures++;

                return null;
            default:
                return null;
        }
    }

    /**
     * Point the root `replace` entry of every split package at its `dev-main` alias, so that a
     * `#[PackageDependency]` on a package resolves inside the monorepo as it will once tagged.
     *
     * @param array<string, string> $aliases aliases just changed, by package name, which a dry run
     *        has not written
     */
    private function update_replace(string $path, array $aliases, bool $dryRun): bool
    {
        try {
            /** @var array<string, string> $versions */
            $versions = array_filter([...MonorepoCheckout::read($path)->aliases, ...$aliases], 'is_string');
            $changes = $this->replaceUpdater->update($path . '/' . self::COMPOSER_FILE, $versions, $dryRun);
        } catch (RuntimeException $e) {
            $this->output->writeln("<error>{$e->getMessage()}</error>");
            $this->failures++;

            return false;
        }
        foreach ($changes as $package => [$current, $version]) {
            $this->changes('Root replace', "{$package}: {$current} -> {$version}");
        }

        return $changes !== [];
    }

    private function changes(string $heading, string $line): void
    {
        if ($this->changesHeading !== $heading) {
            $this->output->writeln('');
            $this->output->writeln("{$heading}:");
            $this->changesHeading = $heading;
        }
        $this->output->writeln("  {$line}");
    }

    /**
     * Print the order to tag packages that need each other's new versions, and warn about constraints
     * the new versions break. Silent when there is nothing to say.
     *
     * @param array<PrepEntry> $entries
     */
    private function report_order(array $entries): ReleaseOrderReport
    {
        $packages = [];
        foreach ($entries as $entry) {
            $packages[] = new PackageRelease(
                $entry->package,
                $entry->version,
                $entry->latest,
                $this->constraints($entry->composer['require'] ?? null),
                $this->constraints($entry->composer['conflict'] ?? null),
                $entry->spi,
            );
        }
        $report = $this->orderChecker->check($packages);
        $this->print_order($report);

        return $report;
    }

    /**
     * @param array<string, string> $overrides
     */
    private function assess(PrepEntry $entry, string $path, array $overrides): void
    {
        $repository = $entry->repository;
        $project = $repository->downstream->project;
        $prefix = $repository->upstream->path;

        $file = Path::contained($path, $prefix, self::CHANGELOG_FILE);
        if ($file === null) {
            $this->output->writeln(sprintf('<error>[%s] split prefix "%s" resolves outside %s, refusing to write</error>', $entry->label(), $prefix, $path));
            $this->failures++;
            $entry->reason = 'skipped, outside monorepo';

            return;
        }
        if (!is_file($file)) {
            $entry->reason = 'skipped, no CHANGELOG.md';

            return;
        }

        try {
            $changelog = (string) file_get_contents($file);
            $entry->changes = $this->changelogParser->parseUnreleased($changelog);
            $entry->links = new ChangelogLinks($changelog);
        } catch (ChangelogException $e) {
            $this->output->writeln("<error>[{$entry->label()}] {$prefix}/" . self::CHANGELOG_FILE . ": {$e->getMessage()}</error>");
            $this->failures++;
            $entry->reason = 'error, ' . $e->getMessage();

            return;
        }
        $entry->file = $file;

        $latest = $this->get_latest_release($repository)?->version;
        $entry->latest = $latest;
        $override = $overrides[$project] ?? $overrides[$entry->package] ?? null;

        if ($override === self::SKIP) {
            $entry->forced = true;
            $entry->reason = 'skipped by --set';

            return;
        }
        if ($override !== null) {
            $entry->version = $override;
            $entry->forced = true;
            $entry->reason = 'set by --set';
            $entry->warnings = $this->major_warnings($latest, $override, $entry);

            return;
        }
        if ($latest === null) {
            $entry->reason = 'skipped, no release to base a version on';

            return;
        }
        $suggestion = $this->suggester->suggest(
            $latest,
            $entry->changes,
            $this->versionSources->resolve($entry->package, $path, $prefix)
        );
        $entry->reason = $suggestion->reason;
        $entry->warnings = $suggestion->warnings;
        $entry->version = $suggestion->version;
        $entry->undecided = $suggestion->undecided;
    }

    /**
     * @return list<string>
     */
    private function major_warnings(?string $latest, string $version, PrepEntry $entry): array
    {
        $before = $latest === null ? null : $this->major($latest);
        $after = $this->major($version);
        if ($before === null || $after === null || $after <= $before) {
            return [];
        }
        $changes = $entry->changes;
        if ($changes !== null
            && $changes->entries(ChangelogSection::BreakingChanges) === []
            && $changes->entries(ChangelogSection::Removed) === []) {
            return ['major release, but Unreleased records no breaking changes'];
        }

        return [];
    }

    private function major(string $version): ?int
    {
        try {
            return (int) explode('.', $this->versionParser->normalize($version), 2)[0];
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    /**
     * @param array<PrepEntry> $entries
     */
    private function report(array $entries, bool $markdown): void
    {
        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                $entry->label(),
                $entry->latest ?? 'none',
                $entry->version ?? '',
                $entry->reason,
            ];
        }
        $table = new Table($this->output);
        $table->setStyle($markdown ? 'markdown' : 'default');
        $table->setHeaders(['Package', 'Latest', 'Suggested', 'Reason']);
        $table->setRows($rows);
        $table->render();
        foreach ($entries as $entry) {
            if ($entry->warnings === []) {
                continue;
            }
            $this->output->writeln('');
            $this->output->writeln($markdown ? "**{$entry->label()}**" : "<comment>{$entry->label()}: check before releasing</comment>");
            foreach ($entry->warnings as $warning) {
                //a pull request body has no link definitions, so [#12] would show as written
                $warning = $markdown ? ($entry->links?->inline($warning, $this->upstream) ?? $warning) : $warning;
                $this->output->writeln('- ' . str_replace("\n", "\n  ", $warning));
            }
        }
        $undecided = array_filter($entries, static fn (PrepEntry $entry): bool => $entry->undecided);
        if ($undecided !== []) {
            $this->output->writeln('');
            $this->output->writeln($markdown
                ? '**No version suggested**, choose one with `--set=<package>=<version>`:'
                : OutputFormatter::escape('No version suggested, choose one with --set=<package>=<version>:'));
            foreach ($undecided as $entry) {
                $this->output->writeln('- ' . $entry->label());
            }
        }
    }

    /**
     * Ask which version to release as. Null skips the package.
     */
    private function choose(PrepEntry $entry): ?string
    {
        $suggested = $entry->version;
        if (!$this->input->isInteractive()) {
            return $suggested;
        }
        if ($suggested === null && !$entry->undecided) {
            return null;
        }
        $question = $suggested === null
            ? "[{$entry->label()}] latest {$entry->latest}, {$entry->reason}. Version to release (blank to skip)"
            : "[{$entry->label()}] latest {$entry->latest}. Version to release (blank for {$suggested}, `skip` to skip)";
        $answer = $this->getStyle()->ask($question, $suggested, static function (?string $value): ?string {
            if ($value === null || $value === '' || $value === 'skip' || preg_match(self::VERSION_PATTERN, $value) === 1) {
                return $value;
            }

            throw new InvalidArgumentException("\"{$value}\" is not a version like 1.2.3");
        });
        if ($answer === null || $answer === '' || $answer === 'skip') {
            return null;
        }
        foreach ($this->major_warnings($entry->latest, $answer, $entry) as $warning) {
            $this->output->writeln("<comment>[{$entry->label()}] {$warning}</comment>");
        }

        return $answer;
    }

    private function write(PrepEntry $entry, string $date): bool
    {
        $file = (string) $entry->file;

        try {
            $updated = $this->rewriter->release((string) file_get_contents($file), (string) $entry->version, $date);
        } catch (ChangelogException $e) {
            $this->output->writeln("<error>[{$entry->label()}] {$e->getMessage()}</error>");
            $this->failures++;

            return false;
        }
        if (@file_put_contents($file, $updated) === false) {
            $this->output->writeln("<error>[{$entry->label()}] could not write {$file}</error>");
            $this->failures++;

            return false;
        }
        $this->output->isVerbose() && $this->output->writeln("[{$entry->label()}] wrote {$file}");

        return true;
    }
}
