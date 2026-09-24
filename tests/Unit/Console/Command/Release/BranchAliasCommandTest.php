<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Command\Release;

use Nyholm\Psr7\Response;
use OpenTelemetry\DevTools\Console\Command\Release\BranchAliasCommand;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsTrait;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Release\BranchAliasCommand
 */
class BranchAliasCommandTest extends TestCase
{
    use UsesVfsTrait;

    private CommandTester $commandTester;
    /** @var array<string> directories to remove after the test */
    private array $cleanup = [];

    #[\Override]
    public function setUp(): void
    {
        $this->setUpVcs();
        $this->commandTester = new CommandTester(new BranchAliasCommand());
    }

    public function test_fails_when_path_is_empty(): void
    {
        $this->assertSame(
            Command::FAILURE,
            $this->execute($this->root->url())
        );
        $this->assertStringContainsString('is not a monorepo checkout', $this->commandTester->getDisplay());
    }

    public function test_fails_when_path_has_no_split_config(): void
    {
        vfsStream::newFile('composer.json')
            ->withContent('{"name":"open-telemetry/api"}')
            ->at($this->root);

        $this->assertSame(
            Command::FAILURE,
            $this->execute($this->root->url())
        );
        $this->assertStringContainsString('is not a monorepo checkout', $this->commandTester->getDisplay());
    }

    public function test_fails_when_split_config_has_no_packages(): void
    {
        vfsStream::newFile('composer.json')
            ->withContent('{"name":"open-telemetry/opentelemetry"}')
            ->at($this->root);
        vfsStream::newFile('.gitsplit.yml')
            ->withContent("splits:\n")
            ->at($this->root);

        $this->assertSame(
            Command::FAILURE,
            $this->execute($this->root->url())
        );
        $this->assertStringContainsString('No packages found', $this->commandTester->getDisplay());
    }

    public function test_updates_alias_of_a_nested_package(): void
    {
        $root = $this->setUpRealMonorepo('packages/api');

        $this->assertSame(Command::SUCCESS, $this->executeWithRelease($root, '1.10.0'));
        $this->assertStringContainsString(
            '1.10.x-dev',
            (string) file_get_contents("{$root}/packages/api/composer.json")
        );
    }

    public function test_refuses_a_split_prefix_that_escapes_the_monorepo(): void
    {
        $root = $this->setUpRealMonorepo('../outside');
        $escaped = dirname($root) . '/outside/composer.json';

        //refusing to write is a failure: the command was asked to update a package and did not
        $this->assertSame(Command::FAILURE, $this->executeWithRelease($root, '1.10.0'));
        $this->assertStringContainsString('resolves outside', $this->commandTester->getDisplay());
        $this->assertStringContainsString('0.1.x-dev', (string) file_get_contents($escaped));
        $this->assertStringNotContainsString('1.10.x-dev', (string) file_get_contents($escaped));
    }

    public function test_refuses_a_split_prefix_that_symlinks_out_of_the_monorepo(): void
    {
        $root = $this->setUpRealMonorepo('packages/api');
        $escaped = dirname($root) . '/outside/composer.json';
        $this->makePackage(dirname($root) . '/outside');
        //the prefix itself is innocuous; what it points at is not
        unlink("{$root}/packages/api/composer.json");
        rmdir("{$root}/packages/api") || $this->fail('could not replace package directory');
        symlink(dirname($root) . '/outside', "{$root}/packages/api");

        $this->assertSame(Command::FAILURE, $this->executeWithRelease($root, '1.10.0'));
        $this->assertStringContainsString('resolves outside', $this->commandTester->getDisplay());
        $this->assertStringContainsString('0.1.x-dev', (string) file_get_contents($escaped));
    }

    public function test_fails_when_a_package_cannot_be_written(): void
    {
        $root = $this->setUpRealMonorepo('packages/api');
        $package = "{$root}/packages/api/composer.json";
        $original = (string) file_get_contents($package);
        chmod($package, 0o444);

        $this->assertSame(Command::FAILURE, $this->executeWithRelease($root, '1.10.0'));
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('could not write', $display);
        $this->assertStringContainsString('WRITE FAILED', $display);
        //the run must not sign off as though the tree were ready to review
        $this->assertStringNotContainsString('Review the changes', $display);
        $this->assertSame($original, file_get_contents($package));
    }

    /**
     * A monorepo checkout on the real filesystem, whose single split has $prefix, alongside a
     * sibling `outside/` package that nothing in the checkout should be able to reach.
     */
    private function setUpRealMonorepo(string $prefix): string
    {
        $base = sys_get_temp_dir() . '/branch-alias-' . bin2hex(random_bytes(6));
        $root = "{$base}/monorepo";
        mkdir($root, 0o777, true);
        $this->cleanup[] = $base;

        file_put_contents("{$root}/composer.json", '{"name":"open-telemetry/opentelemetry"}');
        file_put_contents("{$root}/.gitsplit.yml", <<<YAML
            splits:
              - prefix: "{$prefix}"
                target: "https://\${GH_TOKEN}@github.com/open-telemetry/opentelemetry-php-api.git"
            YAML);

        $this->makePackage("{$base}/outside");
        $this->makePackage("{$root}/" . ltrim($prefix, './'));

        return $root;
    }

    private function makePackage(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0o777, true);
        }
        file_put_contents(
            "{$path}/composer.json",
            '{"name":"open-telemetry/api","extra":{"branch-alias":{"dev-main":"0.1.x-dev"}}}'
        );
    }

    private function executeWithRelease(string $path, string $version): int
    {
        $response = new Response(200, [], json_encode([
            'tag_name' => $version,
            'created_at' => '2026-01-01T00:00:00Z',
        ]));
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturn($response);

        $this->commandTester = new CommandTester(new BranchAliasCommand(null, $client));

        return $this->execute($path);
    }

    #[\Override]
    public function tearDown(): void
    {
        foreach ($this->cleanup as $directory) {
            exec('rm -rf ' . escapeshellarg($directory));
        }
    }

    private function execute(string $path): int
    {
        $this->commandTester->execute([
            '--path' => $path,
            '--token' => 'test-token',
        ]);

        return $this->commandTester->getStatusCode();
    }
}
