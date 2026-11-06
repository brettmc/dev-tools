<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Command\Release;

use Nyholm\Psr7\Response;
use OpenTelemetry\DevTools\Console\Command\Release\ReleaseListCommand;
use OpenTelemetry\DevTools\Console\Release\GitHub;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsTrait;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Release\ReleaseListCommand
 */
class ReleaseListCommandTest extends TestCase
{
    use UsesVfsTrait;

    /** @var list<string> */
    private array $urls = [];
    /** @var array<string, ?string> latest release by split project, null for none */
    private array $latest = ['api' => '1.10.0', 'sdk' => '0.9.0'];
    private CommandTester $tester;

    #[\Override]
    public function setUp(): void
    {
        $this->setUpVcs();
        file_put_contents($this->root->url() . '/composer.json', '{"name":"open-telemetry/opentelemetry"}');
        $yaml = "splits:\n";
        foreach (['src/API' => 'api', 'src/SDK' => 'sdk'] as $prefix => $project) {
            $yaml .= "  - prefix: \"{$prefix}\"\n    target: \"https://\${GH_TOKEN}@github.com/opentelemetry-php/{$project}.git\"\n";
        }
        file_put_contents($this->root->url() . '/.gitsplit.yml', $yaml);

        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(function (RequestInterface $request): Response {
            $url = (string) $request->getUri();
            $this->urls[] = $url;
            if (preg_match('#/repos/opentelemetry-php/[^/]+$#', $url) === 1) {
                return new Response(200, [], json_encode(['full_name' => substr($url, strlen('https://api.github.com/repos/'))]));
            }
            preg_match('#/repos/opentelemetry-php/([^/]+)/releases/latest$#', $url, $m);
            $version = $this->latest[$m[1] ?? ''] ?? null;

            return $version === null
                ? new Response(404)
                : new Response(200, [], json_encode(['tag_name' => $version, 'created_at' => '2026-01-01T00:00:00Z']));
        });
        $this->tester = new CommandTester(new ReleaseListCommand(new GitHub($client)));
    }

    public function test_splits_are_read_from_the_checkout(): void
    {
        $status = $this->tester->execute(['--path' => $this->root->url()]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame([], preg_grep('#raw\.githubusercontent\.com#', $this->urls));
        $this->assertSame([
            'https://api.github.com/repos/opentelemetry-php/api/releases/latest',
            'https://api.github.com/repos/opentelemetry-php/sdk/releases/latest',
        ], $this->urls);
        $this->assertMatchesRegularExpression('/api\s+\|\s+1\.10\.0/', $this->tester->getDisplay());
        $this->assertMatchesRegularExpression('/sdk\s+\|\s+0\.9\.0/', $this->tester->getDisplay());
    }

    public function test_path_defaults_to_the_working_directory(): void
    {
        $cwd = (string) getcwd();
        $dir = sys_get_temp_dir() . '/release-list-' . bin2hex(random_bytes(4));
        mkdir($dir);
        copy($this->root->url() . '/composer.json', "{$dir}/composer.json");
        copy($this->root->url() . '/.gitsplit.yml', "{$dir}/.gitsplit.yml");
        chdir($dir);

        try {
            $status = $this->tester->execute([]);
        } finally {
            chdir($cwd);
            unlink("{$dir}/composer.json");
            unlink("{$dir}/.gitsplit.yml");
            rmdir($dir);
        }

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertCount(2, $this->urls);
    }

    public function test_path_that_is_not_a_monorepo_fails_before_calling_github(): void
    {
        unlink($this->root->url() . '/.gitsplit.yml');

        $status = $this->tester->execute(['--path' => $this->root->url()]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertSame([], $this->urls);
        $this->assertStringContainsString('not a monorepo checkout', $this->tester->getDisplay());
    }

    public function test_package_without_a_release_is_listed_as_none(): void
    {
        $this->latest['sdk'] = null;

        $this->tester->execute(['--path' => $this->root->url()]);

        $this->assertMatchesRegularExpression('/sdk\s+\|\s+none/', $this->tester->getDisplay());
    }

    public function test_stable_lists_only_packages_with_a_1_0_release(): void
    {
        $this->tester->execute(['--path' => $this->root->url(), '--stable' => true]);

        $this->assertStringContainsString('1.10.0', $this->tester->getDisplay());
        $this->assertStringNotContainsString('0.9.0', $this->tester->getDisplay());
    }

    public function test_unstable_lists_only_packages_without_a_1_0_release(): void
    {
        $this->tester->execute(['--path' => $this->root->url(), '--unstable' => true]);

        $this->assertStringNotContainsString('1.10.0', $this->tester->getDisplay());
        $this->assertStringContainsString('0.9.0', $this->tester->getDisplay());
    }
}
