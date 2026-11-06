<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Release;

use OpenTelemetry\DevTools\Console\Command\BaseCommand;
use OpenTelemetry\DevTools\Console\Release\Commit;
use OpenTelemetry\DevTools\Console\Release\GitHub;
use OpenTelemetry\DevTools\Console\Release\Project;
use OpenTelemetry\DevTools\Console\Release\Release;
use OpenTelemetry\DevTools\Console\Release\Repository;
use OpenTelemetry\DevTools\Package\Composer\ConfigAttributes;
use OpenTelemetry\DevTools\Package\Composer\PackageAttributeResolver;
use OpenTelemetry\DevTools\Package\Release\ReleaseOrderReport;
use OpenTelemetry\DevTools\Util\Path;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Parser;

abstract class AbstractReleaseCommand extends BaseCommand
{
    protected const GITSPLIT_FILE = '.gitsplit.yml';
    protected const COMPOSER_FILE = 'composer.json';
    protected const AVAILABLE_REPOS = [
        'core'    => 'open-telemetry/opentelemetry-php',
        'contrib' => 'open-telemetry/opentelemetry-php-contrib',
    ];
    /** The root composer.json name of each monorepo, which is not named after its repository */
    protected const ROOT_PACKAGES = [
        'open-telemetry/opentelemetry-php' => 'open-telemetry/opentelemetry',
        'open-telemetry/opentelemetry-php-contrib' => 'open-telemetry/opentelemetry-php-contrib',
    ];
    protected GitHub $github;
    protected Parser $parser;
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
     * The name in the root composer.json of the checkout at $path, or '' when it has none.
     */
    protected function root_package_name(string $path): string
    {
        $json = json_decode((string) file_get_contents($path . '/' . self::COMPOSER_FILE), true);

        return is_array($json) && is_string($json['name'] ?? null) ? $json['name'] : '';
    }

    /**
     * Map the `splits` of a .gitsplit.yml onto repositories, where $upstream is the monorepo that
     * those splits are made from.
     *
     * @param array<array{prefix: string, target: string}> $splits
     * @return array<Repository>
     */
    protected function map_gitsplit_repositories(array $splits, string $upstream, ?string $filter): array
    {
        $repositories = [];
        foreach ($splits as $entry) {
            $prefix = $entry['prefix'];
            if ($filter && stripos($prefix, $filter) === false) {
                $this->output->isVerbose() && $this->output->writeln(sprintf('[SKIP] %s does not match filter: %s', $prefix, $filter));

                continue;
            }
            $repository = new Repository();
            $repository->upstream = new Project($upstream);
            $repository->upstream->path = $prefix;
            $target = $entry['target'];
            $repository->downstream = new Project(str_replace(['https://${GH_TOKEN}@github.com/', '.git'], ['',''], $target));
            $repositories[] = $repository;
        }

        if ($this->output->isVeryVerbose()) {
            $this->output->writeln('[FOUND]' . json_encode($repositories));
        }

        return $repositories;
    }

    /**
     * The packages of the monorepo checked out at $path, from its own .gitsplit.yml: the root
     * composer.json of a monorepo is not named after its repository (core is
     * `open-telemetry/opentelemetry`), so the presence of split config is what identifies it.
     *
     * @param ?string $upstream the repository the splits are made from, when known; otherwise the
     *        root package name stands in for it
     * @return array<Repository>|null null when $path is not a monorepo checkout
     */
    protected function find_split_packages(string $path, ?string $filter, ?string $upstream = null): ?array
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
        $name = $upstream ?? (string) PackageAttributeResolver::create($composerFile)->resolve(ConfigAttributes::NAME);

        return $this->map_gitsplit_repositories($config['splits'] ?? [], $name, $filter);
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

    /**
     * The checkout --path names, by default the working directory, made absolute; null, having
     * said so, when it is not a directory.
     */
    protected function checkout_path(?string $path): ?string
    {
        $directory = Path::directory($path);
        if ($directory === null) {
            $this->output->writeln("<error>--path {$path} is not a directory</error>");
        }

        return $directory;
    }
}
