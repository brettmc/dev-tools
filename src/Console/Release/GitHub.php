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
            if (preg_match('/^\s*<([^>]+)>\s*;\s*rel="next"\s*$/', $link, $matches) === 1) {
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
