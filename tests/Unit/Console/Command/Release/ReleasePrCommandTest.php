<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Command\Release;

use Nyholm\Psr7\Response;
use OpenTelemetry\DevTools\Console\Command\Release\ReleasePrCommand;
use OpenTelemetry\DevTools\Console\Release\GitHub;
use OpenTelemetry\DevTools\Console\Release\Overrides;
use OpenTelemetry\DevTools\Console\Release\ReleaseDescription;
use OpenTelemetry\DevTools\Package\Git\GitRunner;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsTrait;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Release\ReleasePrCommand
 * @covers \OpenTelemetry\DevTools\Console\Release\CapturedOutput
 */
class ReleasePrCommandTest extends TestCase
{
    use UsesVfsTrait;

    private const REPO = 'open-telemetry/opentelemetry-php';
    private const API = 'https://api.github.com/repos/' . self::REPO;
    private const PULLS = self::API . '/pulls?state=open&head=open-telemetry%3Aotelbot%2Frelease&per_page=100';
    private const BOT = 'otelbot-php[bot]';
    private const DATE = '2026-11-14';
    private const SHA = '841cc9a1b2c3d4e5f60718293a4b5c6d7e8f9012';
    private const COMMIT = 'c0ffee0000000000000000000000000000000000';
    private const LEASE = 'bead000000000000000000000000000000000000';
    /** the commit message for prep(), in tag order */
    private const DATE_MESSAGE = "Release 2026-11-14\n\nopen-telemetry/sdk 2.0.0\nopen-telemetry/api 1.11.0\n";
    private const MARKDOWN = "| Package | Latest | Suggested | Reason |\n|---|---|---|---|\n| opentelemetry-php/api | 1.10.1 | 1.11.0 | Added |\n\nTag in this order: open-telemetry/api\n[DRY-RUN] no files were changed\n";

