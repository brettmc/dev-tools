<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Command\Release;

use OpenTelemetry\DevTools\Console\Command\Release\PeclTagCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Release\PeclTagCommand
 */
class PeclTagCommandTest extends TestCase
{
    private string|false $environmentToken;

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

    public function test_no_token_fails_without_interaction(): void
    {
        $tester = new CommandTester(new PeclTagCommand());

        $tester->execute([], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('No github token', $tester->getDisplay());
    }
}
