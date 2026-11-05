<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Command\Release;

use Nyholm\Psr7\Response;
use OpenTelemetry\DevTools\Console\Command\Release\ReleaseCommand;
use OpenTelemetry\DevTools\Package\Git\GitRunner;
use OpenTelemetry\DevTools\Package\Spi\PackageDependencyReader;
use OpenTelemetry\DevTools\Package\Spi\ProviderDependencies;
use OpenTelemetry\DevTools\Package\Spi\ProviderDependency;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsTrait;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Release\ReleaseCommand
 */
class ReleaseCommandTest extends TestCase
{
    use UsesVfsTrait;

    private const CHANGELOG = "# Changelog\n\n## Unreleased\n\n## 1.45.0 - 2026-11-03\n\n### Added\n\n- New attributes ([#7]).\n\n## 1.44.0 - 2026-10-01\n\n### Fixed\n\n- Old.\n\n[#7]: https://example.com/7\n";

    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];
    private ?string $changelog = self::CHANGELOG;
    private string $latestTag = '1.44.0';
    /** @var array<string, array{0: string, 1: string, 2: string}> prefix, latest tag and changelog by project; sem-conv alone when empty */
    private array $splits = [];
    private ProviderDependencies $dependencies;
    private ?RuntimeException $readError = null;
    private ?string $head = 'abc123';
    /** @var list<string> */
    private array $changes = [];
    /** @var array<string, ?string> CHANGELOG.md of the split repository by project, null for none; the upstream one when unset */
    private array $splitChangelogs = [];
    /** @var list<string> the branch tip for each lookup in turn, the last repeating */
    private array $tips = ['abc123'];
    /** @var resource */
    private $out;

    #[\Override]
    public function setUp(): void
    {
        $this->setUpVcs();
        file_put_contents($this->root->url() . '/.gitsplit.yml', 'splits: []');
        file_put_contents($this->root->url() . '/composer.json', '{"name":"open-telemetry/opentelemetry"}');
        $this->dependencies = new ProviderDependencies();
    }

    public function test_core_without_path_fails_before_calling_github(): void
    {
        $this->run_release('', ['--path' => null]);

        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('--path', $this->output());
    }

    public function test_default_run_includes_core_and_needs_path(): void
    {
        $this->run_release('', ['--path' => null, '--repo' => null]);

        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('--path', $this->output());
    }

    public function test_contrib_does_not_need_path(): void
    {
        $this->run_release('', ['--path' => null, '--repo' => 'contrib']);

        $this->assertNotSame([], $this->requests);
    }

    public function test_invalid_repo_fails_before_calling_github(): void
    {
        $status = $this->run_release('', ['--repo' => 'nope', '--path' => null]);

        $this->assertSame(1, $status);
        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('Invalid source: nope', $this->output());
    }

    public function test_checkout_of_another_monorepo_fails_before_calling_github(): void
    {
        file_put_contents($this->root->url() . '/composer.json', '{"name":"open-telemetry/opentelemetry-php-contrib"}');

        $status = $this->run_release('');

        $this->assertSame(1, $status);
        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('not a checkout of open-telemetry/opentelemetry-php', $this->output());
    }

    public function test_contrib_checkout_is_used_for_contrib(): void
    {
        file_put_contents($this->root->url() . '/composer.json', '{"name":"open-telemetry/opentelemetry-php-contrib"}');

        $this->run_release("\n\n\n", ['--repo' => 'contrib']);

        $this->assertSame('1.45.0', $this->created()['tag_name']);
    }

    public function test_unreadable_git_checkout_fails_before_calling_github(): void
    {
        $this->head = null;

        $status = $this->run_release('');

        $this->assertSame(1, $status);
        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('fatal: not a git repository', $this->output());
    }

    public function test_uncommitted_changes_fail_before_calling_github(): void
    {
        $this->changes = [' M src/SemConv/composer.json'];

        $status = $this->run_release('');

        $this->assertSame(1, $status);
        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('uncommitted changes', $this->output());
        $this->assertStringContainsString(' M src/SemConv/composer.json', $this->output());
    }

    public function test_checkout_not_at_the_branch_tip_fails(): void
    {
        $this->head = 'old456';

        $status = $this->run_release('');

        $this->assertSame(1, $status);
        $this->assertCount(1, $this->requests);
        $this->assertStringContainsString('/repos/open-telemetry/opentelemetry-php/git/matching-refs/heads/main', $this->requests[0]['url']);
        $this->assertStringContainsString('old456', $this->output());
        $this->assertStringContainsString('abc123', $this->output());
    }

    public function test_dry_run_warns_about_an_out_of_date_checkout(): void
    {
        $this->head = 'old456';
        $this->changes = [' M src/SemConv/composer.json'];

        $status = $this->run_release("\n\n\n", ['--dry-run' => true]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('uncommitted changes', $this->output());
        $this->assertStringContainsString('old456', $this->output());
        $this->assertStringContainsString('[DRY-RUN]', $this->output());
    }

    public function test_dry_run_warns_about_an_unreadable_git_checkout(): void
    {
        $this->head = null;

        $status = $this->run_release("\n\n\n", ['--dry-run' => true]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('fatal: not a git repository', $this->output());
        $this->assertStringContainsString('[DRY-RUN]', $this->output());
    }

    public function test_release_stops_when_the_branch_moves(): void
    {
        $this->splits = [
            'sdk' => ['src/SDK', '1.15.0', self::section('1.16.0')],
            'api' => ['src/API', '1.10.0', self::section('1.11.0')],
        ];
        $this->composer('src/SDK', 'open-telemetry/sdk', ['open-telemetry/api' => '^1.11']);
        $this->composer('src/API', 'open-telemetry/api');
        $this->tips = ['abc123', 'abc123', 'def789'];

        $status = $this->run_release(str_repeat("\n", 6), ['--filter' => 'src/']);

        $this->assertSame(1, $status);
        $this->assertSame(['opentelemetry-php/api' => '1.11.0'], $this->tags());
        $this->assertStringContainsString('moved from abc123 to def789', $this->output());
    }

    public function test_release_without_a_checkout_stops_when_the_branch_moves(): void
    {
        $this->tips = ['abc123', 'def789'];

        $status = $this->run_release("\n\n\n", ['--path' => null, '--repo' => 'contrib']);

        $this->assertSame(1, $status);
        $this->assertNull($this->post());
        $this->assertStringContainsString('moved from abc123 to def789', $this->output());
    }

    public function test_release_stops_when_the_split_lacks_the_changelog_section(): void
    {
        $this->splitChangelogs = ['sem-conv' => self::section('1.44.0')];

        $status = $this->run_release("\n\n\n");

        $this->assertSame(1, $status);
        $this->assertNull($this->post());
        $this->assertStringContainsString('opentelemetry-php/sem-conv main has no 1.45.0 section in CHANGELOG.md yet', $this->output());
    }

    public function test_release_stops_when_the_split_has_no_changelog(): void
    {
        $this->splitChangelogs = ['sem-conv' => null];

        $status = $this->run_release("\n\n\n");

        $this->assertSame(1, $status);
        $this->assertNull($this->post());
        $this->assertStringContainsString('has no 1.45.0 section in CHANGELOG.md yet', $this->output());
    }

    public function test_split_changelog_is_read_through_the_api(): void
    {
        $this->run_release("\n\n\n");

        $urls = array_column($this->requests, 'url');
        $this->assertContains('https://api.github.com/repos/opentelemetry-php/sem-conv/contents/CHANGELOG.md?ref=main', $urls);
    }

    public function test_dry_run_warns_when_the_split_lacks_the_changelog_section(): void
    {
        $this->splitChangelogs = ['sem-conv' => self::section('1.44.0')];

        $status = $this->run_release("\n\n\n", ['--dry-run' => true]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('has no 1.45.0 section in CHANGELOG.md yet', $this->output());
        $this->assertStringContainsString('[DRY-RUN] https://api.github.com/repos/opentelemetry-php/sem-conv/releases', $this->output());
    }

    public function test_split_is_not_checked_for_a_version_the_changelog_does_not_have(): void
    {
        $this->splitChangelogs = ['sem-conv' => self::section('1.44.0')];

        $this->run_release("1.46.0\n\n\n");

        $this->assertSame('1.46.0', $this->created()['tag_name']);
    }

    public function test_path_that_is_not_a_monorepo_fails_before_calling_github(): void
    {
        unlink($this->root->url() . '/.gitsplit.yml');

        $this->run_release('');

        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('not a monorepo checkout', $this->output());
    }

    public function test_unreadable_package_dependencies_fail_before_calling_github(): void
    {
        $this->readError = new RuntimeException('vfs://root/vendor/autoload.php not found, run `composer install` in vfs://root first');

        $this->run_release('');

        $this->assertSame([], $this->requests);
        $this->assertStringContainsString('composer install', $this->output());
    }

    public function test_releases_follow_package_dependency_attributes(): void
    {
        $this->splits = [
            'sdk-configuration' => ['src/Config/SDK', '0.9.0', self::section('0.10.0')],
            'exporter-otlp' => ['src/Contrib/Otlp', '1.4.0', self::section('1.5.0')],
        ];
        $this->composer('src/Config/SDK', 'open-telemetry/sdk-configuration');
        $this->composer('src/Contrib/Otlp', 'open-telemetry/exporter-otlp');
        $this->dependencies = new ProviderDependencies([
            new ProviderDependency('Provider', 'open-telemetry/exporter-otlp', '^1.5', 'src/Config/SDK/Provider.php'),
        ]);

        $this->run_release(str_repeat("\n", 6), ['--filter' => 'src/']);

        $this->assertSame(
            ['opentelemetry-php/exporter-otlp' => '1.5.0', 'opentelemetry-php/sdk-configuration' => '0.10.0'],
            $this->tags()
        );
        $this->assertStringContainsString('Tag in this order: open-telemetry/exporter-otlp, open-telemetry/sdk-configuration', $this->output());
    }

    public function test_releases_follow_composer_requirements(): void
    {
        $this->splits = [
            'sdk' => ['src/SDK', '1.15.0', self::section('1.16.0')],
            'api' => ['src/API', '1.10.0', self::section('1.11.0')],
        ];
        $this->composer('src/SDK', 'open-telemetry/sdk', ['open-telemetry/api' => '^1.11']);
        $this->composer('src/API', 'open-telemetry/api');

        $this->run_release(str_repeat("\n", 6), ['--filter' => 'src/']);

        $this->assertSame(['opentelemetry-php/api', 'opentelemetry-php/sdk'], array_keys($this->tags()));
    }

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
    private function run_release(string $answers, array $options = []): int
    {
        $this->out = fopen('php://memory', 'w+');
        $options += ['--token' => 'token', '--repo' => 'core', '--filter' => 'SemConv', '--path' => $this->root->url()];
        $input = new ArrayInput(array_filter($options, static fn (mixed $value): bool => $value !== null));
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

        return $this->command()->run($input, $output);
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
     * @return array<string, string> tag by downstream repository, in the order they were created
     */
    private function tags(): array
    {
        $tags = [];
        foreach ($this->requests as $request) {
            if ($request['method'] === 'POST' && preg_match('#/repos/([^/]+/[^/]+)/releases$#', $request['url'], $m)) {
                $tags[$m[1]] = json_decode($request['body'], true)['tag_name'];
            }
        }

        return $tags;
    }

    private static function section(string $version): string
    {
        return "# Changelog\n\n## Unreleased\n\n## {$version} - 2026-11-03\n\n### Added\n\n- New attributes ([#7]).\n\n[#7]: https://example.com/7\n";
    }

    /**
     * @param array<string, string> $require
     */
    private function composer(string $prefix, string $name, array $require = []): void
    {
        $dir = $this->root->url() . "/{$prefix}";
        is_dir($dir) || mkdir($dir, 0o777, true);
        file_put_contents("{$dir}/composer.json", json_encode(['name' => $name, 'require' => (object) $require]));
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

        $reader = $this->createMock(PackageDependencyReader::class);
        $reader->method('read')->willReturnCallback(fn (): ProviderDependencies => $this->readError !== null
            ? throw $this->readError
            : $this->dependencies);

        $git = $this->createMock(GitRunner::class);
        $git->method('head')->willReturnCallback(fn (): string => $this->head
            ?? throw new RuntimeException('fatal: not a git repository (or any of the parent directories): .git'));
        $git->method('changes')->willReturnCallback(fn (): array => $this->changes);

        return new ReleaseCommand($client, $reader, $git);
    }

    private function respond(string $method, string $url): Response
    {
        $json = static fn (mixed $data, int $status = 200): Response => new Response($status, [], json_encode($data));
        $splits = $this->splits ?: ['sem-conv' => ['src/SemConv', $this->latestTag, $this->changelog]];
        if ($method === 'POST') {
            return $json(['html_url' => 'https://github.com/x/y/releases/1'], 201);
        }
        if (str_contains($url, '/git/matching-refs/heads/main')) {
            $tip = count($this->tips) > 1 ? array_shift($this->tips) : $this->tips[0];

            return $json([['ref' => 'refs/heads/main', 'object' => ['sha' => $tip]]]);
        }
        if (str_ends_with($url, '/main/.gitsplit.yml')) {
            $yaml = "splits:\n";
            foreach ($splits as $project => [$prefix]) {
                $yaml .= "  - prefix: \"{$prefix}\"\n    target: \"https://\${GH_TOKEN}@github.com/opentelemetry-php/{$project}.git\"\n";
            }

            return new Response(200, [], $yaml);
        }
        foreach ($splits as $project => [$prefix, $latest, $changelog]) {
            $split = array_key_exists($project, $this->splitChangelogs) ? $this->splitChangelogs[$project] : $changelog;
            $response = match (true) {
                str_ends_with($url, "/{$project}/contents/CHANGELOG.md?ref=main") => $split === null
                    ? new Response(404)
                    : $json(['encoding' => 'base64', 'content' => chunk_split(base64_encode($split), 60, "\n")]),
                str_ends_with($url, "/main/{$prefix}/CHANGELOG.md") => $changelog === null ? new Response(404) : new Response(200, [], $changelog),
                str_contains($url, "/{$project}/releases/latest") => $json(['tag_name' => $latest, 'created_at' => '2026-01-01T00:00:00Z']),
                str_contains($url, "/{$project}/compare/") => $json(['commits' => [['sha' => 'abc', 'commit' => ['message' => 'Add attributes']]]]),
                default => null,
            };
            if ($response !== null) {
                return $response;
            }
        }

        return match (true) {
            str_contains($url, '/commits/abc/pulls') => $json([['user' => ['login' => 'octocat'], 'html_url' => 'https://github.com/o/r/pull/7', 'number' => 7, 'title' => 'Add attributes']]),
            str_contains($url, '/commits?path=') => $json([['sha' => 'abc', 'commit' => ['message' => 'Add attributes']]]),
            default => new Response(404),
        };
    }
}
