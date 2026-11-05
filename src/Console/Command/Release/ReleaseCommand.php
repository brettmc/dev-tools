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
    private bool $force;
    /**
     * @var array<string> packages released by this run
     */
    private array $released = [];
    /** @var array<string, ?ChangelogReleases> CHANGELOG.md on the branch being tagged, by downstream repository */
    private array $changelogs = [];
    private ?ClientInterface $clientOverride;
    private PackageDependencyReader $dependencyReader;
    private GitRunner $git;
    /** @var array<string, string> the tip of the branch being tagged when the run started, by upstream repository */
    private array $tips = [];

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
            ->addOption('repo', ['r'], InputOption::VALUE_OPTIONAL, 'repo to handle (core, contrib)')
            ->addOption('filter', null, InputOption::VALUE_OPTIONAL, 'filter by repository prefix')
            ->addOption('force', ['f'], InputOption::VALUE_NONE, 'force new releases even if no changes')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'checkout of the monorepo being released, at the commit being tagged (core, unless --repo contrib); required for core')
        ;
    }
    #[\Override]
    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        if (!$input->getOption('token')) {
            $token = getenv('GITHUB_TOKEN');
            if ($token !== false) {
                $input->setOption('token', $token);
            }
        }
        if (!$input->getOption('token')) {
            throw new \RuntimeException('No github token provided (via --token or GITHUB_TOKEN env)');
        }
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        assert($output instanceof ConsoleOutputInterface);
        $this->token = $input->getOption('token');
        $this->source_branch = $input->getOption('branch') ?? 'main';
        $this->dry_run = $input->getOption('dry-run');
        $this->force = $input->getOption('force');
        $source = $input->getOption('repo');
        $filter = $input->getOption('filter');
        $this->client = $this->clientOverride ?? Psr18ClientDiscovery::find();
        $this->parser = new Parser();
        $progress_section = $output->section();
        $main_section = $output->section();
        $this->registerInputAndOutput($input, $main_section);
        if ($source && !array_key_exists($source, self::AVAILABLE_REPOS)) {
            $options = implode(',', array_keys(self::AVAILABLE_REPOS));
            $this->output->writeln("<error>Invalid source: {$source}. Options: {$options}</error>");

            return Command::FAILURE;
        }
        $this->sources = $source ? [$source => self::AVAILABLE_REPOS[$source]] : self::AVAILABLE_REPOS;

        $path = $input->getOption('path');
        $checkout = null;
        if ($path === null && isset($this->sources['core'])) {
            //core's providers carry #[PackageDependency] on packages released in the same run, and
            //the tag order those need can only be read from a checkout
            $this->output->writeln('<error>Releasing core needs --path, a checkout of it at the commit being tagged, with `composer install` run</error>');

            return Command::FAILURE;
        }
        if ($path !== null) {
            $checkout = $this->read_checkout(rtrim($path, '/'), self::AVAILABLE_REPOS[$source ?? 'core']);
            if ($checkout === null) {
                return Command::FAILURE;
            }
        }

        try {
            foreach ($this->sources as $upstream) {
                $this->tips[$upstream] = $this->branch_tip($upstream);
            }
        } catch (\Exception $e) {
            $this->output->writeln("<error>{$e->getMessage()}</error>");

            return Command::FAILURE;
        }
        if ($checkout !== null && $checkout['head'] !== null && $checkout['head'] !== $this->tips[$checkout['upstream']]) {
            $stop = $this->problem(sprintf(
                '%s is at %s, but %s %s is at %s. Check out and pull %s before releasing.',
                $checkout['path'],
                $checkout['head'],
                $checkout['upstream'],
                $this->source_branch,
                $this->tips[$checkout['upstream']],
                $this->source_branch,
            ));
            if ($stop) {
                return Command::FAILURE;
            }
        }

        $repositories = [];

        try {
            $found = $this->find_repositories($filter);
        } catch (\Exception $e) {
            $this->output->writeln("<error>{$e->getCode()} {$e->getMessage()}</error>");

            return Command::FAILURE;
        }
        if (count($found) === 0) {
            $this->output->writeln('<error>No repositories found!</error>');

            return Command::FAILURE;
        }
        $bar = new ProgressBar($progress_section, count($found));
        $bar->start();
        foreach ($found as $repository) {
            $bar->setMessage($repository->downstream->project);
            $repository = $this->populate_release_details($repository);

            if ($this->compare_diffs_to_unreleased($repository) === false) {
                $this->output->writeln("[SKIP] Skipping {$repository->downstream} due to differences");
            } else {
                $repositories[] = $repository;
            }
            $bar->advance();
        }
        $bar->finish();
        if ($checkout !== null) {
            $repositories = $this->order($repositories, $checkout);
        }
        $completed = $this->publish_repositories($repositories);
        $this->remind_about_branch_aliases();

        return $completed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * For a given repository, populate:
     * 1. latest release
     * 2. unreleased commits to upstream path, since date of last release
     * 3. diffs between latest tag in downstream and selected branch (eg main)
     */
    private function populate_release_details(Repository $repository): Repository
    {
        $this->output->isVerbose() && $this->output->writeln("<info>Processing: {$repository->downstream}</info>");

        $repository->latestRelease = $this->get_latest_release($repository);
        $repository->commits = $this->get_upstream_unreleased_commits($repository);
        $repository->diff = $this->get_diffs($repository);
        $this->changelogs[(string) $repository->downstream] = $this->fetch_changelog($repository);

        return $repository;
    }

    /**
     * The local checkout of $upstream, its HEAD, and the `#[PackageDependency]` attributes of its
     * providers. Null, having said why, when it cannot be read or is not fit to release from. The
     * head is null when git could not read it, which only a dry run carries on from.
     *
     * @return array{path: string, upstream: string, head: ?string, dependencies: ProviderDependencies}|null
     */
    private function read_checkout(string $path, string $upstream): ?array
    {
        if ($this->find_split_packages($path, null) === null) {
            return null;
        }
        $name = $this->root_package_name($path);
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
     * Whether the branch being tagged is still where the run started. A merge since then would be
     * tagged into the split repositories without having been checked or ordered.
     */
    private function branch_unmoved(Repository $repository): bool
    {
        $upstream = (string) $repository->upstream;
        $tip = $this->branch_tip($upstream);
        if ($tip === $this->tips[$upstream]) {
            return true;
        }

        return !$this->problem(sprintf(
            '%s %s moved from %s to %s during the release. Stopping before %s; run release:run again to release the rest.',
            $upstream,
            $this->source_branch,
            $this->tips[$upstream],
            $tip,
            $repository->downstream,
        ));
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
     */
    private function compare_diffs_to_unreleased(Repository $repository): bool
    {
        if ($repository->latestRelease === null) {
            //initial release, nothing to do
            return true;
        }
        if ($repository->diff->commits === [] && $repository->commits === []) {
            //nothing to do
            return true;
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

            $helper = new QuestionHelper();
            $question = new ConfirmationQuestion('<question>Do you want to continue despite these differences? (y/N):</question> ', false);

            return $helper->ask($this->input, $this->output, $question);
        }

        return true;
    }

    private function get_diffs(Repository $repository): Diff
    {
        $diff = new Diff();
        if (!$repository->latestRelease) {
            //nothing to compare against
            return $diff;
        }
        $url = "https://api.github.com/repos/{$repository->downstream}/compare/{$repository->latestRelease->version}...{$this->source_branch}";
        $response = $this->fetch($url);
        if ($response->getStatusCode() !== 200) {
            $this->output->writeln("<error>Failed to compare latest release with {$this->source_branch}</error>");

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
        $prompt = $suggested === null
            ? "Latest={$prev}, enter new tag (blank to skip)"
            : "Latest={$prev}, enter new tag (blank for {$suggested}, `skip` to skip)";
        $question = new Question("<question>{$prompt}:</question>", $suggested);

        $helper = new QuestionHelper();
        $newVersion = $helper->ask($this->input, $this->output, $question);
        if (!$newVersion || $newVersion === 'skip') {
            $this->output->writeln("<info>[SKIP] not going to release {$repository->downstream}</info>");

            return true;
        }
        $release->version = $newVersion;
        $question = new ConfirmationQuestion('<question>Make this the latest release (Y/n)?</question>', true);
        $makeLatest = $helper->ask($this->input, $this->output, $question);
        $question = new ConfirmationQuestion('<question>Make this release a draft (y/N)?</question>', false);
        $isDraft = $helper->ask($this->input, $this->output, $question);
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

        if (!$this->branch_unmoved($repository)) {
            return false;
        }
        if ($fromChangelog !== null && !$this->split_has_section($repository, $newVersion)) {
            return false;
        }
        $this->do_release($repository, $release, $makeLatest, $isDraft);

        return true;
    }

    /**
     * The package's CHANGELOG.md on the branch being tagged, or null when it has none. It is read
     * from upstream, as the split repositories are only updated after the monorepo merges.
     */
    private function fetch_changelog(Repository $repository): ?ChangelogReleases
    {
        $url = "https://raw.githubusercontent.com/{$repository->upstream}/{$this->source_branch}/{$repository->upstream->path}/CHANGELOG.md";
        $response = $this->fetch($url);
        if ($response->getStatusCode() === 404) {
            return null;
        }
        if ($response->getStatusCode() !== 200) {
            $this->output->writeln("<comment>[{$repository->downstream}] could not read CHANGELOG.md ({$response->getStatusCode()}), listing pull requests instead</comment>");

            return null;
        }

        return new ChangelogReleases($response->getBody()->getContents());
    }

    /**
     * Whether the split repository has caught up with the commit that dated $version in the
     * changelog. gitsplit pushes to the splits some time after a merge upstream, and the tag is
     * made on the split's branch, so tagging before then would tag the commit before it.
     *
     * Read through the contents API rather than raw.githubusercontent.com, which caches for
     * minutes and so could report the split as behind, or ahead, of where it is.
     */
    private function split_has_section(Repository $repository, string $version): bool
    {
        $url = "https://api.github.com/repos/{$repository->downstream}/contents/CHANGELOG.md?ref={$this->source_branch}";
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

    private function do_release(Repository $repository, Release $release, bool $makeLatest, bool $isDraft)
    {
        $url = "https://api.github.com/repos/{$repository->downstream}/releases";
        $body = json_encode([
            'tag_name' => $release->version,
            'target_commitish' => $this->source_branch,
            'name' => "Release {$release->version}",
            'body' => $release->notes,
            'draft' => $isDraft,
            'prerelease' => false,
            'generate_release_notes' => false,
            'make_latest' => $makeLatest ? 'true' : 'false',
        ]);
        $this->output->isDebug() && $this->output->writeln($body);
        if ($this->dry_run) {
            $this->output->writeln("[DRY-RUN] {$url}");

            return;
        }
        $response = $this->post($url, $body);
        if ($response->getStatusCode() !== 201) {
            $this->output->writeln("<error>[ERROR] ({$response->getStatusCode()}) {$response->getBody()->getContents()}</error>");
        } else {
            $json = json_decode($response->getBody()->getContents());
            $this->released[] = $repository->downstream->project;
            $this->output->writeln("<info>[CREATED] {$repository->downstream} {$release->version}: </info> {$json->html_url}");
        }
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
