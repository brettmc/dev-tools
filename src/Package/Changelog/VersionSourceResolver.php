<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Changelog;

/**
 * Finds the version source for the few packages that have one.
 *
 * - sem-conv is released as the semantic conventions version it was generated from, per the
 *   generator's version.txt.
 * - gen-otlp-protobuf mirrors the opentelemetry-proto release it was generated from, patch included.
 */
final class VersionSourceResolver
{
    //written by the generator, so unlike src/SemConv/Version.php it cannot be forgotten
    private const SEMCONV_VERSION_FILE = 'script/semantic-conventions/templates/registry/php/version.txt';

    public function resolve(string $package, string $root, string $prefix): ?VersionSource
    {
        return match ($package) {
            'open-telemetry/sem-conv' => $this->semConv("{$root}/" . self::SEMCONV_VERSION_FILE),
            'open-telemetry/gen-otlp-protobuf' => $this->proto("{$root}/{$prefix}/VERSION"),
            default => null,
        };
    }

    private function semConv(string $file): ?VersionSource
    {
        $contents = is_file($file) ? file_get_contents($file) : false;
        if ($contents === false || preg_match('#/schemas/(\d+\.\d+\.\d+)$#', trim($contents), $m) !== 1) {
            return null;
        }

        return new VersionSource($m[1]);
    }

    private function proto(string $file): ?VersionSource
    {
        $contents = is_file($file) ? file_get_contents($file) : false;
        if ($contents === false || preg_match('/^v?(\d+\.\d+\.\d+)$/', trim($contents), $m) !== 1) {
            return null;
        }

        return new VersionSource($m[1]);
    }
}
