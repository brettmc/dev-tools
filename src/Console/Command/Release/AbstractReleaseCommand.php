<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Release;

use Nyholm\Psr7\Request;
use OpenTelemetry\DevTools\Console\Command\BaseCommand;
use OpenTelemetry\DevTools\Console\Release\Commit;
use OpenTelemetry\DevTools\Console\Release\Project;
use OpenTelemetry\DevTools\Console\Release\PullRequest;
use OpenTelemetry\DevTools\Console\Release\Release;
use OpenTelemetry\DevTools\Console\Release\Repository;
use OpenTelemetry\DevTools\Package\Composer\ConfigAttributes;
use OpenTelemetry\DevTools\Package\Composer\PackageAttributeResolver;
use OpenTelemetry\DevTools\Package\Release\ReleaseOrderReport;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Input\InputInterface;
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
    protected ClientInterface $client;
    protected Parser $parser;
    protected ?string $token = null;
    protected bool $dry_run = false;
    protected string $source_branch = 'main';

    /**
     * The token from --token, or else GITHUB_TOKEN. Read in execute(), as interact() is skipped
     * without interaction.
     */
    protected function token(InputInterface $input): ?string
    {
        return $input->getOption('token') ?: (getenv('GITHUB_TOKEN') ?: null);
    }

    protected function headers(): array
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'php-' . PHP_VERSION,
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
        if ($this->token) {
            $headers['Authorization'] ="Bearer {$this->token}";
        }

        return $headers;
    }

    protected function fetch(string $url): ResponseInterface
    {
        $request = new Request('GET', $url, $this->headers());
        $this->output->isVeryVerbose() && $this->output->writeln("[HTTP] GET {$url}");

        return $this->client->sendRequest($request);
    }

    protected function post(string $url, string $body): ResponseInterface
    {
        $request = new Request('POST', $url, $this->headers(), $body);
        $this->output->isVerbose() && $this->output->writeln("[HTTP] POST {$url}");
        $this->output->isVeryVerbose() && $this->output->writeln("[HTTP body] {$body}");

        return $this->client->sendRequest($request);
    }

    protected function get_latest_release(Repository $repository): ?Release
    {
        $release_url = "https://api.github.com/repos/{$repository->downstream}/releases/latest";

        $response = $this->fetch($release_url);
        if ($response->getStatusCode() === 404) {
            $this->output->writeln("<info>[{$repository->downstream}] No latest release found</info>");

            return null;
        }
        if ($response->getStatusCode() !== 200) {
            $this->output->writeln("<error>({$response->getStatusCode()}) {$response->getBody()}</error>");

            throw new \Exception("Error retrieving latest release for {$repository->downstream}: " . $response->getReasonPhrase(), $response->getStatusCode());
        }

        $data = json_decode($response->getBody()->getContents());

        $release = new Release();
        //created_at is when the tag was created, which may be well before the release was published
        $release->timestamp = $data->created_at;
        $release->version = $data->tag_name;
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
    protected function commits(Repository $repository, array $log): array
    {
        $commits = [];
        foreach ($log as $entry) {
            $commit = new Commit();
            $commit->sha = $entry['sha'];
            $commit->message = $entry['message'];
            $commit->pullRequest = $this->get_pull_request($repository, $commit);
            $commits[] = $commit;
        }

        return $commits;
    }

    protected function get_pull_request(Repository $repository, Commit $commit): PullRequest
    {
        $prs_url = "https://api.github.com/repos/{$repository->upstream}/commits/{$commit->sha}/pulls";
        $response = $this->fetch($prs_url);
        if ($response->getStatusCode() === 404) {
            //repo settings should mean this is not possible
            throw new \RuntimeException("Pull request not found for commit SHA {$commit->sha}");
        }
        if ($response->getStatusCode() !== 200) {
            $this->output->isDebug() && $this->output->writeln($response->getBody()->getContents());

            throw new \Exception('Error retrieving pull request');
        }

        $json = json_decode($response->getBody()->getContents());
        if (count($json) === 0) {
            throw new \RuntimeException("Pull request not found for commit SHA {$commit->sha}");
        }
        if (count($json) > 1) {
            $this->output->writeln("[WARN] multiple PRs for commit {$commit->sha}, choosing first...");
        }
        $row = $json[0];
        $pr = new PullRequest();
        $pr->author = $row->user->login;
        $pr->url = $row->html_url;
        $pr->id = $row->number;
        $pr->title = $row->title;

        return $pr;
    }

    protected function get_sha_for_branch(Repository $repository, string $branch): string
    {
        $refs_url = "https://api.github.com/repos/{$repository->upstream}/git/matching-refs/heads/{$branch}";
        $response = $this->fetch($refs_url);
        if ($response->getStatusCode() !== 200) {
            $this->output->isDebug() && $this->output->writeln($response->getBody()->getContents());

            throw new \Exception("Error {$response->getStatusCode()} retrieving branch refs for {$branch}");
        }
        $json = json_decode($response->getBody()->getContents());
        if (count($json) === 0) {
            throw new \RuntimeException("No matching refs found for branch: {$branch}");
        }
        $ref = $json[0];

        $this->output->isVerbose() && $this->output->writeln("Found ref: {$ref->ref} SHA: {$ref->object->sha}");

        return $ref->object->sha;
    }

    /**
     * The tip of the branch being released from, on GitHub. A local remote could be a fork, or
     * named anything.
     */
    protected function branch_tip(string $upstream): string
    {
        $repository = new Repository();
        $repository->upstream = new Project($upstream);

        return $this->get_sha_for_branch($repository, $this->source_branch);
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
     * The file $name in the split package at $prefix, or null when it lies outside $root.
     *
     * The prefixes are read from the checkout's own .gitsplit.yml, which is committed content: a
     * prefix of `../other-project`, or one that is a symlink pointing out of the tree, would
     * otherwise let this command rewrite a file that the caller never selected with --path.
     */
    protected function contained_file(string $root, string $prefix, string $name): ?string
    {
        $target = "{$root}/{$prefix}/{$name}";

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
    protected static function normalize_path(string $path): string
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
}
