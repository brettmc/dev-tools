<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Application;

use OpenTelemetry\DevTools\Console\Application\Application;
use OpenTelemetry\DevTools\Console\Command\Packages\SpiCheckCommand;
use OpenTelemetry\DevTools\Console\Command\Packages\ValidatePackagesCommand;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Console\Application\Application
 */
class ApplicationTest extends TestCase
{
    private const COMMAND_CLASSES = [
        ValidatePackagesCommand::class,
        SpiCheckCommand::class,
    ];

    public function test_commands(): void
    {
        $commands = [];

        foreach ((new Application())->all() as $name => $command) {
            $commands[$name] = get_class($command);
        }

        foreach (self::COMMAND_CLASSES as $commandClass) {
            $this->assertContains($commandClass, $commands);
        }
    }
}
