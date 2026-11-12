<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Command\Php;

use Nyholm\Psr7\Response;
use OpenTelemetry\DevTools\Console\Command\Php\PhpSupportIssuesCommand;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\FakeHttpClient;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesPhpStatesTrait;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsConstants;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsTrait;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Php\PhpSupportIssuesCommand
 */
class PhpSupportIssuesCommandTest extends TestCase
{
    use UsesPhpStatesTrait;
    use UsesVfsTrait;

    private const DROP_TITLE = 'Drop support for PHP 8.2 (support ends 2027-12-31)';
    private const REPO = 'open-telemetry/opentelemetry-php';

    private string $path;

    #[\Override]
    public function setUp(): void
    {
        $this->setUpVcs();
        $this->path = vfsStream::url(UsesVfsConstants::ROOT_DIR);
        file_put_contents("{$this->path}/composer.json", '{"require": {"php": "^8.2"}}');
        mkdir("{$this->path}/.github/workflows", 0777, true);
        file_put_contents("{$this->path}/.github/workflows/php.yml", "jobs:\n  php:\n    strategy:\n      matrix:\n        php-version: ['8.2', '8.3', '8.4', '8.5', '8.6']\n");
        mkdir("{$this->path}/.github/php-version-support", 0777, true);
        $this->template('drop.md', "---\ntitle: \"Drop support for PHP {{ version }} (support ends {{ supported_until }})\"\nlabels: [php-version-support]\n---\nPHP {{ version }} stopped receiving security fixes on {{ security_support_end }}.\n\n- [ ] Raise composer.json\n");
        $this->template('add.md', "---\ntitle: Add PHP {{ version }} to CI\nlabels: [php-version-support]\n---\nPHP {{ version }} was released on {{ released }}.\n");
    }

