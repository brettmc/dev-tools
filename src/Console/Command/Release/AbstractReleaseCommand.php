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
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Parser;

abstract class AbstractReleaseCommand extends BaseCommand
{
    protected const AVAILABLE_REPOS = [
        'core'    => 'open-telemetry/opentelemetry-php',
        'contrib' => 'open-telemetry/opentelemetry-php-contrib',
    ];
    protected ClientInterface $client;
    protected Parser $parser;
    protected ?string $token = null;
    protected array $sources = [];

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

    protected function get_downstream_unreleased_commits(Repository $repository): array
    {
        $commits_url = "https://api.github.com/repos/{$repository->downstream}/commits";
        if ($repository->latestRelease !== null) {
            $commits_url .= '?since=' . $this->since($repository->latestRelease);
        }

        return $this->get_commits($commits_url, $repository);
    }

    /**
     * Github's `since` filter is inclusive, so advance one second past the tag to avoid listing the
     * already-released commit. This happens with lightweight tags, where the tag's timestamp is the
     * timestamp of the commit it points at.
     */
    private function since(Release $release): string
    {
        return (new \DateTimeImmutable($release->timestamp))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->modify('+1 second')
            ->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param Repository $repository
     * @return array<Commit>
     */
    protected function get_upstream_unreleased_commits(Repository $repository): array
    {
        $commits_url = "https://api.github.com/repos/{$repository->upstream}/commits?path={$repository->upstream->path}";
        if ($repository->latestRelease !== null) {
            $commits_url .= '&since=' . $this->since($repository->latestRelease);
        }

        return $this->get_commits($commits_url, $repository);
    }
    private function get_commits(string $url, Repository $repository): array
    {
        $response = $this->fetch($url);
        $data = json_decode($response->getBody()->getContents());
        $commits = [];
        foreach ($data as $row) {
            $commit = new Commit();
            $commit->sha = $row->sha;
            $commit->message = $row->commit->message;
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
     * @throws \Exception
     * @return array<Repository>
     */
    protected function find_repositories(?string $filter = null): array
    {
        $repositories = [];
        foreach ($this->sources as $key => $repo) {
            $this->output->isVerbose() && $this->output->writeln("<info>Fetching .gitsplit.yaml for {$key} ({$repo})</info>");
            $repositories = array_merge($repositories, $this->get_gitsplit_repositories($repo, $filter));
        }

        return $repositories;
    }

    private function get_gitsplit_repositories(string $repo, ?string $filter): array
    {
        $url = "https://raw.githubusercontent.com/{$repo}/main/.gitsplit.yml";
        $response = $this->fetch($url);
        if ($response->getStatusCode() !== 200) {
            throw new \Exception("Error fetching {$url}");
        }

        $yaml = $this->parser->parse($response->getBody()->getContents());
        $repositories = [];
        $this->output->isVeryVerbose() && $this->output->writeln('[RESPONSE]' . json_encode($yaml['splits']));
        foreach ($yaml['splits'] as $entry) {
            $prefix = $entry['prefix'];
            if ($filter && !stripos($prefix, $filter) !== false) {
                $this->output->isVerbose() && $this->output->writeln(sprintf('[SKIP] %s does not match filter: %s', $prefix, $filter));

                continue;
            }
            $repository = new Repository();
            $repository->upstream = new Project($repo);
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
}
