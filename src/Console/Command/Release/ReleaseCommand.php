<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Release;

use Composer\Semver\Comparator;
use OpenTelemetry\DevTools\Console\Release\Commit;
use OpenTelemetry\DevTools\Console\Release\Diff;
use OpenTelemetry\DevTools\Console\Release\GitHub;
use OpenTelemetry\DevTools\Console\Release\GitHubException;
use OpenTelemetry\DevTools\Console\Release\Release;
use OpenTelemetry\DevTools\Console\Release\ReleaseNotes;
use OpenTelemetry\DevTools\Console\Release\Repository;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogException;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogReleases;
use OpenTelemetry\DevTools\Package\Composer\BranchAliasUpdater;
use OpenTelemetry\DevTools\Package\Git\GitRunner;
use OpenTelemetry\DevTools\Package\GitSplit\MonorepoCheckout;
use OpenTelemetry\DevTools\Package\Release\PackageRelease;
use OpenTelemetry\DevTools\Package\Release\ReleaseOrderChecker;
use OpenTelemetry\DevTools\Package\Spi\PackageDependencyReader;
use OpenTelemetry\DevTools\Package\Spi\ProviderDependencies;
use OpenTelemetry\DevTools\Util\Path;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class ReleaseCommand extends AbstractReleaseCommand
{
    private const CHANGELOG_FILE = 'CHANGELOG.md';
    /**
     * @var list<array{0: Repository, 1: string}> packages released by this run, with their version
     */
    private array $released = [];
    /** @var list<array{repository: string, version: string, target: string, latest: bool, draft: bool, url: ?string}> */
    private array $releases = [];
    /** @var list<array{repository: string, reason: string}> */
    private array $skipped = [];
    /** @var array<string, ?ChangelogReleases> CHANGELOG.md in the checkout, by downstream repository */
    private array $changelogs = [];
    private PackageDependencyReader $dependencyReader;
    private GitRunner $git;
    /** the monorepo commit being released */
    private ?string $sha = null;
    /** @var array<string, string> the split commit with the content of $sha, by downstream repository */
    private array $splitCommits = [];
    private string $checkoutPath;

    public function __construct(?GitHub $github = null, ?PackageDependencyReader $dependencyReader = null, ?GitRunner $git = null)
    {
        parent::__construct($github);
        $this->dependencyReader = $dependencyReader ?? new PackageDependencyReader();
        $this->git = $git ?? new GitRunner();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName('release:run')
            ->setDescription('Find unreleased changes and create a new release')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'dry run')
            ->addOption('token', ['t'], InputOption::VALUE_OPTIONAL, 'github token')
            ->addOption('branch', null, InputOption::VALUE_OPTIONAL, 'branch to tag off (default: main)')
            ->addOption('repo', ['r'], InputOption::VALUE_OPTIONAL, 'repo to handle (core, contrib; default: that of --path)')
            ->addOption('filter', null, InputOption::VALUE_OPTIONAL, 'filter by repository prefix')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'checkout of the monorepo being released, at the commit being tagged (required)')
            ->addOption('sha', null, InputOption::VALUE_REQUIRED, 'monorepo commit to release, which must be on --branch (default: the tip of --branch)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text, or json on stdout with the rest on stderr', 'text')
        ;
    }
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        assert($output instanceof ConsoleOutputInterface);
        $format = $input->getOption('format');
        if (!in_array($format, ['text', 'json'], true)) {
            $output->writeln('<error>--format must be text or json</error>');

            return Command::FAILURE;
        }
        $this->dry_run = $input->getOption('dry-run');
        if ($format === 'text') {
            return $this->release($input, $output);
        }

        try {
            $status = $this->release($input, $output->getErrorOutput());
        } catch (\Throwable $e) {
            //the JSON is what tells a workflow which packages were released before the failure
            $output->getErrorOutput()->writeln("<error>{$e->getMessage()}</error>");
            $status = Command::FAILURE;
        }
        $output->writeln(json_encode([
            'dry_run' => $this->dry_run,
            'sha' => $this->sha,
            'releases' => $this->releases,
            'skipped' => $this->skipped,
            'completed' => $status === Command::SUCCESS,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return $status;
    }

    private function release(InputInterface $input, OutputInterface $output): int
    {
        $this->token = $this->token($input);
        if ($this->token === null) {
            $output->writeln('<error>No github token provided (via --token or GITHUB_TOKEN env)</error>');

            return Command::FAILURE;
        }
        $this->source_branch = $input->getOption('branch') ?? 'main';
        if ($this->source_branch !== 'main' && !$input->isInteractive()) {
            //the version is checked against each split's latest release, which is main's
            $output->writeln("<error>--no-interaction releases only from main, as each package's latest release is main's. Run --branch={$this->source_branch} interactively.</error>");

            return Command::FAILURE;
        }
        $source = $input->getOption('repo');
        $filter = $input->getOption('filter');
        //sections redraw in place on stdout, which holds only the JSON in that format
        $progress_section = $output instanceof ConsoleOutputInterface ? $output->section() : $output;
        $main_section = $output instanceof ConsoleOutputInterface ? $output->section() : $output;
        $this->registerInputAndOutput($input, $main_section);
        if ($source && !array_key_exists($source, self::AVAILABLE_REPOS)) {
            $options = implode(',', array_keys(self::AVAILABLE_REPOS));
            $this->output->writeln("<error>Invalid source: {$source}. Options: {$options}</error>");

            return Command::FAILURE;
        }
        $path = $input->getOption('path');
        if ($path === null) {
            //the trees matched against the splits, and the tag order that core's #[PackageDependency]
            //attributes need, can only be read from a checkout
            $this->output->writeln('<error>release:run needs --path, a checkout of the monorepo at the commit being tagged, with `composer install` run</error>');

            return Command::FAILURE;
        }
        $path = $this->checkoutPath($path);
        if ($path === null) {
            return Command::FAILURE;
        }
        $checkout = $this->read_checkout($path, $source ? self::AVAILABLE_REPOS[$source] : null);
        if ($checkout === null) {
            return Command::FAILURE;
        }
        $this->checkoutPath = $checkout['path'];
        if (!$this->choose_sha($checkout, $input->getOption('sha'))) {
            return Command::FAILURE;
        }

        $repositories = [];
        $found = $this->split_repositories($checkout['monorepo'], $checkout['upstream'], $filter);
        if (count($found) === 0) {
            $this->output->writeln('<error>No repositories found!</error>');

            return Command::FAILURE;
        }
        $bar = new ProgressBar($progress_section, count($found));
        $bar->start();
        foreach ($found as $repository) {
            $bar->setMessage($repository->downstream->project);
            if (!$this->populate_release_details($repository)) {
                return Command::FAILURE;
            }
            if (!$this->to_be_released($repository)) {
                //publish_repositories() skips it; it is kept for the tag order of the rest
                $repository->diff = new Diff();
                $repositories[] = $repository;
                $bar->advance();

                continue;
            }
            if (!$this->find_split_commit($repository)) {
                return Command::FAILURE;
            }
            $diff = $this->get_diffs($repository);
            if ($diff === null) {
                return Command::FAILURE;
            }
            $repository->diff = $diff;

            $differences = $this->unexpected_commits($repository);
            if ($differences === []) {
                $repositories[] = $repository;
            } elseif (!$this->input->isInteractive()) {
                if ($this->problem("{$repository->downstream} has commits that are not upstream. Review them, then run release:run interactively.")) {
                    return Command::FAILURE;
                }
                $repositories[] = $repository;
            } elseif ($this->confirm('<question>Do you want to continue despite these differences? (y/N):</question> ', false)) {
                $repositories[] = $repository;
            } else {
                $this->output->writeln("[SKIP] Skipping {$repository->downstream} due to differences");
                $this->skip($repository, 'commits in the split that are not upstream');
            }
            $bar->advance();
        }
        $bar->finish();
        $repositories = $this->order($repositories, $checkout);
        $completed = $this->publish_repositories($repositories);
        $this->remind_about_branch_aliases();

        return $completed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Whether the package may be released, and so needs its split commit and diff. Without
     * interaction, a package with no newer dated changelog section is skipped, so a split that
     * will not be tagged must not stop the run.
     */
    private function to_be_released(Repository $repository): bool
    {
        if ($repository->commits === []) {
            return false;
        }

        return $this->input->isInteractive()
            || $this->suggest_version($this->changelogs[(string) $repository->downstream] ?? null, $repository->latestRelease?->version) !== null;
    }

    private function skip(Repository $repository, string $reason): void
    {
        $this->skipped[] = ['repository' => (string) $repository->downstream, 'reason' => $reason];
    }

    /**
     * For a given repository, populate:
     * 1. latest release
     * 2. unreleased commits to upstream path, since date of last release, up to the commit released
     * 3. its changelog in the checkout, which a package with changes must have
     *
     * @return bool false when the run should stop, having said why
     */
    private function populate_release_details(Repository $repository): bool
    {
        $this->output->isVerbose() && $this->output->writeln("<info>Processing: {$repository->downstream}</info>");

        $repository->latestRelease = $this->get_latest_release($repository);
        $commits = $this->unreleased_commits($repository);
        if ($commits === null) {
            return false;
        }
        $repository->commits = $commits;

        try {
            $changelog = $this->read_changelog($repository);
        } catch (ChangelogException $e) {
            return $commits === []
                || !$this->problem("{$repository->upstream->path}/" . self::CHANGELOG_FILE . ": {$e->getMessage()}");
        }
        $this->changelogs[(string) $repository->downstream] = $changelog;

        return $changelog !== null
            || $commits === []
            || !$this->problem("{$repository->downstream} has unreleased changes, but {$repository->upstream->path} has no CHANGELOG.md");
    }

    /**
     * The commits to the package's prefix in the checkout since its latest release, up to the
     * commit being released. The checkout has the full history, as read_checkout() refuses a
     * shallow one.
     *
     * @return array<Commit>|null null when the run should stop, having said why
     */
    private function unreleased_commits(Repository $repository): ?array
    {
        assert($this->sha !== null);
        $since = $repository->latestRelease === null ? null : $this->since($repository->latestRelease);

        try {
            $log = $this->git->log($this->checkoutPath, $this->sha, $repository->upstream->path, $since);
        } catch (\RuntimeException $e) {
            return $this->problem("The commits to {$repository->upstream->path} of {$this->sha} cannot be read by git: {$e->getMessage()}") ? null : [];
        }

        return $this->commits($log);
    }

    /**
     * The local checkout of $upstream, its HEAD, and the `#[PackageDependency]` attributes of its
     * providers. Null, having said why, when it cannot be read or is not fit to release from. The
     * head is null when git could not read it, which only a dry run carries on from.
     *
     * @param ?string $upstream the monorepo to release, or null for whichever the checkout is of
     * @return array{path: string, upstream: string, monorepo: MonorepoCheckout, head: ?string, dependencies: ProviderDependencies}|null
     */
    private function read_checkout(string $path, ?string $upstream): ?array
    {
        $monorepo = $this->read_monorepo($path);
        if ($monorepo === null) {
            return null;
        }
        $upstream = $this->upstream($path, $monorepo, $upstream);
        if ($upstream === null) {
            return null;
        }

        try {
            $head = $this->git->head($path);
            //git log stops without a word at the shallow boundary, so even a dry run would list
            //too few commits
            if ($this->git->isShallow($path)) {
                $this->output->writeln("<error>{$path} is a shallow clone, so its history may not reach each package's latest release. Clone it in full; with actions/checkout, set `fetch-depth: 0`.</error>");

                return null;
            }
            //an untracked file under a prefix is read as if it were in the release: a CHANGELOG.md
            //for the notes, or a provider for the tag order
            $changes = [
                ...$this->git->changes($path),
                ...$this->git->untracked($path, $monorepo->prefixes),
            ];
            if ($changes !== [] && $this->problem("{$path} has uncommitted changes, which would not be in the release:\n  " . implode("\n  ", $changes))) {
                return null;
            }
        } catch (\RuntimeException $e) {
            $head = null;
            if ($this->problem("{$path} cannot be read by git: {$e->getMessage()}")) {
                return null;
            }
        }

        try {
            $dependencies = $this->dependencyReader->read($path);
        } catch (\RuntimeException $e) {
            $this->output->writeln("<error>{$e->getMessage()}</error>");

            return null;
        }

        return ['path' => $path, 'upstream' => $upstream, 'monorepo' => $monorepo, 'head' => $head, 'dependencies' => $dependencies];
    }

    /**
     * Settle the monorepo commit to release: $sha, which must be on the branch, or else the tip of
     * the branch as the run starts. Everything after reads that commit rather than the branch, so a
     * merge during the run is neither tagged nor a reason to stop.
     *
     * @param array{path: string, upstream: string, monorepo: MonorepoCheckout, head: ?string, dependencies: ProviderDependencies} $checkout
     * @return bool false when the run should stop, having said why
     */
    private function choose_sha(array $checkout, ?string $sha): bool
    {
        if ($sha === null) {
            try {
                $sha = $this->branch_tip($checkout['upstream']);
            } catch (\Exception $e) {
                $this->output->writeln("<error>{$e->getMessage()}</error>");

                return false;
            }
            $this->sha = $sha;

            return $checkout['head'] === null || $checkout['head'] === $sha || !$this->problem(sprintf(
                '%s is at %s, but %s %s is at %s. Check out and pull %s before releasing.',
                $checkout['path'],
                $checkout['head'],
                $checkout['upstream'],
                $this->source_branch,
                $sha,
                $this->source_branch,
            ));
        }
        $this->sha = $sha;
        if ($checkout['head'] !== null && $checkout['head'] !== $sha && $this->problem(sprintf(
            '%s is at %s, but --sha is %s. Check out %s before releasing.',
            $checkout['path'],
            $checkout['head'],
            $sha,
            $sha,
        ))) {
            return false;
        }

        try {
            $tip = $this->branch_tip($checkout['upstream']);
        } catch (\Exception $e) {
            $this->output->writeln("<error>{$e->getMessage()}</error>");

            return false;
        }

        try {
            if (!$this->git->hasCommit($checkout['path'], $tip)) {
                return !$this->problem(sprintf(
                    '%s does not have %s %s at %s. Fetch it from %s and run release:run again.',
                    $checkout['path'],
                    $checkout['upstream'],
                    $this->source_branch,
                    $tip,
                    "https://github.com/{$checkout['upstream']}",
                ));
            }

            return $this->git->isAncestor($checkout['path'], $sha, $tip) || !$this->problem(sprintf(
                '%s is not on %s %s. Release a commit that has been merged.',
                $sha,
                $checkout['upstream'],
                $this->source_branch,
            ));
        } catch (\RuntimeException $e) {
            return !$this->problem("{$checkout['path']} cannot be read by git: {$e->getMessage()}");
        }
    }

    /**
     * Find the split commit with the same content as the package in the commit being released,
     * which is the one to tag. gitsplit rewrites every commit, so the trees are what match.
     *
     * The split's history is walked newest first back to its latest release, which bounds the walk.
     *
     * @return bool false when the run should stop, having said why
     */
    private function find_split_commit(Repository $repository): bool
    {
        $prefix = $repository->upstream->path;
        assert($this->sha !== null);

        try {
            $tree = $this->git->tree($this->checkoutPath, $this->sha, $prefix);
        } catch (\RuntimeException $e) {
            return !$this->problem("{$prefix} of {$this->sha} cannot be read by git: {$e->getMessage()}");
        }
        if ($tree === null) {
            return !$this->problem("{$this->sha} has no {$prefix}, but .gitsplit.yml splits it");
        }

        $since = $repository->latestRelease !== null ? $this->since($repository->latestRelease) : null;

        try {
            foreach ($this->github->commits((string) $repository->downstream, $this->source_branch, $since) as $commit) {
                if ($commit['tree'] === $tree) {
                    $this->splitCommits[(string) $repository->downstream] = $commit['sha'];

                    return true;
                }
            }
        } catch (GitHubException $e) {
            return !$this->problem("Could not list the commits of {$repository->downstream} {$this->source_branch}: {$e->getMessage()}");
        }

        return !$this->problem(sprintf(
            '%s %s has no commit with the %s of %s yet. gitsplit may not have pushed it; wait for it and run release:run again.',
            $repository->downstream,
            $this->source_branch,
            $prefix,
            $this->sha,
        ));
    }

    /**
     * The commit of $repository to tag and compare against: the split commit when one was found,
     * otherwise, for a package not being released or a dry run, the branch.
     */
    private function target(Repository $repository): string
    {
        return $this->splitCommits[(string) $repository->downstream] ?? $this->source_branch;
    }

    /**
     * Put the packages of the checkout being released into tag order, through composer constraints
     * and `#[PackageDependency]` alike. The new version is the one the changelog suggests, as that is
     * what release:prep wrote. Packages from other repositories, and those not released, keep their place.
     *
     * @param array<Repository> $repositories
     * @param array{path: string, upstream: string, monorepo: MonorepoCheckout, head: ?string, dependencies: ProviderDependencies} $checkout
     * @return array<Repository>
     */
    private function order(array $repositories, array $checkout): array
    {
        $local = array_filter(
            $repositories,
            static fn (Repository $repository): bool => (string) $repository->upstream === $checkout['upstream']
        );
        $spi = $checkout['dependencies']->byPrefix(array_values(array_map(
            static fn (Repository $repository): string => $repository->upstream->path,
            $local
        )));
        $packages = [];
        $byName = [];
        foreach ($local as $index => $repository) {
            $prefix = $repository->upstream->path;
            [$name, $composer] = $this->split_composer($checkout['path'], $repository);
            $latest = $repository->latestRelease?->version;
            $packages[] = new PackageRelease(
                $name,
                $this->suggest_version($this->changelogs[(string) $repository->downstream] ?? null, $latest),
                $latest,
                $this->constraints($composer['require'] ?? null),
                $this->constraints($composer['conflict'] ?? null),
                $spi[$prefix] ?? [],
            );
            $byName[$name] = $index;
        }
        $report = (new ReleaseOrderChecker())->check($packages);
        $this->print_order($report);

        //the slots the ordered packages occupy, refilled in tag order
        $slots = [];
        foreach ($report->order as $name) {
            $slots[] = $byName[$name];
        }
        sort($slots);
        $ordered = $repositories;
        foreach ($report->order as $i => $name) {
            $ordered[$slots[$i]] = $repositories[$byName[$name]];
        }

        return array_values($ordered);
    }

    /**
     * Compare downstream diffs to upstream changes found by date.
     * safety check that the changes found in upstream match diffs (which should be more accurate, but can't be used as the
     * primary method of matching because most commit detains are changed during git-split
     * We can only compare based on commit message vs PR message
     *
     * @return array<string> messages of the commits downstream but not upstream, having listed them
     */
    private function unexpected_commits(Repository $repository): array
    {
        if ($repository->latestRelease === null) {
            //initial release, nothing to do
            return [];
        }
        if ($repository->diff->commits === [] && $repository->commits === []) {
            //nothing to do
            return [];
        }
        $diffCommits = $foundCommits = [];
        foreach ($repository->diff->commits as $commit) {
            $diffCommits[] = $commit->message;
        }
        foreach ($repository->commits as $commit) {
            $foundCommits[] = $commit->message;
        }
        $this->output->isDebug() && $this->output->writeln('[COMPARE] ' . json_encode($diffCommits) . ' with ' . json_encode($foundCommits));

        $differences = array_diff($diffCommits, $foundCommits);
        if (count($differences) !== 0) {
            $this->output->writeln('<comment>Warning: The following commits are present downstream but not found upstream:</comment>');
            foreach ($differences as $diff) {
                $this->output->writeln('<comment>  • ' . $diff . '</comment>');
            }
            $this->output->writeln('<comment>Please review these differences before continuing.</comment>');
        }

        return array_values($differences);
    }

    private function confirm(string $question, bool $default): bool
    {
        return (new QuestionHelper())->ask($this->input, $this->output, new ConfirmationQuestion($question, $default));
    }

    /**
     * @return ?Diff null when the run should stop, as the split could not be checked
     */
    private function get_diffs(Repository $repository): ?Diff
    {
        $diff = new Diff();
        if (!$repository->latestRelease) {
            //nothing to compare against
            return $diff;
        }
        $target = $this->target($repository);

        try {
            $commits = $this->github->compare((string) $repository->downstream, $repository->latestRelease->version, $target);
        } catch (GitHubException $e) {
            $message = "Failed to compare {$repository->downstream} {$repository->latestRelease->version} with {$target}: {$e->getMessage()}";

            return $this->problem($message) ? null : $diff;
        }

        foreach ($commits as $c) {
            $commit = new Commit();
            $commit->message = $c['message'];
            $diff->commits[] = $commit;
        }

        return $diff;
    }

    /**
     * @param array<Repository> $repositories
     * @return bool false when the run stopped early
     */
    private function publish_repositories(array $repositories): bool
    {
        foreach ($repositories as $repo) {
            if (count($repo->commits) === 0) {
                $this->output->isVerbose() && $this->output->writeln("<info>[SKIP] {$repo->downstream} (no new commits)</info>");

                continue;
            }
            if (!$this->handle_unreleased($repo)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return bool false when the run should stop
     * @phan-suppress PhanUndeclaredMethod
     * @psalm-suppress UndefinedInterfaceMethod
     */
    private function handle_unreleased(Repository $repository): bool
    {
        $release = new Release();
        $cnt = count($repository->commits);
        $this->output->writeln("<info>[{$repository->downstream}]</info> {$cnt} unreleased change(s):");
        foreach ($repository->commits as $commit) {
            $this->output->writeln("<comment>* {$commit->subject} ({$commit->author})</comment>");
        }

        $prev = ($repository->latestRelease === null)
            ? '-nothing-'
            : $repository->latestRelease->version;
        $changelog = $this->changelogs[(string) $repository->downstream] ?? null;
        $suggested = $this->suggest_version($changelog, $repository->latestRelease?->version);
        if (!$this->input->isInteractive()) {
            //unattended, the changelog is the only say in the version: release:prep dated it, and a
            //release pull request that left a package out did so on purpose
            if ($suggested === null) {
                $this->output->writeln("<info>[SKIP] {$repository->downstream} has unreleased changes but no CHANGELOG.md section newer than {$prev}</info>");
                $this->skip($repository, "no CHANGELOG.md section newer than {$prev}");

                return true;
            }
            $newVersion = $suggested;
            $makeLatest = true;
            $isDraft = false;
        } else {
            $prompt = $suggested === null
                ? "Latest={$prev}, enter new tag (blank to skip)"
                : "Latest={$prev}, enter new tag (blank for {$suggested}, `skip` to skip)";
            $newVersion = (new QuestionHelper())->ask($this->input, $this->output, new Question("<question>{$prompt}:</question>", $suggested));
            if (!$newVersion || $newVersion === 'skip') {
                $this->output->writeln("<info>[SKIP] not going to release {$repository->downstream}</info>");
                $this->skip($repository, 'skipped at the prompt');

                return true;
            }
            $makeLatest = $this->confirm('<question>Make this the latest release (Y/n)?</question>', true);
            $isDraft = $this->confirm('<question>Make this release a draft (y/N)?</question>', false);
        }
        $release->version = $newVersion;
        $fromChangelog = $changelog?->notes($newVersion);
        if ($fromChangelog === null && $this->problem("{$repository->downstream} CHANGELOG.md has no section for {$newVersion}")) {
            return false;
        }
        $release->notes = (new ReleaseNotes())->build(
            (string) $repository->downstream,
            $repository->latestRelease?->version,
            $newVersion,
            $fromChangelog,
        );

        return $this->do_release($repository, $release, $makeLatest, $isDraft);
    }

    /**
     * The package's CHANGELOG.md in the checkout, which is at the commit being released, or null
     * when it has none.
     *
     * @throws ChangelogException when it is malformed
     */
    private function read_changelog(Repository $repository): ?ChangelogReleases
    {
        $file = Path::contained($this->checkoutPath, $repository->upstream->path, self::CHANGELOG_FILE);
        if ($file === null || !is_file($file)) {
            return null;
        }

        return new ChangelogReleases((string) file_get_contents($file));
    }

    /**
     * The newest dated version in the changelog, when it is newer than the latest release: that
     * is what release:prep wrote, so it is the version this release is for.
     */
    private function suggest_version(?ChangelogReleases $changelog, ?string $latest): ?string
    {
        $version = $changelog?->latest();
        if ($version === null) {
            return null;
        }
        if ($latest === null) {
            return $version;
        }

        try {
            return Comparator::greaterThan($version, $latest) ? $version : null;
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    /**
     * @return bool false when the release failed. Later packages may need this one, so the run
     *              stops there.
     */
    private function do_release(Repository $repository, Release $release, bool $makeLatest, bool $isDraft): bool
    {
        $url = "https://api.github.com/repos/{$repository->downstream}/releases";
        $body = [
            'tag_name' => $release->version,
            'target_commitish' => $this->target($repository),
            'name' => "Release {$release->version}",
            'body' => $release->notes,
            'draft' => $isDraft,
            'prerelease' => false,
            'generate_release_notes' => false,
            'make_latest' => $makeLatest ? 'true' : 'false',
        ];
        $made = [
            'repository' => (string) $repository->downstream,
            'version' => $release->version,
            'target' => $this->target($repository),
            'latest' => $makeLatest,
            'draft' => $isDraft,
            'url' => null,
        ];
        if ($this->dry_run) {
            $this->output->writeln("[DRY-RUN] {$url}");
            $this->releases[] = $made;

            return true;
        }

        try {
            $made['url'] = $this->github->createRelease((string) $repository->downstream, $body);
        } catch (GitHubException $e) {
            $this->output->writeln("<error>[ERROR] {$repository->downstream} {$release->version}: {$e->getMessage()}</error>");

            return false;
        }
        $this->released[] = [$repository, $release->version];
        $this->releases[] = $made;
        $this->output->writeln("<info>[CREATED] {$repository->downstream} {$release->version}: </info> {$made['url']}");

        return true;
    }

    /**
     * Name each released package whose branch alias in the checkout does not cover the version
     * tagged. After a prepared release there are none, as release:prep moved them. A version typed
     * at the prompt may differ from the prepared one.
     */
    private function remind_about_branch_aliases(): void
    {
        $branchKey = "dev-{$this->source_branch}";
        $stale = [];
        foreach ($this->released as [$repository, $version]) {
            [, $composer] = $this->split_composer($this->checkoutPath, $repository);
            $alias = $composer['extra']['branch-alias'][$branchKey] ?? null;
            if (is_string($alias) && !BranchAliasUpdater::covers($alias, $version)) {
                $stale[] = "{$repository->downstream->project} {$version} ({$branchKey}: {$alias})";
            }
        }
        if ($stale === []) {
            return;
        }
        $this->output->writeln('<comment>Branch aliases do not cover the versions tagged: ' . implode(', ', $stale) . '</comment>');
        $this->output->writeln('<comment>Run `vendor/bin/otel update:branch-alias` from the monorepo checkout, and open a pull request with the changes.</comment>');
    }
}