    public function test_it_opens_nothing_when_the_versions_are_in_line_with_the_policy(): void
    {
        $client = $this->client();
        $tester = new CommandTester(new PhpSupportIssuesCommand($client));

        $status = $tester->execute(['--path' => $this->path, '--date' => '2026-11-05', '--repo' => self::REPO, '--token' => 'secret']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('nothing to open', $tester->getDisplay());
        $this->assertCount(1, $client->requests, 'only php.net was asked');
    }

    public function test_a_dry_run_prints_the_issue_without_contacting_github(): void
    {
        $client = $this->client();
        $tester = new CommandTester(new PhpSupportIssuesCommand($client));

        $status = $tester->execute(['--path' => $this->path, '--date' => '2027-11-15', '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Would open an issue: ' . self::DROP_TITLE . ' [php-version-support]', $display);
        $this->assertStringContainsString('PHP 8.2 stopped receiving security fixes on 2026-12-31.', $display);
        $this->assertStringContainsString('- [ ] Raise composer.json', $display);
        $this->assertCount(1, $client->requests, 'only php.net was asked');
    }

    public function test_it_opens_the_issue(): void
    {
        $client = $this->client([
            $this->issues([]),
            $this->label(),
            new Response(201, [], (string) json_encode(['html_url' => 'https://github.com/o/r/issues/1'])),
        ]);
        $tester = new CommandTester(new PhpSupportIssuesCommand($client));

        $status = $tester->execute(['--path' => $this->path, '--date' => '2027-11-15', '--repo' => self::REPO, '--token' => 'secret']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('Opened https://github.com/o/r/issues/1', $tester->getDisplay());
        $this->assertCount(4, $client->requests);
        $listing = $client->requests[1];
        $this->assertSame('/repos/open-telemetry/opentelemetry-php/issues', $listing->getUri()->getPath());
        $this->assertStringContainsString('labels=php-version-support', $listing->getUri()->getQuery());
        $this->assertSame('Bearer secret', $listing->getHeaderLine('Authorization'));
        $issue = json_decode((string) $client->requests[3]->getBody(), true);
        $this->assertSame(self::DROP_TITLE, $issue['title']);
        $this->assertSame(['php-version-support'], $issue['labels']);
        $this->assertStringContainsString('PHP 8.2 stopped receiving security fixes', $issue['body']);
    }

    public function test_it_does_not_open_an_issue_that_exists(): void
    {
        $client = $this->client([$this->issues([self::DROP_TITLE, 'Something else'])]);
        $tester = new CommandTester(new PhpSupportIssuesCommand($client));

        $status = $tester->execute(['--path' => $this->path, '--date' => '2027-11-15', '--repo' => self::REPO, '--token' => 'secret']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('Issue already exists: ' . self::DROP_TITLE, $tester->getDisplay());
        $this->assertCount(2, $client->requests, 'php.net and the list of issues, no new issue');
    }

    public function test_it_opens_an_issue_for_each_thing_to_do(): void
    {
        file_put_contents("{$this->path}/.github/workflows/php.yml", "jobs:\n  php:\n    strategy:\n      matrix:\n        php-version: ['8.2', '8.3', '8.4', '8.5']\n");
        $created = new Response(201, [], '{"html_url": "https://github.com/o/r/issues/1"}');
        $client = $this->client([$this->issues([]), $this->label(), $created, $this->label(), $created]);
        $tester = new CommandTester(new PhpSupportIssuesCommand($client));

        $tester->execute(['--path' => $this->path, '--date' => '2027-11-15', '--repo' => self::REPO, '--token' => 'secret']);

        $titles = array_map(
            static fn ($request): ?string => json_decode((string) $request->getBody(), true)['title'] ?? null,
            array_filter($client->requests, static fn ($request): bool => str_ends_with($request->getUri()->getPath(), '/issues') && $request->getMethod() === 'POST'),
        );
        $this->assertSame([self::DROP_TITLE, 'Add PHP 8.6 to CI'], array_values($titles));
        $listings = array_filter($client->requests, static fn ($request): bool => $request->getMethod() === 'GET' && str_contains($request->getUri()->getPath(), '/issues'));
        $this->assertCount(1, $listings, 'the issues with the label are listed once');
    }

    public function test_it_opens_no_issue_when_a_later_template_is_broken(): void
    {
        file_put_contents("{$this->path}/.github/workflows/php.yml", "jobs:\n  php:\n    strategy:\n      matrix:\n        php-version: ['8.2', '8.3', '8.4', '8.5']\n");
        $this->template('add.md', "---\ntitle: Add PHP {{ versoin }}\nlabels: [x]\n---\nBody\n");
        $client = $this->client();
        $tester = new CommandTester(new PhpSupportIssuesCommand($client));

        $status = $tester->execute(['--path' => $this->path, '--date' => '2027-11-15', '--repo' => self::REPO, '--token' => 'secret']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('unknown placeholder {{ versoin }}', $tester->getDisplay());
        $this->assertCount(1, $client->requests, 'only php.net was asked, the drop issue was not opened');
    }

    public function test_it_reads_the_templates_from_the_given_directory(): void
    {
        mkdir("{$this->path}/templates");
        file_put_contents("{$this->path}/templates/drop.md", "---\ntitle: Custom {{ version }}\nlabels: [x]\n---\nBody\n");
        $tester = new CommandTester(new PhpSupportIssuesCommand($this->client()));

        $tester->execute(['--path' => $this->path, '--date' => '2027-11-15', '--dry-run' => true, '--templates' => 'templates/']);

        $this->assertStringContainsString('Would open an issue: Custom 8.2 [x]', $tester->getDisplay());
    }

    public function test_a_dry_run_shows_the_text_as_it_is_even_when_it_looks_like_a_style_tag(): void
    {
        $this->template('drop.md', "---\ntitle: Drop {{ version }}\nlabels: [x]\n---\nRun `<info>otel</info>` and read <error>this</error>.\n");
        $tester = new CommandTester(new PhpSupportIssuesCommand($this->client()));

        $tester->execute(['--path' => $this->path, '--date' => '2027-11-15', '--dry-run' => true]);

        $this->assertStringContainsString('Run `<info>otel</info>` and read <error>this</error>.', $tester->getDisplay());
    }

    public function test_it_fails_on_a_template_without_a_label(): void
    {
        $this->template('drop.md', "---\ntitle: Drop {{ version }}\n---\nBody\n");
        $tester = new CommandTester(new PhpSupportIssuesCommand($this->client()));

        $status = $tester->execute(['--path' => $this->path, '--date' => '2027-11-15', '--dry-run' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('drop.md needs at least one label', $tester->getDisplay());
    }

    public function test_it_fails_on_a_placeholder_that_does_not_exist(): void
    {
        $this->template('drop.md', "---\ntitle: Drop {{ versoin }}\nlabels: [x]\n---\nBody\n");
        $tester = new CommandTester(new PhpSupportIssuesCommand($this->client()));

        $status = $tester->execute(['--path' => $this->path, '--date' => '2027-11-15', '--dry-run' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('unknown placeholder {{ versoin }}', $tester->getDisplay());
    }

    public function test_it_fails_on_a_missing_template(): void
    {
        unlink("{$this->path}/.github/php-version-support/drop.md");
        $tester = new CommandTester(new PhpSupportIssuesCommand($this->client()));

        $status = $tester->execute(['--path' => $this->path, '--date' => '2027-11-15', '--dry-run' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('drop.md not found', $tester->getDisplay());
    }

    /**
     * @dataProvider invalidRepositories
     */
    public function test_it_needs_a_repository_unless_it_is_a_dry_run(?string $repository): void
    {
        putenv('GITHUB_REPOSITORY');
        $tester = new CommandTester(new PhpSupportIssuesCommand($this->client()));

        $status = $tester->execute(['--path' => $this->path, '--date' => '2027-11-15', '--token' => 'secret'] + ($repository === null ? [] : ['--repo' => $repository]));

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('--repo (or GITHUB_REPOSITORY) must be owner/name', $tester->getDisplay());
    }

    public static function invalidRepositories(): iterable
    {
        yield 'none' => [null];
        yield 'no owner' => ['opentelemetry-php'];
        yield 'a url' => ['https://github.com/o/r'];
        yield 'a trailing newline' => ["open-telemetry/opentelemetry-php\n"];
        yield 'dot segments' => ['../..'];
        yield 'a dot segment' => ['o/..'];
    }

    public function test_it_fails_when_the_path_is_not_a_directory(): void
    {
        $client = new FakeHttpClient([]);
        $tester = new CommandTester(new PhpSupportIssuesCommand($client));

        $status = $tester->execute(['--path' => "{$this->path}/missing", '--dry-run' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('is not a directory', $tester->getDisplay());
        $this->assertCount(0, $client->requests);
    }

    public function test_it_needs_a_token_unless_it_is_a_dry_run(): void
    {
        putenv('GITHUB_TOKEN');
        $client = $this->client();
        $tester = new CommandTester(new PhpSupportIssuesCommand($client));

        $status = $tester->execute(['--path' => $this->path, '--date' => '2027-11-15', '--repo' => self::REPO]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('needs a GitHub token', $tester->getDisplay());
        $this->assertCount(0, $client->requests, 'nothing was asked before the failure');
    }

    public function test_it_reports_a_github_error(): void
    {
        $client = $this->client([new Response(403)]);
        $tester = new CommandTester(new PhpSupportIssuesCommand($client));

        $status = $tester->execute(['--path' => $this->path, '--date' => '2027-11-15', '--repo' => self::REPO, '--token' => 'secret']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('403', $tester->getDisplay());
    }

    public function test_it_is_named_after_its_purpose(): void
    {
        $this->assertSame('php:support:issues', (new PhpSupportIssuesCommand())->getName());
    }

    /**
     * @param list<Response> $then answers after the one of php.net
     */
    private function client(array $then = []): FakeHttpClient
    {
        return new FakeHttpClient([new Response(200, [], $this->phpStatesJson()), ...$then]);
    }

    private function label(): Response
    {
        return new Response(201, [], '{"name": "php-version-support"}');
    }

    /**
     * @param list<string> $titles
     */
    private function issues(array $titles): Response
    {
        return new Response(200, [], (string) json_encode(array_map(static fn (string $title): array => ['title' => $title], $titles)));
    }

    private function template(string $name, string $contents): void
    {
        file_put_contents("{$this->path}/.github/php-version-support/{$name}", $contents);
    }
}
