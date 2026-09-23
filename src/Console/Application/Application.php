<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Application;

use OpenTelemetry\DevTools\Console\Command\Packages\ValidateInstallationCommand;
use OpenTelemetry\DevTools\Console\Command\Packages\ValidatePackagesCommand;
use OpenTelemetry\DevTools\Console\Command\Release\BranchAliasCommand;
use OpenTelemetry\DevTools\Console\Command\Release\PeclCommand;
use OpenTelemetry\DevTools\Console\Command\Release\PeclTagCommand;
use OpenTelemetry\DevTools\Console\Command\Release\ReleaseCommand;
use OpenTelemetry\DevTools\Console\Command\Release\ReleaseListCommand;
use OpenTelemetry\DevTools\Package\Composer\MultiRepositoryInfoResolver;
use OpenTelemetry\DevTools\Package\Composer\PackageAttributeResolverFactory;
use OpenTelemetry\DevTools\Package\GitSplit\ConfigResolver;
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
            new ValidatePackagesCommand(
                new ConfigResolver()
            ),
            new ValidateInstallationCommand(
                new MultiRepositoryInfoResolver(
                    new ConfigResolver(),
                    new PackageAttributeResolverFactory()
                )
            ),
            new ReleaseCommand(),
            new ReleaseListCommand(),
            new PeclCommand(),
            new PeclTagCommand(),
            new BranchAliasCommand(),
        ]);
    }
}
