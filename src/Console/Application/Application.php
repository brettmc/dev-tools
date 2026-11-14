<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Application;

use OpenTelemetry\DevTools\Console\Command\Changelog\ChangelogCheckCommand;
use OpenTelemetry\DevTools\Console\Command\Packages\SpiCheckCommand;
use OpenTelemetry\DevTools\Console\Command\Packages\ValidatePackagesCommand;
use OpenTelemetry\DevTools\Console\Command\Php\PhpSupportCommand;
use OpenTelemetry\DevTools\Console\Command\Php\PhpSupportIssuesCommand;
use OpenTelemetry\DevTools\Console\Command\Release\BranchAliasCommand;
use OpenTelemetry\DevTools\Console\Command\Release\PeclCommand;
use OpenTelemetry\DevTools\Console\Command\Release\PeclTagCommand;
use OpenTelemetry\DevTools\Console\Command\Release\ReleaseCommand;
use OpenTelemetry\DevTools\Console\Command\Release\ReleaseListCommand;
use OpenTelemetry\DevTools\Console\Command\Release\ReleasePrCommand;
use OpenTelemetry\DevTools\Console\Command\Release\ReleasePrepCommand;
use OpenTelemetry\DevTools\Console\Command\Release\ReleasePrStaleCommand;
use Symfony\Component\Console\Application as BaseApplication;

class Application extends BaseApplication
{
    public const DEFAULT_NAME = 'otel';

    public function __construct(string $name = self::DEFAULT_NAME, string $version = 'DEV')
    {
        parent::__construct($name, $version);

        $this->initCommands();
    }

    private function initCommands(): void
    {
        $this->addCommands([
            new ValidatePackagesCommand(),
            new ReleaseCommand(),
            new ReleaseListCommand(),
            new PeclCommand(),
            new PeclTagCommand(),
            new BranchAliasCommand(),
            new ReleasePrepCommand(),
            new ReleasePrCommand(),
            new ReleasePrStaleCommand(),
            new SpiCheckCommand(),
            new ChangelogCheckCommand(),
            new PhpSupportCommand(),
            new PhpSupportIssuesCommand(),
        ]);
    }
}
