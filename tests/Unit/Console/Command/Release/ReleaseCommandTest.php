<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Command\Release;

use Nyholm\Psr7\Response;
use OpenTelemetry\DevTools\Console\Command\Release\ReleaseCommand;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Release\ReleaseCommand
 */
class ReleaseCommandTest extends TestCase
{
    private const CHANGELOG = "# Changelog\n\n## Unreleased\n\n## 1.45.0 - 2026-11-03\n\n### Added\n\n- New attributes ([#7]).\n\n## 1.44.0 - 2026-10-01\n\n### Fixed\n\n- Old.\n\n[#7]: https://example.com/7\n";

    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];
    private ?string $changelog = self::CHANGELOG;
    private string $latestTag = '1.44.0';
    /** @var resource */
    private $out;

    public function test_release_notes_come_from_the_changelog(): void
    {
        $this->run_release("\n\n\n");

        $this->assertStringContainsString('- New attributes ([#7]).', $this->created()['body']);
        $this->assertStringContainsString('[#7]: https://example.com/7', $this->created()['body']);
        $this->assertStringContainsString('compare/1.44.0...1.45.0', $this->created()['body']);
        $this->assertStringNotContainsString("What's Changed", $this->created()['body']);
    }

    public function test_blank_answer_takes_the_version_of_the_newest_dated_section(): void
    {
        $this->run_release("\n\n\n");

        $this->assertSame('1.45.0', $this->created()['tag_name']);
    }

    public function test_pull_requests_are_listed_when_the_changelog_has_no_section_for_the_version(): void
    {
        $this->run_release("1.46.0\n\n\n");

        $this->assertSame('1.46.0', $this->created()['tag_name']);
        $this->assertStringContainsString("What's Changed", $this->created()['body']);
        $this->assertStringContainsString('Add attributes by @octocat', $this->created()['body']);
        $this->assertStringContainsString('no section for 1.46.0', $this->output());
    }

    public function test_pull_requests_are_listed_when_the_package_has_no_changelog(): void
    {
        $this->changelog = null;

        $this->run_release("1.45.0\n\n\n");

        $this->assertStringContainsString("What's Changed", $this->created()['body']);
    }

    public function test_blank_answer_skips_when_the_changelog_is_not_newer_than_the_latest_release(): void
    {
        $this->latestTag = '1.45.0';

        $this->run_release("\n");

        $this->assertNull($this->post());
    }

    public function test_skip_answer_skips_even_with_a_suggestion(): void
    {
        $this->run_release("skip\n");

        $this->assertNull($this->post());
    }

    public function test_dry_run_creates_nothing(): void
    {
        $this->run_release("\n\n\n", ['--dry-run' => true]);

        $this->assertNull($this->post());
        $this->assertStringContainsString('[DRY-RUN]', $this->output());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function run_release(string $answers, array $options = []): void
    {
        $this->out = fopen('php://memory', 'w+');
        $input = new ArrayInput($options + ['--token' => 'token', '--repo' => 'core', '--filter' => 'SemConv']);
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $answers);
        rewind($stream);
        $input->setStream($stream);
        $input->setInteractive(true);
        $stdout = $this->out;
        $output = new class($stdout) extends ConsoleOutput {
            /** @param resource $memory */
            public function __construct(private $memory)
            {
                parent::__construct();
            }

            #[\Override]
            public function getStream()
            {
                return $this->memory;
            }

            #[\Override]
            protected function doWrite(string $message, bool $newline): void
            {
                fwrite($this->memory, $message . ($newline ? PHP_EOL : ''));
            }
        };

        $this->command()->run($input, $output);
    }

    private function output(): string
    {
        rewind($this->out);

        return (string) stream_get_contents($this->out);
    }

    /**
     * @return array<string, mixed>
     */
    private function created(): array
    {
        $post = $this->post();
        $this->assertNotNull($post, 'no release was created');

        return json_decode($post['body'], true);
    }

    /**
     * @return array{method: string, url: string, body: string}|null
     */
    private function post(): ?array
    {
        foreach ($this->requests as $request) {
            if ($request['method'] === 'POST') {
                return $request;
            }
        }

        return null;
    }

    private function command(): ReleaseCommand
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(function (RequestInterface $request): Response {
            $url = (string) $request->getUri();
            $this->requests[] = ['method' => $request->getMethod(), 'url' => $url, 'body' => (string) $request->getBody()];

            return $this->respond($request->getMethod(), $url);
        });

        return new ReleaseCommand($client);
    }

    private function respond(string $method, string $url): Response
    {
        $json = static fn (mixed $data, int $status = 200): Response => new Response($status, [], json_encode($data));

        return match (true) {
            $method === 'POST' => $json(['html_url' => 'https://github.com/x/y/releases/1'], 201),
            str_ends_with($url, '/main/.gitsplit.yml') => new Response(200, [], "splits:\n  - prefix: \"src/SemConv\"\n    target: \"https://\${GH_TOKEN}@github.com/opentelemetry-php/sem-conv.git\"\n"),
            str_ends_with($url, '/main/src/SemConv/CHANGELOG.md') => $this->changelog === null ? new Response(404) : new Response(200, [], $this->changelog),
            str_contains($url, '/sem-conv/releases/latest') => $json(['tag_name' => $this->latestTag, 'created_at' => '2026-01-01T00:00:00Z']),
            str_contains($url, '/sem-conv/compare/') => $json(['commits' => [['sha' => 'abc', 'commit' => ['message' => 'Add attributes']]]]),
            str_contains($url, '/commits/abc/pulls') => $json([['user' => ['login' => 'octocat'], 'html_url' => 'https://github.com/o/r/pull/7', 'number' => 7, 'title' => 'Add attributes']]),
            str_contains($url, '/commits?path=') => $json([['sha' => 'abc', 'commit' => ['message' => 'Add attributes']]]),
            default => new Response(404),
        };
    }
}
