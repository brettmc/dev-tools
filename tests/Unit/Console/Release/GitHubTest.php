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

    public function test_issue_titles_of_a_label_skip_pull_requests(): void
    {
        $this->respond(self::API . '/issues?state=all&labels=php-version-support&per_page=100', [
            ['title' => 'Drop PHP 8.2'],
            ['title' => 'A pull request', 'pull_request' => []],
            ['title' => 'Add PHP 8.6'],
        ]);

        $this->assertSame(['Drop PHP 8.2', 'Add PHP 8.6'], $this->github->issueTitles('o/r', 'php-version-support'));
    }

    public function test_issue_titles_follow_the_pages(): void
    {
        $first = self::API . '/issues?state=all&labels=x&per_page=100';
        $second = self::API . '/issues?state=all&labels=x&per_page=100&page=2';
        $this->respond($first, [['title' => 'One']], 200, ['Link' => "<{$second}>; rel=\"next\""]);
        $this->respond($second, [['title' => 'Two']]);

        $this->assertSame(['One', 'Two'], $this->github->issueTitles('o/r', 'x'));
        $this->assertCount(2, $this->requests);
    }

    public function test_issue_titles_encode_the_label(): void
    {
        $this->respond(self::API . '/issues?state=all&labels=a%26b&per_page=100', [['title' => 'Found']]);

        $this->assertSame(['Found'], $this->github->issueTitles('o/r', 'a&b'));
    }

    public function test_issue_titles_fail_when_github_refuses(): void
    {
        $this->respond(self::API . '/issues?state=all&labels=x&per_page=100', ['message' => 'Bad credentials'], 401);

        $this->expectException(GitHubException::class);
        $this->expectExceptionMessage('401 Bad credentials');

        $this->github->issueTitles('o/r', 'x');
    }

    public function test_create_issue_creates_its_labels_first(): void
    {
        $this->respond(self::API . '/labels', ['name' => 'php-version-support'], 201);
        $this->respond(self::API . '/issues', ['html_url' => 'https://github.com/o/r/issues/7'], 201);
        $this->github->authenticate('secret');

        $url = $this->github->createIssue('o/r', 'Drop PHP 8.2', "Body\n- [ ] item", ['php-version-support']);

        $this->assertSame('https://github.com/o/r/issues/7', $url);
        $this->assertSame(['/repos/o/r/labels', '/repos/o/r/issues'], array_map(static fn (RequestInterface $r): string => $r->getUri()->getPath(), $this->requests));
        $this->assertSame(['name' => 'php-version-support'], json_decode((string) $this->requests[0]->getBody(), true));
        $this->assertSame(
            ['title' => 'Drop PHP 8.2', 'body' => "Body\n- [ ] item", 'labels' => ['php-version-support']],
            json_decode((string) $this->requests[1]->getBody(), true),
        );
        $this->assertSame('Bearer secret', $this->requests[1]->getHeaderLine('Authorization'));
    }

    public function test_create_issue_goes_on_when_the_label_exists(): void
    {
        $this->respond(self::API . '/labels', ['errors' => [['resource' => 'Label', 'code' => 'already_exists', 'field' => 'name']]], 422);
        $this->respond(self::API . '/issues', ['html_url' => 'https://github.com/o/r/issues/8'], 201);

        $this->assertSame('https://github.com/o/r/issues/8', $this->github->createIssue('o/r', 'Title', 'Body', ['x']));
    }

    public function test_create_issue_fails_for_a_label_github_rejects(): void
    {
        $this->respond(self::API . '/labels', ['message' => 'Validation Failed', 'errors' => [['resource' => 'Label', 'code' => 'invalid', 'field' => 'name']]], 422);

        $this->expectException(GitHubException::class);
        $this->expectExceptionMessage('422 Validation Failed');

        $this->github->createIssue('o/r', 'Title', 'Body', ['x']);
    }

    public function test_create_issue_does_not_take_another_status_for_an_existing_label(): void
    {
        $this->respond(self::API . '/labels', ['message' => 'Forbidden', 'errors' => [['code' => 'already_exists']]], 403);

        $this->expectException(GitHubException::class);
        $this->expectExceptionMessage('403 Forbidden');

        $this->github->createIssue('o/r', 'Title', 'Body', ['x']);
    }

    public function test_create_issue_fails_for_a_422_that_does_not_say_why(): void
    {
        $this->responses[self::API . '/labels'] = new Response(422, [], 'nope');

        $this->expectException(GitHubException::class);

        $this->github->createIssue('o/r', 'Title', 'Body', ['x']);
    }

    public function test_create_issue_creates_a_label_once_for_all_the_issues(): void
    {
        $this->respond(self::API . '/labels', ['name' => 'x'], 201);
        $this->respond(self::API . '/issues', ['html_url' => 'https://github.com/o/r/issues/1'], 201);

        $this->github->createIssue('o/r', 'One', 'Body', ['x']);
        $this->github->createIssue('o/r', 'Two', 'Body', ['x']);

        $paths = array_map(static fn (RequestInterface $r): string => $r->getUri()->getPath(), $this->requests);
        $this->assertSame(['/repos/o/r/labels', '/repos/o/r/issues', '/repos/o/r/issues'], $paths);
    }

    public function test_create_issue_remembers_a_label_that_already_existed(): void
    {
        $this->respond(self::API . '/labels', ['errors' => [['code' => 'already_exists']]], 422);
        $this->respond(self::API . '/issues', ['html_url' => 'https://github.com/o/r/issues/1'], 201);

        $this->github->createIssue('o/r', 'One', 'Body', ['x']);
        $this->github->createIssue('o/r', 'Two', 'Body', ['x']);

        $this->assertCount(3, $this->requests);
    }

    public function test_create_issue_reports_the_issues_page_when_the_response_has_no_url(): void
    {
        $this->respond(self::API . '/issues', ['id' => 1], 201);

        $this->assertSame('https://github.com/o/r/issues', $this->github->createIssue('o/r', 'Title', 'Body'));
    }

    public function test_a_link_to_another_host_is_not_followed(): void
    {
        $first = self::API . '/issues?state=all&labels=x&per_page=100';
        $this->respond($first, [['title' => 'One']], 200, ['Link' => '<https://evil.example/repos/o/r/issues?page=2>; rel="next"']);
        $this->github->authenticate('secret');

        $this->assertSame(['One'], $this->github->issueTitles('o/r', 'x'));
        $this->assertCount(1, $this->requests, 'the token went only to GitHub');
    }

    public function test_create_issue_without_labels_creates_none(): void
    {
        $this->respond(self::API . '/issues', ['html_url' => 'https://github.com/o/r/issues/9'], 201);

        $this->github->createIssue('o/r', 'Title', 'Body');

        $this->assertCount(1, $this->requests);
    }

    public function test_create_issue_fails_when_it_cannot_be_opened(): void
    {
        $this->respond(self::API . '/issues', ['message' => 'Issues are disabled for this repo'], 410);

        $this->expectException(GitHubException::class);
        $this->expectExceptionMessage('410 Issues are disabled for this repo');

        $this->github->createIssue('o/r', 'Title', 'Body');
    }

    public function test_open_pull_requests_from_a_branch_by_one_author(): void
    {
        $this->respond(self::API . '/pulls?state=open&head=o%3Aotelbot%2Frelease&per_page=100', [
            self::pull(7, 'otelbot-php[bot]', "Body\r\nof 7", 'sha-7'),
            self::pull(8, 'someone', 'Not the bot', 'sha-8'),
            self::pull(9, 'otelbot-php[bot]', null, 'sha-9'),
        ]);

        $this->assertSame([
            ['number' => 7, 'body' => "Body\r\nof 7", 'head' => 'sha-7'],
            ['number' => 9, 'body' => '', 'head' => 'sha-9'],
        ], $this->github->openPullRequests('o/r', 'otelbot/release', 'otelbot-php[bot]'));
    }

    public function test_open_pull_requests_follow_the_pages(): void
    {
        $first = self::API . '/pulls?state=open&head=o%3Ab&per_page=100';
        $second = $first . '&page=2';
        $this->respond($first, [self::pull(1, 'bot', 'One', 'a')], 200, ['Link' => "<{$second}>; rel=\"next\""]);
        $this->respond($second, [self::pull(2, 'bot', 'Two', 'b')]);

        $this->assertSame([1, 2], array_column($this->github->openPullRequests('o/r', 'b', 'bot'), 'number'));
    }

    public function test_open_pull_requests_fail_for_a_malformed_pull_request(): void
    {
        $this->respond(self::API . '/pulls?state=open&head=o%3Ab&per_page=100', [['number' => '7', 'user' => ['login' => 'bot']]]);

        $this->expectException(GitHubException::class);
        $this->expectExceptionMessage('number');

        $this->github->openPullRequests('o/r', 'b', 'bot');
    }

    public function test_pull_request_title_and_body(): void
    {
        $this->respond(self::API . '/pulls/7', ['title' => 'Release 2026-11-14', 'body' => 'Body']);

        $this->assertSame(['title' => 'Release 2026-11-14', 'body' => 'Body'], $this->github->pullRequest('o/r', 7));
    }

    public function test_pull_request_without_a_body_has_an_empty_one(): void
    {
        $this->respond(self::API . '/pulls/7', ['title' => 'Release', 'body' => null]);

        $this->assertSame(['title' => 'Release', 'body' => ''], $this->github->pullRequest('o/r', 7));
    }

    public function test_edit_pull_request(): void
    {
        $this->respond(self::API . '/pulls/7', ['number' => 7]);

        $this->github->editPullRequest('o/r', 7, 'Release 2026-11-14', 'Body');

        $this->assertSame('PATCH', $this->requests[0]->getMethod());
        $this->assertSame(['title' => 'Release 2026-11-14', 'body' => 'Body'], json_decode((string) $this->requests[0]->getBody(), true));
    }

    public function test_edit_pull_request_body_only_leaves_the_title(): void
    {
        $this->respond(self::API . '/pulls/7', ['number' => 7]);

        $this->github->editPullRequest('o/r', 7, null, 'Body');

        $this->assertSame(['body' => 'Body'], json_decode((string) $this->requests[0]->getBody(), true));
    }

    public function test_edit_pull_request_that_fails(): void
    {
        $this->respond(self::API . '/pulls/7', ['message' => 'Resource not accessible by integration'], 403);

        $this->expectException(GitHubException::class);
        $this->expectExceptionMessage('PATCH ' . self::API . '/pulls/7: 403');

        $this->github->editPullRequest('o/r', 7, null, 'Body');
    }

    public function test_create_pull_request_returns_its_url(): void
    {
        $this->respond(self::API . '/pulls', ['html_url' => 'https://github.com/o/r/pull/7'], 201);

        $this->assertSame('https://github.com/o/r/pull/7', $this->github->createPullRequest('o/r', 'main', 'otelbot/release', 'Release', 'Body'));
        $this->assertSame(
            ['title' => 'Release', 'head' => 'otelbot/release', 'base' => 'main', 'body' => 'Body'],
            json_decode((string) $this->requests[0]->getBody(), true),
        );
    }

    public function test_close_pull_request_comments_closes_and_deletes_the_branch(): void
    {
        $this->respond(self::API . '/issues/7/comments', ['id' => 1], 201);
        $this->respond(self::API . '/pulls/7', ['head' => ['ref' => 'otelbot/release', 'repo' => ['full_name' => 'o/r']]]);
        $this->responses[self::API . '/git/refs/heads/otelbot/release'] = new Response(204);

        $this->github->closePullRequest('o/r', 7, 'Nothing to release.');

        $this->assertSame(
            ['POST /repos/o/r/issues/7/comments', 'PATCH /repos/o/r/pulls/7', 'DELETE /repos/o/r/git/refs/heads/otelbot/release'],
            array_map(static fn (RequestInterface $r): string => $r->getMethod() . ' ' . $r->getUri()->getPath(), $this->requests),
        );
        $this->assertSame(['body' => 'Nothing to release.'], json_decode((string) $this->requests[0]->getBody(), true));
        $this->assertSame(['state' => 'closed'], json_decode((string) $this->requests[1]->getBody(), true));
    }

    public function test_close_pull_request_from_a_fork_leaves_its_branch(): void
    {
        $this->respond(self::API . '/issues/7/comments', ['id' => 1], 201);
        $this->respond(self::API . '/pulls/7', ['head' => ['ref' => 'main', 'repo' => ['full_name' => 'fork/r']]]);

        $this->github->closePullRequest('o/r', 7, 'Closed.');

        $this->assertCount(2, $this->requests);
    }

    public function test_close_pull_request_fails_when_the_branch_is_not_deleted(): void
    {
        $this->respond(self::API . '/issues/7/comments', ['id' => 1], 201);
        $this->respond(self::API . '/pulls/7', ['head' => ['ref' => 'b', 'repo' => ['full_name' => 'o/r']]]);
        $this->respond(self::API . '/git/refs/heads/b', ['message' => 'Reference does not exist'], 422);

        $this->expectException(GitHubException::class);
        $this->expectExceptionMessage('DELETE ' . self::API . '/git/refs/heads/b: 422 Reference does not exist');

        $this->github->closePullRequest('o/r', 7, 'Closed.');
    }

    public function test_permission_of_a_user(): void
    {
        $this->respond(self::API . '/collaborators/otelbot-php%5Bbot%5D/permission', ['permission' => 'write', 'role_name' => 'maintain']);

        $this->assertSame('write', $this->github->permission('o/r', 'otelbot-php[bot]'));
    }

    public function test_parents_of_a_commit(): void
    {
        $this->respond(self::API . '/commits/abc', ['sha' => 'abc', 'parents' => [['sha' => 'p1'], ['sha' => 'p2']]]);

        $this->assertSame(['p1', 'p2'], $this->github->parents('o/r', 'abc'));
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

    /**
     * @return array<string, mixed>
     */
    private static function pull(int $number, string $author, ?string $body, string $head): array
    {
        return ['number' => $number, 'user' => ['login' => $author], 'body' => $body, 'head' => ['sha' => $head, 'ref' => 'otelbot/release']];
    }
}
