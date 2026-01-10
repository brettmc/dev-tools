<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Release;

use Http\Discovery\Psr18ClientDiscovery;
use OpenTelemetry\DevTools\Console\Release\Commit;
use OpenTelemetry\DevTools\Console\Release\Diff;
use OpenTelemetry\DevTools\Console\Release\Release;
use OpenTelemetry\DevTools\Console\Release\Repository;
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
    private string $source_branch;
    private bool $dry_run;
    private bool $force;

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
        if ($source && !array_key_exists($source, self::AVAILABLE_REPOS)) {
            $options = implode(',', array_keys(self::AVAILABLE_REPOS));
            $this->output->writeln("<error>Invalid source: {$source}. Options: {$options}</error>");
        }
        $this->sources = $source ? [$source => self::AVAILABLE_REPOS[$source]] : self::AVAILABLE_REPOS;
        $this->client = Psr18ClientDiscovery::find();
        $this->parser = new Parser();
        $progress_section = $output->section();
        $main_section = $output->section();
        $this->registerInputAndOutput($input, $main_section);

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
        $this->publish_repositories($repositories);

        return Command::SUCCESS;
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

        return $repository;
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
     * @return void
     */
    private function publish_repositories(array $repositories): void
    {
        foreach ($repositories as $repo) {
            if (count($repo->commits) === 0 && !$this->force) {
                $this->output->isVerbose() && $this->output->writeln("<info>[SKIP] {$repo->downstream} (no new commits)</info>");

                continue;
            }
            $this->handle_unreleased($repo);
        }
    }

    /**
     * @phan-suppress PhanUndeclaredMethod
     * @psalm-suppress UndefinedInterfaceMethod
     */
    private function handle_unreleased(Repository $repository): void
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
        $question = new Question("<question>Latest={$prev}, enter new tag (blank to skip):</question>", null);

        $helper = new QuestionHelper();
        $newVersion = $helper->ask($this->input, $this->output, $question);
        if (!$newVersion) {
            $this->output->writeln("<info>[SKIP] not going to release {$repository->downstream}</info>");

            return;
        }
        $release->version = $newVersion;
        $question = new ConfirmationQuestion('<question>Make this the latest release (Y/n)?</question>', true);
        $makeLatest = $helper->ask($this->input, $this->output, $question);
        $question = new ConfirmationQuestion('<question>Make this release a draft (y/N)?</question>', false);
        $isDraft = $helper->ask($this->input, $this->output, $question);
        $notes = [];
        if ($repository->latestRelease === null) {
            $notes[] = 'Initial release';
        } else {
            $notes[] = "What's Changed:";
            foreach ($repository->commits as $commit) {
                $notes[] = "* {$commit->pullRequest->title} by @{$commit->pullRequest->author} in [{$commit->pullRequest->id}]({$commit->pullRequest->url})";
            }
            $notes[] = '';
            $notes[] = "**Full Changelog**: https://github.com/{$repository->downstream}/compare/{$repository->latestRelease->version}...{$release->version}";
        }
        $release->notes = implode(PHP_EOL, $notes);

        $this->do_release($repository, $release, $makeLatest, $isDraft);
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
            $this->output->writeln("<info>[CREATED] {$repository->downstream} {$release->version}: </info> {$json->html_url}");
        }
    }
}