    /** @var list<array{number: int, body: string, user?: string}> the open pull requests */
    private array $pulls = [];
    /** the body of the pull request when it is read again, null for the same as listed */
    private ?string $current = null;
    private string $currentTitle = 'Release ' . self::DATE;
    private string $permission = 'write';
    /** @var array<string, Response> by `METHOD url`, ahead of the defaults */
    private array $responses = [];
    /** @var list<RequestInterface> */
    private array $requests = [];
    /** @var list<string> GitHub writes and git pushes, in order */
    private array $events = [];
    /** @var list<InputInterface> inputs of each release:prep run */
    private array $prepInputs = [];
    /** @var array<string, int> exit status of release:prep, by format */
    private array $prepStatus = ['markdown' => Command::SUCCESS, 'json' => Command::SUCCESS];
    /** @var array<string, mixed> the JSON of release:prep, decoded */
    private array $prep;
    /** @var list<array{0: string, 1: string, 2: string}> message, name and email of each commit */
    private array $commits = [];
    /** sha of otelbot/release on GitHub, null for no branch */
    private ?string $remote = null;
    private string $remoteParent = self::SHA;
    private bool $sameTree = false;
    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: ?string}> arguments of each push */
    private array $pushes = [];
    private CommandTester $tester;
    /** the --repository of the run */
    private string $repository = self::REPO;
    private string|false $environmentToken;

    #[\Override]
    public function setUp(): void
    {
        $this->setUpVcs();
        $this->prep = self::prep();
        $this->environmentToken = getenv('GITHUB_TOKEN');
        putenv('GITHUB_TOKEN');
    }

    #[\Override]
    public function tearDown(): void
    {
        putenv($this->environmentToken === false ? 'GITHUB_TOKEN' : "GITHUB_TOKEN={$this->environmentToken}");
    }

    public function test_a_release_without_a_pull_request_opens_one(): void
    {
        $this->assertSame(Command::SUCCESS, $this->run_command());

        $this->assertSame([[self::DATE_MESSAGE, self::BOT, '356036745+otelbot-php[bot]@users.noreply.github.com']], $this->commits);
        $this->assertSame([[$this->root->url(), 'https://github.com/' . self::REPO . '.git', 'otelbot/release', '', 'app-token']], $this->pushes);
        $this->assertSame(['push', 'POST ' . self::API . '/pulls'], $this->events);
        $this->assertSame([
            'title' => 'Release ' . self::DATE,
            'head' => 'otelbot/release',
            'base' => 'main',
            'body' => $this->description(),
        ], $this->sent('POST', self::API . '/pulls'));
        $this->assertStringContainsString('https://github.com/' . self::REPO . '/pull/7', $this->tester->getDisplay());
    }

    public function test_prep_runs_twice_without_interaction_with_the_date_and_each_override(): void
    {
        $this->pulls = [['number' => 7, 'body' => self::body(['api=1.11.0', 'sdk=2.0.0'])]];

        $this->assertSame(Command::SUCCESS, $this->run_command());

        $this->assertCount(2, $this->prepInputs);
        [$markdown, $json] = $this->prepInputs;
        foreach ($this->prepInputs as $input) {
            $this->assertFalse($input->isInteractive());
            $this->assertSame($this->root->url(), $input->getOption('path'));
            $this->assertSame(self::DATE, $input->getOption('date'));
            $this->assertSame(['api=1.11.0', 'sdk=2.0.0'], $input->getOption('set'));
            $this->assertSame('app-token', $input->getOption('token'));
        }
        $this->assertTrue($markdown->getOption('dry-run'));
        $this->assertSame('markdown', $markdown->getOption('format'));
        $this->assertFalse($json->getOption('dry-run'));
        $this->assertSame('json', $json->getOption('format'));
    }

    public function test_the_progress_of_the_json_prep_is_shown_and_kept_out_of_the_json(): void
    {
        $this->assertSame(Command::SUCCESS, $this->run_command());

        $this->assertStringContainsString('prep progress', $this->tester->getDisplay());
    }

    public function test_the_date_defaults_to_today_in_utc(): void
    {
        $this->run_command(['--date' => null]);

        $this->assertSame(gmdate('Y-m-d'), $this->prepInputs[0]->getOption('date'));
    }

    public function test_more_than_one_pull_request_fails(): void
    {
        $this->pulls = [['number' => 7, 'body' => ''], ['number' => 8, 'body' => '']];

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString(self::REPO . ' has more than one open release pull request', $this->tester->getDisplay());
        $this->assertSame([], $this->prepInputs);
    }

    public function test_pull_requests_by_others_are_not_the_release_pull_request(): void
    {
        $this->pulls = [['number' => 8, 'body' => '', 'user' => 'someone']];

        $this->assertSame(Command::SUCCESS, $this->run_command());

        $this->assertNotNull($this->sent('POST', self::API . '/pulls'));
    }

    public function test_rebuild_only_without_a_pull_request_does_nothing(): void
    {
        $this->assertSame(Command::SUCCESS, $this->run_command($this->rebuild('')));

        $this->assertStringContainsString(self::REPO . ' has no open release pull request to rebuild', $this->tester->getDisplay());
        $this->assertSame([], $this->prepInputs);
    }

    public function test_rebuild_only_with_the_same_overrides_does_nothing(): void
    {
        $this->pulls = [['number' => 7, 'body' => "Edited text\n\n" . self::body(['api=1.11.0'])]];

        $this->assertSame(Command::SUCCESS, $this->run_command($this->rebuild(self::body(['  api=1.11.0']))));

        $this->assertStringContainsString('The overrides are unchanged', $this->tester->getDisplay());
        $this->assertSame([], $this->prepInputs);
    }

    public function test_rebuild_only_with_changed_overrides_rebuilds(): void
    {
        $this->pulls = [['number' => 7, 'body' => self::body(['api=1.12.0'])]];

        $this->assertSame(Command::SUCCESS, $this->run_command($this->rebuild(self::body(['api=1.11.0']))));

        $this->assertCount(2, $this->prepInputs);
    }

    public function test_rebuild_only_needs_the_previous_body(): void
    {
        $this->assertSame(Command::FAILURE, $this->run_command(['--rebuild-only' => true]));

        $this->assertStringContainsString('--rebuild-only needs --previous-body-file', $this->tester->getDisplay());
    }

    public function test_a_previous_body_file_that_cannot_be_read_fails(): void
    {
        $this->assertSame(Command::FAILURE, $this->run_command(['--rebuild-only' => true, '--previous-body-file' => $this->root->url() . '/missing.md']));

        $this->assertStringContainsString('missing.md', $this->tester->getDisplay());
    }

    public function test_a_sender_without_write_access_fails(): void
    {
        $this->permission = 'read';

        $this->assertSame(Command::FAILURE, $this->run_command(['--sender' => 'mallory']));

        $this->assertStringContainsString('mallory has read access to ' . self::REPO . ', not write', $this->tester->getDisplay());
        $this->assertSame([], $this->prepInputs);
        $this->assertSame(self::API . '/collaborators/mallory/permission', (string) $this->requests[1]->getUri());
    }

    public function test_a_sender_with_admin_or_write_access_passes(): void
    {
        foreach (['admin', 'write'] as $permission) {
            $this->permission = $permission;

            $this->assertSame(Command::SUCCESS, $this->run_command(['--sender' => 'maintainer']), $permission);
        }
    }

    public function test_without_a_sender_no_permission_is_checked(): void
    {
        $this->assertSame(Command::SUCCESS, $this->run_command());

        $this->assertSame([], array_filter($this->requests, static fn (RequestInterface $request): bool => str_contains((string) $request->getUri(), '/collaborators/')));
    }

    public function test_invalid_overrides_fail_and_are_listed(): void
    {
        $this->pulls = [['number' => 7, 'body' => self::body(['api=banana', 'sdk=1.0.0', '$(id)=skip'])]];

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString('Each override must be <package>=<version|skip>. Not valid: api=banana, $(id)=skip', $this->tester->getDisplay());
        $this->assertSame([], $this->prepInputs);
    }

    public function test_errors_are_annotated_under_github_actions(): void
    {
        $this->pulls = [['number' => 7, 'body' => ''], ['number' => 8, 'body' => '']];
        putenv('GITHUB_ACTIONS=true');

        try {
            $this->run_command();
        } finally {
            putenv('GITHUB_ACTIONS');
        }

        $this->assertStringContainsString('::error::' . self::REPO . ' has more than one open release pull request', $this->tester->getDisplay());
    }

    public function test_nothing_to_release_closes_the_pull_request(): void
    {
        $this->pulls = [['number' => 7, 'body' => self::body([])]];
        $this->prep['release'] = false;

        $this->assertSame(Command::SUCCESS, $this->run_command());

        $this->assertSame(['body' => 'Nothing to release from `main` at ' . self::SHA . '.'], $this->sent('POST', self::API . '/issues/7/comments'));
        $this->assertSame(['state' => 'closed'], $this->sent('PATCH', self::API . '/pulls/7'));
        $this->assertContains('DELETE ' . self::API . '/git/refs/heads/otelbot/release', $this->events);
        $this->assertSame([], $this->commits);
        $this->assertSame([], $this->pushes);
    }

    public function test_nothing_to_release_without_a_pull_request_does_nothing(): void
    {
        $this->prep['release'] = false;

        $this->assertSame(Command::SUCCESS, $this->run_command());

        $this->assertSame([], $this->events);
        $this->assertSame([], $this->commits);
        $this->assertStringContainsString('Nothing to release from main at ' . self::SHA, $this->tester->getDisplay());
    }

    public function test_a_failed_dry_run_fails_showing_its_output(): void
    {
        $this->prepStatus['markdown'] = Command::FAILURE;

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString('markdown prep failed', $this->tester->getDisplay());
        $this->assertCount(1, $this->prepInputs);
        $this->assertSame([], $this->commits);
    }

    public function test_a_failed_prep_fails(): void
    {
        $this->prepStatus['json'] = Command::FAILURE;

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertSame([], $this->commits);
        $this->assertSame([], $this->events);
    }

    public function test_the_lease_is_the_branch_as_fetched(): void
    {
        $this->remote = self::LEASE;

        $this->assertSame(Command::SUCCESS, $this->run_command());

        $this->assertSame(self::LEASE, $this->pushes[0][3]);
    }

    public function test_the_same_release_on_the_same_main_is_not_pushed(): void
    {
        $this->remote = self::LEASE;
        $this->sameTree = true;

        $this->assertSame(Command::SUCCESS, $this->run_command());

        $this->assertSame([], $this->pushes);
    }

    public function test_the_same_release_on_another_main_is_pushed(): void
    {
        $this->remote = self::LEASE;
        $this->remoteParent = 'older';
        $this->sameTree = true;

        $this->assertSame(Command::SUCCESS, $this->run_command());

        $this->assertCount(1, $this->pushes);
    }

    public function test_an_existing_pull_request_is_updated_before_the_push(): void
    {
        $previous = self::body(['api=1.11.0']);
        $this->pulls = [['number' => 7, 'body' => $previous]];
        $this->remote = self::LEASE;
        $this->currentTitle = 'Release 2026-11-01';

        $this->assertSame(Command::SUCCESS, $this->run_command());

        $this->assertSame(['PATCH ' . self::API . '/pulls/7', 'push'], $this->events);
        $this->assertSame(['title' => 'Release ' . self::DATE, 'body' => $this->description($previous, ['api=1.11.0'])], $this->sent('PATCH', self::API . '/pulls/7'));
        $this->assertNull($this->sent('POST', self::API . '/pulls'));
    }

    public function test_an_up_to_date_description_is_not_edited(): void
    {
        $description = $this->description();
        $this->pulls = [['number' => 7, 'body' => $description]];

        $this->assertSame(Command::SUCCESS, $this->run_command());

        $this->assertSame(['push'], $this->events);
    }

    public function test_a_description_edited_during_the_run_fails_without_pushing(): void
    {
        $this->pulls = [['number' => 7, 'body' => self::body([])]];
        $this->current = self::body(['api=1.12.0']);

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString('The description of #7 changed during this run. The run for that edit rebuilds the pull request.', $this->tester->getDisplay());
        $this->assertSame([], $this->events);
    }

    public function test_the_stale_note_is_not_an_edit(): void
    {
        $this->pulls = [['number' => 7, 'body' => self::body([])]];
        $this->current = ReleaseDescription::markStale(str_replace("\n", "\r\n", self::body([])));

        $this->assertSame(Command::SUCCESS, $this->run_command());

        $this->assertSame(['PATCH ' . self::API . '/pulls/7', 'push'], $this->events);
    }

    public function test_contrib_is_rebuilt_by_hand(): void
    {
        $this->assertSame(Command::SUCCESS, $this->run_command(['--repository' => 'open-telemetry/opentelemetry-php-contrib']));

        $this->assertStringContainsString('Then rebuild it.', $this->sent('POST', 'https://api.github.com/repos/open-telemetry/opentelemetry-php-contrib/pulls')['body'] ?? '');
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
        $this->assertSame([], $this->requests);
    }

    public function test_the_date_must_be_a_date(): void
    {
        $this->assertSame(Command::FAILURE, $this->run_command(['--date' => '2026-02-30']));

        $this->assertStringContainsString('--date must be YYYY-MM-DD', $this->tester->getDisplay());
    }

    public function test_a_failed_github_request_fails(): void
    {
        $this->responses['GET ' . self::PULLS] = new Response(500, [], '{"message":"Server Error"}');

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString('500', $this->tester->getDisplay());
    }

    public function test_a_failed_push_fails(): void
    {
        $this->remote = 'moved';

        $this->assertSame(Command::FAILURE, $this->run_command());

        $this->assertStringContainsString('stale info', $this->tester->getDisplay());
        $this->assertNull($this->sent('POST', self::API . '/pulls'));
    }

    /**
     * @return array<string, mixed>
     */
    private function rebuild(string $previous): array
    {
        $file = $this->root->url() . '/previous.md';
        file_put_contents($file, $previous);

        return ['--rebuild-only' => true, '--previous-body-file' => $file];
    }

    /**
     * The description the command should write.
     *
     * @param list<string> $overrides
     */
    private function description(string $previous = '', array $overrides = []): string
    {
        return (new ReleaseDescription(self::REPO, self::SHA, true))->render(self::MARKDOWN, $this->prep, $previous, new Overrides($overrides));
    }

    /**
     * @param list<string> $overrides
     */
    private static function body(array $overrides): string
    {
        return "Releases.\n\n" . Overrides::FENCE . "\n" . implode('', array_map(static fn (string $line): string => "{$line}\n", $overrides)) . "```\n";
    }

    /**
     * @return array<string, mixed>
     */
    private static function prep(): array
    {
        return [
            'packages' => [
                ['package' => 'open-telemetry/api', 'repository' => 'opentelemetry-php/api', 'latest' => '1.10.1', 'version' => '1.11.0', 'reason' => 'Added', 'warnings' => [], 'undecided' => false, 'changes' => []],
                ['package' => 'open-telemetry/sdk', 'repository' => 'opentelemetry-php/sdk', 'latest' => '1.15.0', 'version' => '2.0.0', 'reason' => 'Breaking', 'warnings' => ['Dropped X.'], 'undecided' => false, 'changes' => []],
                ['package' => 'open-telemetry/context', 'repository' => 'opentelemetry-php/context', 'latest' => '1.0.0', 'version' => null, 'reason' => 'no unreleased changes', 'warnings' => [], 'undecided' => false, 'changes' => []],
            ],
            'order' => ['open-telemetry/sdk', 'open-telemetry/api'],
            'constraint_warnings' => [],
            'release' => true,
            'completed' => true,
        ];
    }

    /**
     * The decoded body of the last $method request to $url, or null when there was none.
     *
     * @return array<string, mixed>|null
     */
    private function sent(string $method, string $url): ?array
    {
        $body = null;
        foreach ($this->requests as $request) {
            if ($request->getMethod() === $method && (string) $request->getUri() === $url) {
                $body = json_decode((string) $request->getBody(), true);
            }
        }

        return $body;
    }

    private function respond(RequestInterface $request): Response
    {
        $method = $request->getMethod();
        $url = (string) $request->getUri();
        $this->requests[] = $request;
        $method === 'GET' || $this->events[] = "{$method} {$url}";
        if (isset($this->responses["{$method} {$url}"])) {
            return $this->responses["{$method} {$url}"];
        }
        $api = 'https://api.github.com/repos/' . $this->repository;

        return match (true) {
            $method === 'GET' && str_starts_with($url, $api . '/pulls?') => new Response(200, [], json_encode(array_map(static fn (array $pull): array => [
                'number' => $pull['number'],
                'body' => $pull['body'],
                'user' => ['login' => $pull['user'] ?? self::BOT],
                'head' => ['sha' => self::LEASE],
            ], $this->pulls))),
            $method === 'GET' && preg_match('#/pulls/(\d+)$#', $url) === 1 => new Response(200, [], json_encode([
                'title' => $this->currentTitle,
                'body' => $this->current ?? $this->pulls[0]['body'],
            ])),
            $method === 'GET' && str_contains($url, '/collaborators/') => new Response(200, [], json_encode(['permission' => $this->permission])),
            $method === 'PATCH' => new Response(200, [], json_encode(['head' => ['ref' => 'otelbot/release', 'repo' => ['full_name' => $this->repository]]])),
            $method === 'POST' && str_ends_with($url, '/comments') => new Response(201, [], '{}'),
            $method === 'POST' && str_ends_with($url, '/pulls') => new Response(201, [], json_encode(['html_url' => "https://github.com/{$this->repository}/pull/7"])),
            $method === 'DELETE' => new Response(204),
            default => new Response(404, [], '{"message":"Not Found"}'),
        };
    }

    /**
     * A release:prep that answers with $this->prep, in the format asked for.
     */
    private function fakePrep(): Command
    {
        return (new Command('release:prep'))
            ->addOption('path', null, InputOption::VALUE_REQUIRED)
            ->addOption('token', null, InputOption::VALUE_REQUIRED)
            ->addOption('date', null, InputOption::VALUE_REQUIRED)
            ->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY)
            ->addOption('dry-run', null, InputOption::VALUE_NONE)
            ->addOption('format', null, InputOption::VALUE_REQUIRED)
            ->setCode(function (InputInterface $input, OutputInterface $output): int {
                $this->prepInputs[] = $input;
                $format = $input->getOption('format');
                if ($format === 'markdown') {
                    $output->write($this->prepStatus['markdown'] === Command::SUCCESS ? self::MARKDOWN : "<error>markdown prep failed</error>\n");

                    return $this->prepStatus['markdown'];
                }
                $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
                $errors->writeln('prep progress');
                $output->writeln(json_encode($this->prep + ['completed' => $this->prepStatus['json'] === Command::SUCCESS], JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

                return $this->prepStatus['json'];
            });
    }

    /**
     * @param array<string, mixed> $input
     */
    private function run_command(array $input = []): int
    {
        $this->requests = $this->events = $this->prepInputs = $this->commits = $this->pushes = [];
        $input += ['--path' => $this->root->url(), '--repository' => self::REPO, '--token' => 'app-token', '--date' => self::DATE];
        $input = array_filter($input, static fn (mixed $value): bool => $value !== null);
        $this->repository = $input['--repository'];

        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(fn (RequestInterface $request): Response => $this->respond($request));
        $git = $this->createMock(GitRunner::class);
        $git->method('head')->willReturn(self::SHA);
        $git->method('commitAll')->willReturnCallback(function (string $path, string $message, string $name, string $email): string {
            $this->commits[] = [$message, $name, $email];

            return self::COMMIT;
        });
        $git->method('fetch')->willReturnCallback(fn (): ?string => $this->remote);
        $git->method('parent')->willReturnCallback(fn (string $path, string $sha): ?string => $sha === self::LEASE ? $this->remoteParent : null);
        $git->method('sameTree')->willReturnCallback(fn (string $path, string $a, string $b): bool => [$a, $b] === [self::LEASE, self::COMMIT] && $this->sameTree);
        $git->method('push')->willReturnCallback(function (string ...$args): void {
            if ($this->remote === 'moved') {
                throw new RuntimeException('! [rejected] HEAD -> otelbot/release (stale info)');
            }
            $this->pushes[] = $args;
            $this->events[] = 'push';
        });

        $this->tester = new CommandTester(new ReleasePrCommand(new GitHub($client), $git, fn (): Command => $this->fakePrep()));

        return $this->tester->execute($input);
    }
}
