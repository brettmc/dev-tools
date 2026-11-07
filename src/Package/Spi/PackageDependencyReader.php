<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Spi;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Reads the `#[PackageDependency]` attributes on the service providers of a checkout, by running
 * read-package-dependencies.php against the checkout's own vendor/autoload.php.
 */
class PackageDependencyReader
{
    private const SCRIPT = __DIR__ . '/read-package-dependencies.php';

    public function __construct(private readonly string $php = PHP_BINARY)
    {
    }

    public function read(string $path): ProviderDependencies
    {
        if (!is_file("{$path}/vendor/autoload.php")) {
            throw new RuntimeException("{$path}/vendor/autoload.php not found, run `composer install` in {$path} first");
        }
        $process = new Process([$this->php, self::SCRIPT, $path], $path);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'Could not read the #[PackageDependency] attributes of %s: %s',
                $path,
                trim($process->getErrorOutput() ?: $process->getOutput())
            ));
        }
        $data = json_decode($process->getOutput(), true);
        if (!is_array($data) || !is_array($data['dependencies'] ?? null) || !is_array($data['missing'] ?? null)) {
            throw new RuntimeException("Unexpected output reading the #[PackageDependency] attributes of {$path}: {$process->getOutput()}");
        }

        $dependencies = [];
        foreach ($data['dependencies'] as $row) {
            if (!is_string($row['provider'] ?? null) || !is_string($row['package'] ?? null) || !is_string($row['version'] ?? null)) {
                throw new RuntimeException(sprintf('Unreadable #[PackageDependency] in %s: %s', $path, json_encode($row)));
            }
            $file = $row['file'] ?? null;
            $dependencies[] = new ProviderDependency($row['provider'], $row['package'], $row['version'], is_string($file) ? $file : null);
        }

        return new ProviderDependencies($dependencies, array_values(array_filter($data['missing'], 'is_string')));
    }
}
