<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Command\Release;

use Nyholm\Psr7\Response;
use OpenTelemetry\DevTools\Console\Command\Release\PeclTagCommand;
use OpenTelemetry\DevTools\Console\Release\GitHub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Release\PeclTagCommand
 */
class PeclTagCommandTest extends TestCase
{
    private const API = 'https://api.github.com/repos/open-telemetry/opentelemetry-php-instrumentation';

    private string|false $environmentToken;
    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];
    private ?string $latest = '1.2.0';
    private int $tagStatus = 201;
    private int $refStatus = 201;
    private CommandTester $tester;

    #[\Override]
    public function setUp(): void
    {
        $this->environmentToken = getenv('GITHUB_TOKEN');
        putenv('GITHUB_TOKEN');

        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(function (RequestInterface $request): Response {
            $url = (string) $request->getUri();
            $this->requests[] = ['method' => $request->getMethod(), 'url' => $url, 'body' => (string) $request->getBody()];
            $json = static fn (mixed $data, int $status = 200): Response => new Response($status, [], json_encode($data));

            return match ($url) {
                self::API => $json(['full_name' => 'open-telemetry/opentelemetry-php-instrumentation']),
                self::API . '/git/ref/heads/main' => $json(['ref' => 'refs/heads/main', 'object' => ['sha' => 'abc123']]),
                self::API . '/releases/latest' => $this->latest === null
                    ? $json(['message' => 'Not Found'], 404)
                    : $json(['tag_name' => $this->latest, 'created_at' => '2026-01-01T00:00:00Z']),
                self::API . '/git/tags' => $this->tagStatus === 201
                    ? $json(['sha' => 'tag-sha', 'url' => self::API . '/git/tags/tag-sha'], 201)
                    : $json(['message' => 'Resource not accessible by integration'], $this->tagStatus),
                self::API . '/git/refs' => $this->refStatus === 201
                    ? $json(['url' => self::API . '/git/refs/tags/1.3.0'], 201)
                    : $json(['message' => 'Reference already exists'], $this->refStatus),
                default => $json(['message' => 'Not Found'], 404),
            };
        });
        $this->tester = new CommandTester(new PeclTagCommand(new GitHub($client)));
    }

    #[\Override]
    public function tearDown(): void
    {
        putenv($this->environmentToken === false ? 'GITHUB_TOKEN' : "GITHUB_TOKEN={$this->environmentToken}");
    }

    public function test_no_token_fails_without_interaction(): void
    {
        $this->tester->execute([], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $this->assertStringContainsString('No github token', $this->tester->getDisplay());
        $this->assertSame([], $this->requests);
    }

    public function test_tag_and_ref_are_created_at_the_branch_tip(): void
    {
        $this->tester->setInputs(['1.3.0']);

        $status = $this->tester->execute(['--token' => 'token']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame(
            ['tag' => '1.3.0', 'message' => 'Tagging 1.3.0', 'object' => 'abc123', 'type' => 'commit'],
            json_decode($this->post('/git/tags'), true)
        );
        $this->assertSame(['ref' => 'refs/tags/1.3.0', 'sha' => 'tag-sha'], json_decode($this->post('/git/refs'), true));
    }

    public function test_failed_tag_creation_fails(): void
    {
        $this->tagStatus = 403;
        $this->tester->setInputs(['1.3.0']);

        $status = $this->tester->execute(['--token' => 'token']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Resource not accessible by integration', $this->tester->getDisplay());
        $this->assertNull($this->post('/git/refs'));
    }

    public function test_failed_ref_creation_fails(): void
    {
        $this->refStatus = 422;
        $this->tester->setInputs(['1.3.0']);

        $status = $this->tester->execute(['--token' => 'token']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Reference already exists', $this->tester->getDisplay());
    }

    public function test_repository_without_a_release_prompts_without_a_latest(): void
    {
        $this->latest = null;
        $this->tester->setInputs(['1.0.0']);

        $status = $this->tester->execute(['--token' => 'token']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('Latest=-nothing-', $this->tester->getDisplay());
        $this->assertNotNull($this->post('/git/refs'));
    }

    public function test_dry_run_creates_nothing(): void
    {
        $this->tester->setInputs(['1.3.0']);

        $status = $this->tester->execute(['--token' => 'token', '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame([], array_filter($this->requests, static fn (array $request): bool => $request['method'] === 'POST'));
        $this->assertStringContainsString('[DRY-RUN] POST ' . self::API . '/git/tags', $this->tester->getDisplay());
    }

    private function post(string $path): ?string
    {
        foreach ($this->requests as $request) {
            if ($request['method'] === 'POST' && $request['url'] === self::API . $path) {
                return $request['body'];
            }
        }

        return null;
    }
}
