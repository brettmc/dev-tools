<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Release;

use OpenTelemetry\DevTools\Console\Command\BaseCommand;
use OpenTelemetry\DevTools\Console\Release\Commit;
use OpenTelemetry\DevTools\Console\Release\GitHub;
use OpenTelemetry\DevTools\Console\Release\Project;
use OpenTelemetry\DevTools\Console\Release\Release;
use OpenTelemetry\DevTools\Console\Release\Repository;
use OpenTelemetry\DevTools\Package\GitSplit\MonorepoCheckout;
use OpenTelemetry\DevTools\Package\Release\ReleaseOrderReport;
use OpenTelemetry\DevTools\Util\Path;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractReleaseCommand extends BaseCommand
{
    protected const COMPOSER_FILE = 'composer.json';
    protected const AVAILABLE_REPOS = [
        'core'    => 'open-telemetry/opentelemetry-php',
        'contrib' => 'open-telemetry/opentelemetry-php-contrib',
    ];
    protected GitHub $github;
    protected ?string $token = null;
    protected bool $dry_run = false;
    protected string $source_branch = 'main';

    public function __construct(?GitHub $github = null)
    {
        parent::__construct();
        $this->github = $github ?? new GitHub();
    }

    /**
     * The token from --token, or else GITHUB_TOKEN. Read in execute(), as interact() is skipped
     * without interaction.
     */
    protected function token(InputInterface $input): ?string
    {
        $token = $input->getOption('token') ?: (getenv('GITHUB_TOKEN') ?: null);
        $this->github->authenticate($token);

        return $token;
    }

    #[\Override]
    protected function registerOutput(OutputInterface $output): void
    {
        parent::registerOutput($output);
        $this->github->trace($output);
    }

    protected function get_latest_release(Repository $repository): ?Release
    {
        $release = $this->github->latestRelease((string) $repository->downstream);
        if ($release === null) {
            $this->output->writeln("<info>[{$repository->downstream}] No latest release found</info>");

            return null;
        }
        $this->output->isVerbose() && $this->output->writeln("[INFO] Latest release of {$repository->downstream} is {$release}");

        return $release;
    }

    /**
     * Github's `since` filter is inclusive, as is `git log --since`, so advance one second past the
     * tag to avoid listing the already-released commit. This happens with lightweight tags, where
     * the tag's timestamp is the timestamp of the commit it points at.
     */
    protected function since(Release $release): string
    {
        return (new \DateTimeImmutable($release->timestamp))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->modify('+1 second')
            ->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param list<array{sha: string, author: string, message: string}> $log from GitRunner::log()
     * @return list<Commit>
     */
    protected function commits(array $log): array
    {
        $commits = [];
        foreach ($log as $entry) {
            $commit = new Commit();
            $commit->message = $entry['message'];
            $commit->subject = explode("\n", $entry['message'], 2)[0];
            $commit->author = $entry['author'];
            $commits[] = $commit;
        }

        return $commits;
    }

    /**
     * The tip of the branch being released from, on GitHub. A local remote could be a fork, or
     * named anything.
     */
    protected function branch_tip(string $upstream): string
    {
        $sha = $this->github->branchSha($upstream, $this->source_branch);
        $this->output->isVerbose() && $this->output->writeln("[INFO] {$upstream} {$this->source_branch} is at {$sha}");

        return $sha;
    }

    /**
     * Report something that makes the release unsafe. A dry run warns and carries on, so it can
     * still show what would be released.
     *
     * @return bool whether to stop
     */
    protected function problem(string $message): bool
    {
        if ($this->dry_run) {
            $this->output->writeln("<comment>[DRY-RUN] {$message}</comment>");

            return false;
        }
        $this->output->writeln("<error>{$message}</error>");

        return true;
    }

    /**
     * The monorepo checked out at $path, read from its own .gitsplit.yml. Null, having said why, when
     * it is not a monorepo checkout or its .gitsplit.yml is malformed.
     */
    protected function read_monorepo(string $path): ?MonorepoCheckout
    {
        try {
            return MonorepoCheckout::read($path);
        } catch (\RuntimeException $e) {
            $this->output->writeln('<error>' . OutputFormatter::escape($e->getMessage()) . '</error>');
            if (!MonorepoCheckout::isCheckout($path)) {
                $repos = implode(' or ', self::AVAILABLE_REPOS);
                $this->output->writeln("<comment>Run this command from a {$repos} checkout, or pass --path=</comment>");
            }

            return null;
        }
    }

    /**
     * Which of core and contrib the checkout is of, from its .gitsplit.yml `project_url`. Null, having
     * said so, when it is neither, or is not $expected. A fork's checkout names the upstream
     * repository too, so this is only a claim: the branch tip checks are what show it holds.
     */
    protected function upstream(string $path, MonorepoCheckout $monorepo, ?string $expected = null): ?string
    {
        $repositories = $expected === null ? array_values(self::AVAILABLE_REPOS) : [$expected];
        if ($monorepo->repository !== null && in_array($monorepo->repository, $repositories, true)) {
            return $monorepo->repository;
        }
        $this->output->writeln(sprintf(
            '<error>%s is not a checkout of %s: its .gitsplit.yml %s</error>',
            $path,
            implode(' or ', $repositories),
            $monorepo->repository === null ? 'has no project_url' : "project_url names {$monorepo->repository}",
        ));

        return null;
    }

    /**
     * The split packages of $monorepo as repositories, where $upstream is the monorepo that those
     * splits are made from.
     *
     * @return array<Repository>
     */
    protected function split_repositories(MonorepoCheckout $monorepo, string $upstream, ?string $filter): array
    {
        $repositories = [];
        foreach ($monorepo->splits as $prefix => $downstream) {
            if ($filter && stripos($prefix, $filter) === false) {
                $this->output->isVerbose() && $this->output->writeln(sprintf('[SKIP] %s does not match filter: %s', $prefix, $filter));

                continue;
            }
            $repository = new Repository();
            $repository->upstream = new Project($upstream);
            $repository->upstream->path = $prefix;
            $repository->downstream = new Project($downstream);
            $repositories[] = $repository;
        }

        if ($this->output->isVeryVerbose()) {
            $this->output->writeln('[FOUND]' . json_encode($repositories));
        }

        return $repositories;
    }

    /**
     * The package name and composer.json of a split in the checkout at $path. The name is not always
     * derivable from the name of the repository it is split to, so it falls back to that guess only
     * when there is no composer.json, or it has no name.
     *
     * @return array{0: string, 1: array}
     */
    protected function split_composer(string $path, Repository $repository): array
    {
        $file = Path::contained($path, $repository->upstream->path, self::COMPOSER_FILE);
        $json = $file !== null && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $composer = is_array($json) ? $json : [];
        $name = $composer['name'] ?? null;

        return [is_string($name) ? $name : "open-telemetry/{$repository->downstream->project}", $composer];
    }

    /**
     * Print the order to tag packages that need each other's new versions, and warn about constraints
     * the new versions break. Silent when there is nothing to say.
     */
    protected function print_order(ReleaseOrderReport $report): void
    {
        if ($report->order !== []) {
            $this->output->writeln('');
            $this->output->writeln('Tag in this order: ' . implode(', ', $report->order));
        }
        if ($report->warnings !== []) {
            $this->output->writeln('');
            $this->output->writeln('<comment>Check the constraints between packages:</comment>');
            foreach ($report->warnings as $warning) {
                $this->output->writeln("- {$warning}");
            }
        }
    }

    /**
     * @return array<string, string>
     */
    protected function constraints(mixed $section): array
    {
        return is_array($section) ? array_filter($section, 'is_string') : [];
    }
}
