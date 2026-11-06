<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Release;

use Composer\Semver\Comparator;
use Http\Discovery\Psr18ClientDiscovery;
use OpenTelemetry\DevTools\Console\Release\Commit;
use OpenTelemetry\DevTools\Console\Release\Diff;
use OpenTelemetry\DevTools\Console\Release\Release;
use OpenTelemetry\DevTools\Console\Release\ReleaseNotes;
use OpenTelemetry\DevTools\Console\Release\Repository;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogReleases;
use OpenTelemetry\DevTools\Package\Git\GitRunner;
use OpenTelemetry\DevTools\Package\Release\PackageRelease;
use OpenTelemetry\DevTools\Package\Release\ReleaseOrderChecker;
use OpenTelemetry\DevTools\Package\Spi\PackageDependencyReader;
use OpenTelemetry\DevTools\Package\Spi\ProviderDependencies;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Yaml\Parser;

class ReleaseCommand extends AbstractReleaseCommand
{
    private const CHANGELOG_FILE = 'CHANGELOG.md';
    private bool $force;
    /**
     * @var array<string> packages released by this run
     */
    private array $released = [];
    /** @var list<array{repository: string, version: string, target: string, latest: bool, draft: bool, url: ?string}> */
    private array $releases = [];
    /** @var list<array{repository: string, reason: string}> */
    private array $skipped = [];
    /** @var array<string, ?ChangelogReleases> CHANGELOG.md in the checkout, by downstream repository */
    private array $changelogs = [];
    private ?ClientInterface $clientOverride;
    private PackageDependencyReader $dependencyReader;
    private GitRunner $git;
    /** the monorepo commit being released */
    private ?string $sha = null;
    /** @var array<string, string> the split commit with the content of $sha, by downstream repository */
    private array $splitCommits = [];
    private string $checkoutPath;

