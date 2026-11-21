<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Command\Release;

use Nyholm\Psr7\Response;
use OpenTelemetry\DevTools\Console\Command\Release\ReleasePrCheckCommand;
use OpenTelemetry\DevTools\Console\Release\GitHub;
use OpenTelemetry\DevTools\Console\Release\ReleaseDescription;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Release\ReleasePrCheckCommand
 */
class ReleasePrCheckCommandTest extends TestCase
{
    private const REPO = 'open-telemetry/opentelemetry-php';
    private const API = 'https://api.github.com/repos/' . self::REPO;
    private const HEAD = 'bead000000000000000000000000000000000000';
    private const MAIN = '841cc9a1b2c3d4e5f60718293a4b5c6d7e8f9012';
    private const OTHER = '2222222222222222222222222222222222222222';
    private const HASH = '646ae93db4df7370';
    private const NOT_BUILT = 'The head commit was not built by release:pr. Rebuild the pull request.';
    private const NOT_ON_MAIN = 'This pull request is not built on the tip of main. Rebuild it: tick Open a release PR on the release dashboard issue, or run the Release PR workflow.';
    private const OVERRIDES = 'The overrides in the description are not the ones this pull request was built with. Wait for the rebuild to finish, or read its run for the error.';
    private const TICK = 'Read the breaking changes in the description, then tick the box.';

    private string $message = "Release 2026-11-21\n\nBuilt-On: " . self::MAIN . "\n";
    /** @var list<string> */
    private array $parents = [self::MAIN];
    private ?string $body = "Releases x.\n\n```overrides\n```\n";
    /** @var list<RequestInterface> */
    private array $requests = [];
    private int $commitStatus = 200;
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

    public function test_a_release_built_on_main_passes(): void
    {
        $this->assertSame(Command::SUCCESS, $this->run_command());

        $this->assertStringContainsString('#7 is built on the tip of main, with the overrides in its description', $this->tester->getDisplay());
        $this->assertSame(self::API . '/commits/' . self::HEAD, (string) $this->requests[0]->getUri());
        $this->assertSame('Bearer read-token', $this->requests[0]->getHeaderLine('Authorization'));
        $this->assertSame([], array_filter($this->requests, static fn (RequestInterface $request): bool => $request->getMethod() !== 'GET'));
    }

    public function test_a_null_description_is_an_empty_one(): void
    {
        $this->body = null;

        $this->assertSame(Command::SUCCESS, $this->run_command());
    }

    public function test_a_ticked_box_for_the_breaking_changes_passes(): void
    {
        $this->breaking(ReleaseDescription::APPROVED, self::HASH);

        $this->assertSame(Command::SUCCESS, $this->run_command());

        $this->assertStringContainsString('The breaking changes are approved', $this->tester->getDisplay());
    }

    public function test_a_box_ticked_by_hand_passes(): void
    {
        $this->breaking('* [X]  I have read the breaking changes', self::HASH);

        $this->assertSame(Command::SUCCESS, $this->run_command());
    }

    public function test_an_unticked_box_fails(): void
    {
        $this->breaking(ReleaseDescription::APPROVAL, self::HASH);

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString(self::TICK, $this->tester->getDisplay());
    }

    public function test_a_ticked_box_without_its_marker_fails(): void
    {
        $this->message .= 'Breaking-Changes: ' . self::HASH . "\n";
        $this->body = ReleaseDescription::APPROVED . "\n\n```overrides\n```\n";

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString(self::TICK, $this->tester->getDisplay());
    }

    public function test_a_description_without_the_section_fails(): void
    {
        $this->message .= 'Breaking-Changes: ' . self::HASH . "\n";

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString(self::TICK, $this->tester->getDisplay());
    }

    public function test_a_tick_for_an_older_build_fails(): void
    {
        $this->breaking(ReleaseDescription::APPROVED, '0000000000000000');
        $this->message = str_replace('Breaking-Changes: 0000000000000000', 'Breaking-Changes: ' . self::HASH, $this->message);

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString(self::TICK, $this->tester->getDisplay());
    }

    public function test_a_head_without_trailers_fails(): void
    {
        $this->message = 'Release 2026-11-21';

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString(self::NOT_BUILT, $this->tester->getDisplay());
        $this->assertStringNotContainsString(self::NOT_ON_MAIN, $this->tester->getDisplay());
    }

    public function test_a_malformed_breaking_changes_trailer_fails(): void
    {
        $this->message .= "Breaking-Changes: not-a-hash\n";

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString(self::NOT_BUILT, $this->tester->getDisplay());
    }

    public function test_a_head_behind_main_fails(): void
    {
        $this->message = str_replace(self::MAIN, self::OTHER, $this->message);
        $this->parents = [self::OTHER];

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString(self::NOT_ON_MAIN, $this->tester->getDisplay());
    }

    public function test_a_merge_of_main_fails(): void
    {
        $this->parents = [self::MAIN, self::OTHER];

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString(self::NOT_ON_MAIN, $this->tester->getDisplay());
    }

    public function test_a_rebase_onto_main_fails(): void
    {
        $this->message = str_replace(self::MAIN, self::OTHER, $this->message);

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString(self::NOT_ON_MAIN, $this->tester->getDisplay());
    }

