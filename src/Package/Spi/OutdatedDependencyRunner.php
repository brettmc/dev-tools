<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Spi;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Runs tbachert/spi's own check, which compares each `#[PackageDependency]` with the latest release
 * of the package, in a checkout.
 */
class OutdatedDependencyRunner
{
    public function __construct(private readonly string $composer = 'composer')
    {
    }

    /**
     * @param list<string> $ignore packages tbachert/spi should not check
     * @return int the exit code
     */
    public function run(string $path, array $ignore, OutputInterface $output): int
    {
        $command = [$this->composer, 'spi:show-outdated-package-dependencies', '--no-interaction'];
        foreach ($ignore as $package) {
            $command[] = '--ignore-package=' . $package;
        }
        $process = new Process($command, $path);
        $process->setTimeout(null);

        return $process->run(static function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });
    }
}