    public function __construct(?ClientInterface $client = null, ?PackageDependencyReader $dependencyReader = null, ?GitRunner $git = null)
    {
        parent::__construct();
        //held aside rather than assigned to $this->client, so that discovery stays lazy: it throws
        //when no PSR-18 client is installed, which should not happen merely by listing the commands
        $this->clientOverride = $client;
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
            ->addOption('force', ['f'], InputOption::VALUE_NONE, 'force new releases even if no changes')
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
        $status = $this->release($input, $output->getErrorOutput());
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
        $this->force = $input->getOption('force');
        if ($this->force && !$input->isInteractive()) {
            $output->writeln('<error>--force releases packages with no changes, which needs someone to choose the versions; run it interactively</error>');

            return Command::FAILURE;
        }
        $source = $input->getOption('repo');
        $filter = $input->getOption('filter');
        $this->client = $this->clientOverride ?? Psr18ClientDiscovery::find();
        $this->parser = new Parser();
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
        $checkout = $this->read_checkout(rtrim($path, '/'), $source ? self::AVAILABLE_REPOS[$source] : null);
        if ($checkout === null) {
            return Command::FAILURE;
        }
        $this->checkoutPath = $checkout['path'];
        if (!$this->choose_sha($checkout, $input->getOption('sha'))) {
            return Command::FAILURE;
        }

        $repositories = [];
        $found = $this->find_split_packages($checkout['path'], $filter, $checkout['upstream']) ?? [];
        if (count($found) === 0) {
            $this->output->writeln('<error>No repositories found!</error>');

            return Command::FAILURE;
        }
        $bar = new ProgressBar($progress_section, count($found));
        $bar->start();
        foreach ($found as $repository) {
            $bar->setMessage($repository->downstream->project);
            $repository = $this->populate_release_details($repository);
            if (!$this->find_split_commit($repository)) {
                return Command::FAILURE;
            }
            $repository->diff = $this->get_diffs($repository);

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

    private function skip(Repository $repository, string $reason): void
    {
        $this->skipped[] = ['repository' => (string) $repository->downstream, 'reason' => $reason];
    }

    /**
     * For a given repository, populate:
     * 1. latest release
     * 2. unreleased commits to upstream path, since date of last release, up to the commit released
     * 3. its changelog in the checkout
     */
    private function populate_release_details(Repository $repository): Repository
    {
        $this->output->isVerbose() && $this->output->writeln("<info>Processing: {$repository->downstream}</info>");

        $repository->latestRelease = $this->get_latest_release($repository);
        $repository->commits = $this->get_upstream_unreleased_commits($repository, $this->sha);
        $this->changelogs[(string) $repository->downstream] = $this->read_changelog($repository);

        return $repository;
    }

    /**
     * The local checkout of $upstream, its HEAD, and the `#[PackageDependency]` attributes of its
     * providers. Null, having said why, when it cannot be read or is not fit to release from. The
     * head is null when git could not read it, which only a dry run carries on from.
     *
     * @param ?string $upstream the monorepo to release, or null for whichever the checkout is of
     * @return array{path: string, upstream: string, head: ?string, dependencies: ProviderDependencies}|null
     */
    private function read_checkout(string $path, ?string $upstream): ?array
    {
        if ($this->find_split_packages($path, null) === null) {
            return null;
        }
        $name = $this->root_package_name($path);
        if ($upstream === null) {
            $found = array_search($name, self::ROOT_PACKAGES, true);
            if ($found === false) {
                $this->output->writeln(sprintf(
                    '<error>%s is not a checkout of %s: its composer.json is named "%s"</error>',
                    $path,
                    implode(' or ', array_keys(self::ROOT_PACKAGES)),
                    $name,
                ));

                return null;
            }
            $upstream = $found;
        }
        if ($name !== self::ROOT_PACKAGES[$upstream]) {
            $this->output->writeln(sprintf(
                '<error>%s is not a checkout of %s: its composer.json is named "%s", not "%s"</error>',
                $path,
                $upstream,
                $name,
                self::ROOT_PACKAGES[$upstream],
            ));

            return null;
        }

        try {
            $head = $this->git->head($path);
            $changes = $this->git->changes($path);
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

        return ['path' => $path, 'upstream' => $upstream, 'head' => $head, 'dependencies' => $dependencies];
    }

    /**
     * Settle the monorepo commit to release: $sha, which must be on the branch, or else the tip of
     * the branch as the run starts. Everything after reads that commit rather than the branch, so a
     * merge during the run is neither tagged nor a reason to stop.
     *
     * @param array{path: string, upstream: string, head: ?string, dependencies: ProviderDependencies} $checkout
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

        //behind or identical: every commit of $sha is on the branch
        $response = $this->fetch("https://api.github.com/repos/{$checkout['upstream']}/compare/{$this->source_branch}...{$sha}");
        $status = $response->getStatusCode() === 200 ? json_decode($response->getBody()->getContents())->status ?? null : null;

        return in_array($status, ['behind', 'identical'], true) || !$this->problem(sprintf(
            '%s is not on %s %s (%s). Release a commit that has been merged.',
            $sha,
            $checkout['upstream'],
            $this->source_branch,
            $status ?? "HTTP {$response->getStatusCode()}",
        ));
    }

    /**
     * Find the split commit with the same content as the package in the commit being released,
     * which is the one to tag. gitsplit rewrites every commit, so the trees are what match.
     *
     * The split's history is walked newest first from its latest release, which bounds the walk,
     * as does the cap of one page of 100 commits.
     *
     * @return bool false when the run should stop, having said why
     */
    private function find_split_commit(Repository $repository): bool
    {
        if ($repository->commits === [] && !$this->force) {
            //nothing to release, and its tree is that of its latest release, which the walk excludes
            return true;
        }
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

        $url = "https://api.github.com/repos/{$repository->downstream}/commits?sha={$this->source_branch}&per_page=100";
        if ($repository->latestRelease !== null) {
            $url .= '&since=' . $this->since($repository->latestRelease);
        }
        $response = $this->fetch($url);
        if ($response->getStatusCode() !== 200) {
            return !$this->problem("Could not list the commits of {$repository->downstream} {$this->source_branch} ({$response->getStatusCode()})");
        }
        foreach (json_decode($response->getBody()->getContents()) as $commit) {
            if ($commit->commit->tree->sha === $tree) {
                $this->splitCommits[(string) $repository->downstream] = $commit->sha;

                return true;
            }
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
     * Put the packages of the checkout that need each other's new versions into tag order, through
     * composer constraints and `#[PackageDependency]` alike. The new version is the one the changelog
     * suggests, as that is what release:prep wrote. Other packages keep their place.
     *
     * @param array<Repository> $repositories
     * @param array{path: string, upstream: string, head: ?string, dependencies: ProviderDependencies} $checkout
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
            $file = $this->contained_file($checkout['path'], $prefix, self::COMPOSER_FILE);
            $json = $file !== null && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $composer = is_array($json) ? $json : [];
            $name = is_string($composer['name'] ?? null) ? $composer['name'] : "open-telemetry/{$repository->downstream->project}";
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

    private function get_diffs(Repository $repository): Diff
    {
        $diff = new Diff();
        if (!$repository->latestRelease) {
            //nothing to compare against
            return $diff;
        }
        $target = $this->target($repository);
        $url = "https://api.github.com/repos/{$repository->downstream}/compare/{$repository->latestRelease->version}...{$target}";
        $response = $this->fetch($url);
        if ($response->getStatusCode() !== 200) {
            $this->output->writeln("<error>Failed to compare latest release with {$target}</error>");

            return $diff;
        }
        $data = json_decode($response->getBody()->getContents());

        foreach ($data->commits as $c) {
            $commit = new Commit();
            $commit->sha = $c->sha;
            $commit->message = $c->commit->message;
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
            if (count($repo->commits) === 0 && !$this->force) {
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
            $this->output->writeln("<comment>* {$commit->pullRequest->title} ({$commit->pullRequest->author})</comment>");
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
            //a release off another branch, such as a backport, must not take the badge from main's
            $makeLatest = $this->source_branch === 'main';
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
        if ($changelog !== null && $fromChangelog === null) {
            $this->output->writeln("<comment>[{$repository->downstream}] CHANGELOG.md has no section for {$newVersion}, listing pull requests instead</comment>");
        }
        $release->notes = (new ReleaseNotes())->build(
            (string) $repository->downstream,
            $repository->latestRelease?->version,
            $newVersion,
            $repository->commits,
            $fromChangelog,
        );

        if ($fromChangelog !== null && !$this->split_has_section($repository, $newVersion)) {
            return false;
        }

        return $this->do_release($repository, $release, $makeLatest, $isDraft);
    }

    /**
     * The package's CHANGELOG.md in the checkout, which is at the commit being released, or null
     * when it has none.
     */
    private function read_changelog(Repository $repository): ?ChangelogReleases
    {
        $file = $this->contained_file($this->checkoutPath, $repository->upstream->path, self::CHANGELOG_FILE);
        if ($file === null || !is_file($file)) {
            return null;
        }

        return new ChangelogReleases((string) file_get_contents($file));
    }

    /**
     * Whether the commit to be tagged has the section for $version in its CHANGELOG.md. Read through
     * the contents API rather than raw.githubusercontent.com, which caches for minutes.
     */
    private function split_has_section(Repository $repository, string $version): bool
    {
        $url = "https://api.github.com/repos/{$repository->downstream}/contents/CHANGELOG.md?ref={$this->target($repository)}";
        $response = $this->fetch($url);
        $section = null;
        if ($response->getStatusCode() === 200) {
            $data = json_decode($response->getBody()->getContents());
            $markdown = base64_decode((string) ($data->content ?? ''), true);
            $section = $markdown === false ? null : (new ChangelogReleases($markdown))->notes($version);
        }
        if ($section !== null) {
            return true;
        }

        return !$this->problem(sprintf(
            '%s %s has no %s section in CHANGELOG.md yet. gitsplit may not have pushed the merge; wait for it and run release:run again.',
            $repository->downstream,
            $this->source_branch,
            $version,
        ));
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
        $body = json_encode([
            'tag_name' => $release->version,
            'target_commitish' => $this->target($repository),
            'name' => "Release {$release->version}",
            'body' => $release->notes,
            'draft' => $isDraft,
            'prerelease' => false,
            'generate_release_notes' => false,
            'make_latest' => $makeLatest ? 'true' : 'false',
        ]);
        $this->output->isDebug() && $this->output->writeln($body);
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
        $response = $this->post($url, $body);
        if ($response->getStatusCode() !== 201) {
            $this->output->writeln("<error>[ERROR] {$repository->downstream} {$release->version} ({$response->getStatusCode()}) {$response->getBody()->getContents()}</error>");

            return false;
        }
        $json = json_decode($response->getBody()->getContents());
        $this->released[] = $repository->downstream->project;
        $made['url'] = $json->html_url;
        $this->releases[] = $made;
        $this->output->writeln("<info>[CREATED] {$repository->downstream} {$release->version}: </info> {$json->html_url}");

        return true;
    }

    /**
     * A release makes the branch alias of that package stale: composer ignores `extra.branch-alias`
     * in a tag, so the alias is updated after tagging rather than before it.
     */
    private function remind_about_branch_aliases(): void
    {
        if ($this->released === []) {
            return;
        }
        $packages = implode(', ', $this->released);
        $this->output->writeln("<comment>Branch aliases may now be out of date for: {$packages}</comment>");
        $this->output->writeln('<comment>Run `vendor/bin/otel update:branch-alias` from the monorepo checkout, and open a pull request with the changes.</comment>');
    }
}