    public function test_overrides_changed_since_the_build_fail(): void
    {
        $this->message .= "Override: sdk=1.16.1\n";
        $this->body = "Releases x.\n\n```overrides\nsdk=skip\n```\n";

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString(self::OVERRIDES, $this->tester->getDisplay());
    }

    public function test_an_override_added_since_the_build_fails(): void
    {
        $this->body = "Releases x.\n\n```overrides\nsdk=skip\n```\n";

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString(self::OVERRIDES, $this->tester->getDisplay());
    }

    public function test_an_override_removed_since_the_build_fails(): void
    {
        $this->message .= "Override: sdk=skip\n";

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString(self::OVERRIDES, $this->tester->getDisplay());
    }

    public function test_overrides_are_compared_as_release_pr_reads_them(): void
    {
        $this->message .= "Override: sdk=skip\nOverride: api=1.12.0\n";
        $this->body = "Releases x.\r\n\r\n```overrides \r\n  sdk=skip  \r\n\r\napi=1.12.0\r\n```\r\n";

        $this->assertSame(Command::SUCCESS, $this->run_command());
    }

    public function test_each_problem_is_reported(): void
    {
        $this->parents = [self::OTHER];
        $this->breaking(ReleaseDescription::APPROVAL, self::HASH);
        $this->body = str_replace("```overrides\n", "```overrides\nsdk=skip\n", (string) $this->body);

        $this->assertSame(Command::FAILURE, $this->run_command());

        $display = $this->tester->getDisplay();
        $this->assertStringContainsString(self::NOT_ON_MAIN, $display);
        $this->assertStringContainsString(self::OVERRIDES, $display);
        $this->assertStringContainsString(self::TICK, $display);
    }

    public function test_errors_are_annotated_under_github_actions(): void
    {
        $this->parents = [self::OTHER];
        putenv('GITHUB_ACTIONS=true');

        try {
            $this->run_command();
        } finally {
            putenv('GITHUB_ACTIONS');
        }

        $this->assertStringContainsString('::error::' . self::NOT_ON_MAIN, $this->tester->getDisplay());
    }

    public function test_contrib_is_looked_up_in_contrib(): void
    {
        $this->run_command(['--repository' => 'open-telemetry/opentelemetry-php-contrib']);

        $this->assertStringStartsWith('https://api.github.com/repos/open-telemetry/opentelemetry-php-contrib/', (string) $this->requests[0]->getUri());
    }

    public function test_the_repository_must_be_a_monorepo(): void
    {
        $this->assertSame(Command::FAILURE, $this->run_command(['--repository' => 'someone/else']));

        $this->assertStringContainsString('--repository must be open-telemetry/opentelemetry-php or open-telemetry/opentelemetry-php-contrib', $this->tester->getDisplay());
        $this->assertSame([], $this->requests);
    }

    public function test_the_number_must_be_a_pull_request_number(): void
    {
        $this->assertSame(Command::FAILURE, $this->run_command(['--number' => '7/../../x']));

        $this->assertStringContainsString('--number must be the number of the pull request', $this->tester->getDisplay());
        $this->assertSame([], $this->requests);
    }

    public function test_the_head_must_be_a_commit_sha(): void
    {
        $this->assertSame(Command::FAILURE, $this->run_command(['--head' => 'main']));

        $this->assertStringContainsString('--head must be the full sha of the pull request\'s head commit', $this->tester->getDisplay());
        $this->assertSame([], $this->requests);
    }

    public function test_a_token_is_needed(): void
    {
        $this->assertSame(Command::FAILURE, $this->run_command(['--token' => null]));

        $this->assertStringContainsString('needs a GitHub token', $this->tester->getDisplay());
        $this->assertSame([], $this->requests);
    }

    public function test_a_failed_github_request_fails(): void
    {
        $this->commitStatus = 500;

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString('500', $this->tester->getDisplay());
    }

    /**
     * Adds a Breaking changes section with $box and the marker for $hash, and the trailer for $hash.
     */
    private function breaking(string $box, string $hash): void
    {
        $this->message .= "Breaking-Changes: {$hash}\n";
        $this->body = "Releases x.\n\n### Breaking changes\n\n{$box}\n<!-- breaking: {$hash} -->\n\n```overrides\n```\n";
    }

    private function respond(RequestInterface $request): Response
    {
        $this->requests[] = $request;
        $url = (string) $request->getUri();

        return match (true) {
            str_ends_with($url, '/commits/' . self::HEAD) => new Response($this->commitStatus, [], json_encode([
                'commit' => ['message' => $this->message],
                'parents' => array_map(static fn (string $sha): array => ['sha' => $sha], $this->parents),
            ])),
            str_ends_with($url, '/git/ref/heads/main') => new Response(200, [], json_encode(['ref' => 'refs/heads/main', 'object' => ['sha' => self::MAIN]])),
            str_ends_with($url, '/pulls/7') => new Response(200, [], json_encode(['title' => 'Release 2026-11-21', 'body' => $this->body])),
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
        $this->tester = new CommandTester(new ReleasePrCheckCommand(new GitHub($client)));
        $input = array_filter(
            $input + ['--repository' => self::REPO, '--number' => '7', '--head' => self::HEAD, '--token' => 'read-token'],
            static fn (mixed $value): bool => $value !== null,
        );

        return $this->tester->execute($input);
    }
}
