<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Changelog;

/**
 * Finds the version source for the few packages that have one. Each is a VERSION file the generator
 * writes into the package, so unlike src/SemConv/Version.php it cannot be forgotten.
 *
 * - sem-conv is released as the semantic conventions version it was generated from.
 * - gen-otlp-protobuf mirrors the opentelemetry-proto release it was generated from, patch included.
 */
final class VersionSourceResolver
{
    private const PACKAGES = ['open-telemetry/sem-conv', 'open-telemetry/gen-otlp-protobuf'];

    public function resolve(string $package, string $root, string $prefix): ?VersionSource
    {
        if (!in_array($package, self::PACKAGES, true)) {
            return null;
        }

        $file = "{$root}/{$prefix}/VERSION";
        $contents = is_file($file) ? file_get_contents($file) : false;
        if ($contents === false || preg_match('/^v?(\d+\.\d+\.\d+)$/', trim($contents), $m) !== 1) {
            return null;
        }

        return new VersionSource($m[1]);
    }
}
