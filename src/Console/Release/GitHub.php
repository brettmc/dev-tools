<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Release;

use Http\Discovery\Psr18ClientDiscovery;
use Nyholm\Psr7\Request;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The GitHub REST API, as the release commands use it. Every request either answers with what was
 * asked for, or throws a GitHubException naming the request, so that a rate limit or a bad token
 * cannot pass for an empty answer.
 */
class GitHub
{
    private const API = 'https://api.github.com/repos/';
    private const PER_PAGE = 100;

    private ?ClientInterface $client;
    private ?string $token = null;
    /** @var array<string, true> the labels known to exist, by `repository#label` */
    private array $labels = [];
    private OutputInterface $output;

    /**
     * Discovery waits for the first request: it throws when no PSR-18 client is installed, which
     * should not happen merely by listing the commands.
     */
    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client;
        $this->output = new NullOutput();
    }

    public function authenticate(?string $token): void
    {
        $this->token = $token;
    }

    public function trace(OutputInterface $output): void
    {
        $this->output = $output;
    }

    /**
     * The latest release of $repository, or null when it has none. A repository that cannot be seen
     * at all fails, rather than passing for one with no release.
     */
    public function latestRelease(string $repository): ?Release
    {
        $url = self::API . "{$repository}/releases/latest";
        $response = $this->send('GET', $url);
        if ($response->getStatusCode() === 404) {
            //GitHub answers 404 both for no release and for no such repository
            $this->expect('GET', self::API . $repository, 200);

            return null;
        }
        $data = $this->decode('GET', $url, $response, 200);

        $release = new Release();
        $release->version = self::string($data, 'tag_name', $url);
        //created_at is when the tag was created, which may be well before the release was published
        $release->timestamp = self::string($data, 'created_at', $url);

        return $release;
    }

    /**
     * The commit at the tip of $branch. The ref must match exactly: git/matching-refs would answer
     * for 1.x with 1.x-old.
     */
    public function branchSha(string $repository, string $branch): string
    {
        $url = self::API . "{$repository}/git/ref/heads/{$branch}";

        return self::string(self::object($this->expect('GET', $url, 200), 'object', $url), 'sha', $url);
    }

    /**
     * The commits of $branch, newest first, fetched a page at a time as they are read.
     *
     * @return \Generator<int, array{sha: string, message: string, tree: string}>
     */
    public function commits(string $repository, string $branch, ?string $since): \Generator
    {
        $url = self::API . "{$repository}/commits?sha={$branch}&per_page=" . self::PER_PAGE;
        if ($since !== null) {
            $url .= "&since={$since}";
        }
        while ($url !== null) {
            $response = $this->send('GET', $url);
            foreach (self::list($this->decode('GET', $url, $response, 200), $url) as $commit) {
                yield self::commit($commit, $url);
            }
            $url = self::next($response);
        }
    }

    /**
     * The commits on $head since $base, oldest first.
     *
     * @return list<array{sha: string, message: string, tree: string}>
     */
    public function compare(string $repository, string $base, string $head): array
    {
        $url = self::API . "{$repository}/compare/{$base}...{$head}?per_page=" . self::PER_PAGE;
        $commits = [];
        while ($url !== null) {
            $response = $this->send('GET', $url);
            foreach (self::list(self::object($this->decode('GET', $url, $response, 200), 'commits', $url), $url) as $commit) {
                $commits[] = self::commit($commit, $url);
            }
            $url = self::next($response);
        }

        return $commits;
    }

    /**
     * @param array<string, mixed> $release the fields of the new release
     * @return string the URL of the release on github.com
     */
    public function createRelease(string $repository, array $release): string
    {
        $url = self::API . "{$repository}/releases";

        return self::string($this->expect('POST', $url, 201, $release), 'html_url', $url);
    }

    /**
     * Create an annotated tag object. It is not a tag in the repository until createRef() points
     * a ref at it.
     *
     * @return array{sha: string, url: string}
     */
    public function createTag(string $repository, string $tag, string $message, string $sha): array
    {
        $url = self::API . "{$repository}/git/tags";
        $data = $this->expect('POST', $url, 201, [
            'tag' => $tag,
            'message' => $message,
            'object' => $sha,
            'type' => 'commit',
        ]);

        return ['sha' => self::string($data, 'sha', $url), 'url' => self::string($data, 'url', $url)];
    }

    /**
     * @return string the API URL of the new ref
     */
    public function createRef(string $repository, string $ref, string $sha): string
    {
        $url = self::API . "{$repository}/git/refs";

        return self::string($this->expect('POST', $url, 201, ['ref' => $ref, 'sha' => $sha]), 'url', $url);
    }

    /**
     * The titles of the issues of $repository, open and closed, that have $label.
     *
     * @return list<string>
     */
    public function issueTitles(string $repository, string $label): array
    {
        $url = self::API . "{$repository}/issues?state=all&labels=" . rawurlencode($label) . '&per_page=' . self::PER_PAGE;
        $titles = [];
        while ($url !== null) {
            $response = $this->send('GET', $url);
            foreach (self::list($this->decode('GET', $url, $response, 200), $url) as $issue) {
                //the issues API lists the pull requests too
                if (is_array($issue) && !isset($issue['pull_request']) && is_string($issue['title'] ?? null)) {
                    $titles[] = $issue['title'];
                }
            }
            $url = self::next($response);
        }

        return $titles;
    }

    /**
     * Open an issue, after creating the labels that do not exist yet.
     *
     * @param list<string> $labels
     * @return string the URL of the issue on github.com
     */
    public function createIssue(string $repository, string $title, string $body, array $labels = []): string
    {
        foreach ($labels as $label) {
            $this->createLabel($repository, $label);
        }
        $url = self::API . "{$repository}/issues";
        $issue = $this->expect('POST', $url, 201, ['title' => $title, 'body' => $body, 'labels' => $labels]);

        //the issue exists by now, so a response without its URL is not worth reporting as a failure
        return is_array($issue) && is_string($issue['html_url'] ?? null) ? $issue['html_url'] : "https://github.com/{$repository}/issues";
    }

    /**
     * The open pull requests from $branch of $repository itself, opened by $author. The list
     * endpoint cannot filter by author, so the others are dropped here.
     *
     * @return list<array{number: int, body: string, head: string}> head is the sha of the head commit
     */
    public function openPullRequests(string $repository, string $branch, string $author): array
    {
        $owner = explode('/', $repository, 2)[0];
        $url = self::API . "{$repository}/pulls?state=open&head=" . rawurlencode("{$owner}:{$branch}") . '&per_page=' . self::PER_PAGE;
        $pulls = [];
        while ($url !== null) {
            $response = $this->send('GET', $url);
            foreach (self::list($this->decode('GET', $url, $response, 200), $url) as $pull) {
                if (self::string(self::object($pull, 'user', $url), 'login', $url) !== $author) {
                    continue;
                }
                $pulls[] = [
                    'number' => self::int($pull, 'number', $url),
                    'body' => self::body($pull, $url),
                    'head' => self::string(self::object($pull, 'head', $url), 'sha', $url),
                ];
            }
            $url = self::next($response);
        }

        return $pulls;
    }

    /**
     * @return array{title: string, body: string}
     */
    public function pullRequest(string $repository, int $number): array
    {
        $url = self::API . "{$repository}/pulls/{$number}";
        $pull = $this->expect('GET', $url, 200);

        return ['title' => self::string($pull, 'title', $url), 'body' => self::body($pull, $url)];
    }

    /**
     * Set the body of a pull request, and its title unless $title is null.
     */
    public function editPullRequest(string $repository, int $number, ?string $title, string $body): void
    {
        $fields = $title === null ? ['body' => $body] : ['title' => $title, 'body' => $body];
        $this->expect('PATCH', self::API . "{$repository}/pulls/{$number}", 200, $fields);
    }

    /**
     * @return string the URL of the pull request on github.com
     */
    public function createPullRequest(string $repository, string $base, string $head, string $title, string $body): string
    {
        $url = self::API . "{$repository}/pulls";

        return self::string($this->expect('POST', $url, 201, ['title' => $title, 'head' => $head, 'base' => $base, 'body' => $body]), 'html_url', $url);
    }

    /**
     * Comment on a pull request, close it, and delete its head branch. A branch in another
     * repository, such as a fork, is left alone.
     */
    public function closePullRequest(string $repository, int $number, string $comment): void
    {
        $this->expect('POST', self::API . "{$repository}/issues/{$number}/comments", 201, ['body' => $comment]);
        $url = self::API . "{$repository}/pulls/{$number}";
        $head = self::object($this->expect('PATCH', $url, 200, ['state' => 'closed']), 'head', $url);
        $repo = self::object($head, 'repo', $url);
        if (!is_array($repo) || ($repo['full_name'] ?? null) !== $repository) {
            return;
        }
        $ref = self::API . "{$repository}/git/refs/heads/" . self::string($head, 'ref', $url);
        $response = $this->send('DELETE', $ref);
        if ($response->getStatusCode() !== 204) {
            $this->decode('DELETE', $ref, $response, 204);
        }
    }

    /**
     * The permission $user has on $repository: admin, write, read or none. `maintain` and
     * `triage` are folded into write and read by GitHub here.
     */
    public function permission(string $repository, string $user): string
    {
        $url = self::API . "{$repository}/collaborators/" . rawurlencode($user) . '/permission';

        return self::string($this->expect('GET', $url, 200), 'permission', $url);
    }

    /**
     * @return list<string> the shas of the parents of $sha
     */
    public function parents(string $repository, string $sha): array
    {
        $url = self::API . "{$repository}/commits/{$sha}";

        return array_map(
            static fn (mixed $parent): string => self::string($parent, 'sha', $url),
            self::list(self::object($this->expect('GET', $url, 200), 'parents', $url), $url),
        );
    }

    /**
     * Create the label, unless it exists. A 422 is only fine when it says so: it is also what a
     * label that GitHub rejects gets.
     */
    private function createLabel(string $repository, string $label): void
    {
        if (isset($this->labels["{$repository}#{$label}"])) {
            return;
        }
        $url = self::API . "{$repository}/labels";
        $response = $this->send('POST', $url, ['name' => $label]);
        $errors = json_decode((string) $response->getBody(), true)['errors'] ?? [];
        if ($response->getStatusCode() === 422 && is_array($errors) && in_array('already_exists', array_column(array_filter($errors, 'is_array'), 'code'), true)) {
            $this->labels["{$repository}#{$label}"] = true;

            return;
        }
        $this->decode('POST', $url, $response, 201);
        $this->labels["{$repository}#{$label}"] = true;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function expect(string $method, string $url, int $status, ?array $body = null): mixed
    {
        return $this->decode($method, $url, $this->send($method, $url, $body), $status);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $url, ?array $body = null): ResponseInterface
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'php-' . PHP_VERSION,
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
        if ($this->token !== null && $this->token !== '') {
            $headers['Authorization'] = "Bearer {$this->token}";
        }
        $encoded = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->output->isVeryVerbose() && $this->output->writeln("[HTTP] {$method} {$url}");
        $encoded !== null && $this->output->isDebug() && $this->output->writeln("[HTTP body] {$encoded}");

        try {
            return ($this->client ??= Psr18ClientDiscovery::find())->sendRequest(new Request($method, $url, $headers, $encoded));
        } catch (ClientExceptionInterface $e) {
            throw new GitHubException("{$method} {$url}: {$e->getMessage()}", 0, $e);
        }
    }

    private function decode(string $method, string $url, ResponseInterface $response, int $status): mixed
    {
        $body = (string) $response->getBody();

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $data = null;
            if ($response->getStatusCode() === $status) {
                throw new GitHubException("{$method} {$url}: the response is not JSON ({$e->getMessage()})", $status, $e);
            }
        }
        if ($response->getStatusCode() !== $status) {
            $message = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : $response->getReasonPhrase();
            $permissions = $response->getHeaderLine('X-Accepted-GitHub-Permissions');
            $help = $permissions === '' ? '' : " (the token needs {$permissions})";

            throw new GitHubException("{$method} {$url}: {$response->getStatusCode()} {$message}{$help}", $response->getStatusCode());
        }

        return $data;
    }

    /**
     * The URL of the next page, from a `Link: <...>; rel="next"` header.
     */
    private static function next(ResponseInterface $response): ?string
    {
        foreach (explode(',', $response->getHeaderLine('Link')) as $link) {
            //the token goes with every request, so a link to another host is not followed
            if (preg_match('/^\s*<([^>]+)>\s*;\s*rel="next"\s*$/', $link, $matches) === 1 && str_starts_with($matches[1], self::API)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * @return array{sha: string, message: string, tree: string}
     */
    private static function commit(mixed $commit, string $url): array
    {
        $details = self::object($commit, 'commit', $url);

        return [
            'sha' => self::string($commit, 'sha', $url),
            'message' => self::string($details, 'message', $url),
            'tree' => self::string(self::object($details, 'tree', $url), 'sha', $url),
        ];
    }

    private static function object(mixed $data, string $key, string $url): mixed
    {
        if (!is_array($data) || !array_key_exists($key, $data)) {
            throw new GitHubException("{$url}: the response has no {$key}");
        }

        return $data[$key];
    }

    private static function string(mixed $data, string $key, string $url): string
    {
        $value = self::object($data, $key, $url);
        if (!is_string($value)) {
            throw new GitHubException("{$url}: {$key} in the response is not a string");
        }

        return $value;
    }

    private static function int(mixed $data, string $key, string $url): int
    {
        $value = self::object($data, $key, $url);
        if (!is_int($value)) {
            throw new GitHubException("{$url}: {$key} in the response is not a number");
        }

        return $value;
    }

    /**
     * The body of an issue or pull request. GitHub sends null for an empty one.
     */
    private static function body(mixed $data, string $url): string
    {
        return self::object($data, 'body', $url) === null ? '' : self::string($data, 'body', $url);
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $data, string $url): array
    {
        if (!is_array($data) || !array_is_list($data)) {
            throw new GitHubException("{$url}: the response is not a list");
        }

        return $data;
    }
}
