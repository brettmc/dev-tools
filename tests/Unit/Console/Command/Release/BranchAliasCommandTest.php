<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Command\Release;

use OpenTelemetry\DevTools\Console\Command\Release\BranchAliasCommand;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsTrait;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Release\BranchAliasCommand
 */
class BranchAliasCommandTest extends TestCase
{
    use UsesVfsTrait;

    private CommandTester $commandTester;

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

    private function execute(string $path): int
    {
        $this->commandTester->execute([
            '--path' => $path,
            '--token' => 'test-token',
        ]);

        return $this->commandTester->getStatusCode();
    }
}
