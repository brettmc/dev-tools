<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Release;

use Nyholm\Psr7\Response;
use OpenTelemetry\DevTools\Console\Release\GitHub;
use OpenTelemetry\DevTools\Console\Release\GitHubException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @covers \OpenTelemetry\DevTools\Console\Release\GitHub
 * @covers \OpenTelemetry\DevTools\Console\Release\GitHubException
 */
class GitHubTest extends TestCase
{
    private const API = 'https://api.github.com/repos/o/r';

    /** @var array<string, Response> by URL */
    private array $responses = [];
    /** @var list<RequestInterface> */
    private array $requests = [];
    private GitHub $github;

    #[\Override]
    public function setUp(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(function (RequestInterface $request): Response {
            $this->requests[] = $request;

            return $this->responses[(string) $request->getUri()] ?? new Response(404, [], '{"message":"Not Found"}');
        });
        $this->github = new GitHub($client);
    }

    public function test_latest_release(): void
    {
        $this->respond(self::API . '/releases/latest', ['tag_name' => '1.2.0', 'created_at' => '2026-01-01T00:00:00Z']);

        $release = $this->github->latestRelease('o/r');

        $this->assertNotNull($release);
        $this->assertSame('1.2.0', $release->version);
        $this->assertSame('2026-01-01T00:00:00Z', $release->timestamp);
    }

    public function test_repository_without_a_release_has_no_latest_release(): void
    {
        $this->respond(self::API, ['full_name' => 'o/r']);

        $this->assertNull($this->github->latestRelease('o/r'));
    }

    public function test_latest_release_of_a_repository_that_cannot_be_seen_fails(): void
    {
        //a wrong split target, or a token without access, must not pass for a first release
        $this->expectException(GitHubException::class);
        $this->expectExceptionMessage(self::API);

        $this->github->latestRelease('o/r');
    }

    public function test_error_names_the_url_status_and_message(): void
    {
        $this->respond(self::API . '/releases/latest', ['message' => 'API rate limit exceeded'], 403);

        try {
            $this->github->latestRelease('o/r');
            $this->fail('no exception');
        } catch (GitHubException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertStringContainsString('GET ' . self::API . '/releases/latest', $e->getMessage());
            $this->assertStringContainsString('403', $e->getMessage());
            $this->assertStringContainsString('API rate limit exceeded', $e->getMessage());
        }
    }

    public function test_bad_json_fails_with_the_url(): void
    {
        $this->responses[self::API . '/releases/latest'] = new Response(200, [], '<html>');

        $this->expectException(GitHubException::class);
        $this->expectExceptionMessage('GET ' . self::API . '/releases/latest: the response is not JSON');

        $this->github->latestRelease('o/r');
    }

    public function test_missing_field_fails_with_the_url(): void
    {
        $this->respond(self::API . '/releases/latest', ['tag_name' => '1.2.0']);

        $this->expectException(GitHubException::class);
        $this->expectExceptionMessage(self::API . '/releases/latest');

        $this->github->latestRelease('o/r');
    }

    public function test_branch_sha_is_read_from_the_exact_ref(): void
    {
        $this->respond(self::API . '/git/ref/heads/1.x', ['ref' => 'refs/heads/1.x', 'object' => ['sha' => 'abc']]);

        $this->assertSame('abc', $this->github->branchSha('o/r', '1.x'));
    }

    public function test_branch_that_only_prefixes_another_fails(): void
    {
        //matching-refs would answer refs/heads/1.x-old for 1.x
        $this->respond(self::API . '/git/matching-refs/heads/1.x', [['ref' => 'refs/heads/1.x-old', 'object' => ['sha' => 'old']]]);

        $this->expectException(GitHubException::class);
        $this->expectExceptionMessage('404');

        $this->github->branchSha('o/r', '1.x');
    }

    public function test_commits_follow_the_next_link(): void
    {
        $first = self::API . '/commits?sha=main&per_page=100&since=2026-01-01T00:00:01Z';
        $second = self::API . '/commits?sha=main&per_page=100&since=2026-01-01T00:00:01Z&page=2';
        $this->respond($first, [self::commit('a')], 200, ['Link' => "<{$second}>; rel=\"next\", <{$second}>; rel=\"last\""]);
        $this->respond($second, [self::commit('b')], 200, ['Link' => "<{$first}>; rel=\"prev\", <{$first}>; rel=\"first\""]);

        $commits = iterator_to_array($this->github->commits('o/r', 'main', '2026-01-01T00:00:01Z'), false);

        $this->assertSame([
            ['sha' => 'a', 'message' => 'Message a', 'tree' => 'tree-a'],
            ['sha' => 'b', 'message' => 'Message b', 'tree' => 'tree-b'],
        ], $commits);
    }

