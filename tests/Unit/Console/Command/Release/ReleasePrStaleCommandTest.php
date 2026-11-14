<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Command\Release;

use Nyholm\Psr7\Response;
use OpenTelemetry\DevTools\Console\Command\Release\ReleasePrStaleCommand;
use OpenTelemetry\DevTools\Console\Release\GitHub;
use OpenTelemetry\DevTools\Console\Release\ReleaseDescription;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Release\ReleasePrStaleCommand
 */
class ReleasePrStaleCommandTest extends TestCase
{
    private const REPO = 'open-telemetry/opentelemetry-php';
    private const API = 'https://api.github.com/repos/' . self::REPO;
    private const PULLS = self::API . '/pulls?state=open&head=open-telemetry%3Aotelbot%2Frelease&per_page=100';
    private const HEAD = 'bead000000000000000000000000000000000000';
    private const MAIN = '841cc9a1b2c3d4e5f60718293a4b5c6d7e8f9012';

    /** @var list<array{number: int, body: string, user?: string}> */
    private array $pulls = [['number' => 7, 'body' => "Releases x.\r\n\r\nMore.\r\n"]];
    /** @var list<string> */
    private array $parents = ['older'];
    /** @var list<RequestInterface> */
    private array $requests = [];
    private int $pullsStatus = 200;
    private string|false $environmentToken;
    private CommandTester $tester;

    #[\Override]
    public function setUp(): void
    {
        $this->environmentToken = getenv('GITHUB_TOKEN');
        putenv('GITHUB_TOKEN');
    }

    #[\Override]
    public function tearDown(): void
    {
        putenv($this->environmentToken === false ? 'GITHUB_TOKEN' : "GITHUB_TOKEN={$this->environmentToken}");
    }

    public function test_a_pull_request_behind_main_is_marked_stale(): void
    {
        $this->assertSame(Command::SUCCESS, $this->run_command());

        $this->assertSame(['body' => ReleaseDescription::STALE . "\n\nReleases x.\n\nMore.\n"], $this->sent('PATCH', self::API . '/pulls/7'));
        $this->assertStringContainsString('Marked #7 stale', $this->tester->getDisplay());
        $this->assertSame(self::PULLS, (string) $this->requests[0]->getUri());
        $this->assertSame('Bearer app-token', $this->requests[0]->getHeaderLine('Authorization'));
    }

    public function test_a_pull_request_built_on_main_is_left_alone(): void
    {
        $this->parents = [self::MAIN];

        $this->assertSame(Command::SUCCESS, $this->run_command());

        $this->assertStringContainsString('#7 is built on main', $this->tester->getDisplay());
        $this->assertNull($this->sent('PATCH', self::API . '/pulls/7'));
    }

    public function test_a_merge_commit_with_main_as_one_parent_is_not_built_on_main(): void
    {
        $this->parents = [self::MAIN, 'other'];

        $this->assertSame(Command::SUCCESS, $this->run_command());

        $this->assertNotNull($this->sent('PATCH', self::API . '/pulls/7'));
    }

    public function test_a_pull_request_already_marked_is_left_alone(): void
    {
        $this->pulls[0]['body'] = ReleaseDescription::markStale('Releases x.');

        $this->assertSame(Command::SUCCESS, $this->run_command());

        $this->assertStringContainsString('#7 is already marked stale', $this->tester->getDisplay());
        $this->assertNull($this->sent('PATCH', self::API . '/pulls/7'));
    }

    public function test_no_pull_request_is_nothing_to_do(): void
    {
        $this->pulls = [['number' => 8, 'body' => '', 'user' => 'someone']];

        $this->assertSame(Command::SUCCESS, $this->run_command());

        $this->assertStringContainsString(self::REPO . ' has no open release pull request', $this->tester->getDisplay());
        $this->assertCount(1, $this->requests);
    }

    public function test_more_than_one_pull_request_fails(): void
    {
        $this->pulls[] = ['number' => 8, 'body' => ''];

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString(self::REPO . ' has more than one open release pull request', $this->tester->getDisplay());
    }

    public function test_errors_are_annotated_under_github_actions(): void
    {
        $this->pulls[] = ['number' => 8, 'body' => ''];
        putenv('GITHUB_ACTIONS=true');

        try {
            $this->run_command();
        } finally {
            putenv('GITHUB_ACTIONS');
        }

        $this->assertStringContainsString('::error::' . self::REPO . ' has more than one open release pull request', $this->tester->getDisplay());
    }

    public function test_contrib_is_looked_up_in_contrib(): void
    {
        $this->assertSame(Command::SUCCESS, $this->run_command(['--repository' => 'open-telemetry/opentelemetry-php-contrib']));

        $this->assertStringStartsWith('https://api.github.com/repos/open-telemetry/opentelemetry-php-contrib/pulls?', (string) $this->requests[0]->getUri());
    }

    public function test_the_repository_must_be_a_monorepo(): void
    {
        $this->assertSame(Command::FAILURE, $this->run_command(['--repository' => 'someone/else']));

        $this->assertStringContainsString('--repository must be open-telemetry/opentelemetry-php or open-telemetry/opentelemetry-php-contrib', $this->tester->getDisplay());
        $this->assertSame([], $this->requests);
    }

    public function test_a_token_is_needed(): void
    {
        $this->assertSame(Command::FAILURE, $this->run_command(['--token' => null]));

        $this->assertStringContainsString('needs a GitHub token', $this->tester->getDisplay());
    }

    public function test_a_failed_github_request_fails(): void
    {
        $this->pullsStatus = 500;

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString('500', $this->tester->getDisplay());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sent(string $method, string $url): ?array
    {
        foreach ($this->requests as $request) {
            if ($request->getMethod() === $method && (string) $request->getUri() === $url) {
                return json_decode((string) $request->getBody(), true);
            }
        }

        return null;
    }

    private function respond(RequestInterface $request): Response
    {
        $this->requests[] = $request;
        $url = (string) $request->getUri();

        return match (true) {
            str_contains($url, '/pulls?') => new Response($this->pullsStatus, [], json_encode(array_map(static fn (array $pull): array => [
                'number' => $pull['number'],
                'body' => $pull['body'],
                'user' => ['login' => $pull['user'] ?? 'otelbot-php[bot]'],
                'head' => ['sha' => self::HEAD],
            ], $this->pulls))),
            str_ends_with($url, '/commits/' . self::HEAD) => new Response(200, [], json_encode(['parents' => array_map(static fn (string $sha): array => ['sha' => $sha], $this->parents)])),
            str_ends_with($url, '/git/ref/heads/main') => new Response(200, [], json_encode(['ref' => 'refs/heads/main', 'object' => ['sha' => self::MAIN]])),
            $request->getMethod() === 'PATCH' => new Response(200, [], '{}'),
            default => new Response(404, [], '{"message":"Not Found"}'),
        };
    }

    /**
     * @param array<string, mixed> $input
     */
    private function run_command(array $input = []): int
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(fn (RequestInterface $request): Response => $this->respond($request));
        $this->tester = new CommandTester(new ReleasePrStaleCommand(new GitHub($client)));
        $input = array_filter($input + ['--repository' => self::REPO, '--token' => 'app-token'], static fn (mixed $value): bool => $value !== null);

        return $this->tester->execute($input);
    }
}