    public function test_commits_are_fetched_only_as_far_as_they_are_read(): void
    {
        $first = self::API . '/commits?sha=main&per_page=100';
        $this->respond($first, [self::commit('a')], 200, ['Link' => '<' . self::API . '/commits?sha=main&per_page=100&page=2>; rel="next"']);

        foreach ($this->github->commits('o/r', 'main', null) as $commit) {
            break;
        }

        $this->assertCount(1, $this->requests);
    }

    public function test_compare_follows_the_next_link(): void
    {
        $first = self::API . '/compare/1.0.0...abc?per_page=100';
        $second = self::API . '/compare/1.0.0...abc?per_page=100&page=2';
        $this->respond($first, ['commits' => [self::commit('a')]], 200, ['Link' => "<{$second}>; rel=\"next\""]);
        $this->respond($second, ['commits' => [self::commit('b')]]);

        $this->assertSame([
            ['sha' => 'a', 'message' => 'Message a', 'tree' => 'tree-a'],
            ['sha' => 'b', 'message' => 'Message b', 'tree' => 'tree-b'],
        ], $this->github->compare('o/r', '1.0.0', 'abc'));
    }

    public function test_create_release_returns_its_url(): void
    {
        $this->respond(self::API . '/releases', ['html_url' => 'https://github.com/o/r/releases/1'], 201);

        $url = $this->github->createRelease('o/r', ['tag_name' => '1.0.0']);

        $this->assertSame('https://github.com/o/r/releases/1', $url);
        $this->assertSame('POST', $this->requests[0]->getMethod());
        $this->assertSame('{"tag_name":"1.0.0"}', (string) $this->requests[0]->getBody());
    }

    public function test_create_release_that_is_not_created_fails(): void
    {
        $this->respond(self::API . '/releases', ['message' => 'Validation Failed'], 422);

        $this->expectException(GitHubException::class);
        $this->expectExceptionMessage('Validation Failed');

        $this->github->createRelease('o/r', ['tag_name' => '1.0.0']);
    }

    public function test_create_tag_and_ref(): void
    {
        $this->respond(self::API . '/git/tags', ['sha' => 'tag-sha', 'url' => 'https://api.github.com/tag'], 201);
        $this->respond(self::API . '/git/refs', ['url' => 'https://api.github.com/ref'], 201);

        $this->assertSame(['sha' => 'tag-sha', 'url' => 'https://api.github.com/tag'], $this->github->createTag('o/r', '1.0.0', 'Tagging 1.0.0', 'abc'));
        $this->assertSame('https://api.github.com/ref', $this->github->createRef('o/r', 'refs/tags/1.0.0', 'tag-sha'));
        $this->assertSame(
            ['tag' => '1.0.0', 'message' => 'Tagging 1.0.0', 'object' => 'abc', 'type' => 'commit'],
            json_decode((string) $this->requests[0]->getBody(), true)
        );
        $this->assertSame(['ref' => 'refs/tags/1.0.0', 'sha' => 'tag-sha'], json_decode((string) $this->requests[1]->getBody(), true));
    }

    public function test_error_names_the_permissions_the_token_needs(): void
    {
        $this->respond(self::API . '/git/tags', ['message' => 'Resource not accessible by integration'], 403, ['X-Accepted-GitHub-Permissions' => 'contents=write']);

        $this->expectException(GitHubException::class);
        $this->expectExceptionMessage('contents=write');

        $this->github->createTag('o/r', '1.0.0', 'Tagging 1.0.0', 'abc');
    }

    public function test_token_is_sent_as_a_bearer_token(): void
    {
        $this->respond(self::API, ['full_name' => 'o/r']);
        $this->github->authenticate('secret');

        $this->github->latestRelease('o/r');

        $this->assertSame('Bearer secret', $this->requests[0]->getHeaderLine('Authorization'));
        $this->assertSame('2022-11-28', $this->requests[0]->getHeaderLine('X-GitHub-Api-Version'));
    }

    public function test_without_a_token_no_authorization_is_sent(): void
    {
        $this->respond(self::API, ['full_name' => 'o/r']);

        $this->github->latestRelease('o/r');

        $this->assertFalse($this->requests[0]->hasHeader('Authorization'));
    }

    public function test_requests_are_traced_when_very_verbose(): void
    {
        $this->respond(self::API, ['full_name' => 'o/r']);
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERY_VERBOSE);
        $this->github->trace($output);

        $this->github->latestRelease('o/r');

        $this->assertStringContainsString('[HTTP] GET ' . self::API . '/releases/latest', $output->fetch());
    }

    /**
     * @param array<string, string> $headers
     */
    private function respond(string $url, mixed $data, int $status = 200, array $headers = []): void
    {
        $this->responses[$url] = new Response($status, $headers, json_encode($data));
    }

    /**
     * @return array<string, mixed>
     */
    private static function commit(string $sha): array
    {
        return ['sha' => $sha, 'commit' => ['message' => "Message {$sha}", 'tree' => ['sha' => "tree-{$sha}"]]];
    }
}
