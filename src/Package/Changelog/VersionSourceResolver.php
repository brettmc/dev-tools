<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Changelog;

use Composer\Semver\Semver;

/**
 * Finds the version source for the few packages that have one.
 *
 * - sem-conv is released as the semantic conventions version it was generated from.
 * - gen-otlp-protobuf mirrors the opentelemetry-proto release it was generated from, patch included.
 */
final class VersionSourceResolver
{
    public function resolve(string $package, string $root, string $prefix): ?VersionSource
    {
        return match ($package) {
            'open-telemetry/sem-conv' => $this->semConv("{$root}/{$prefix}/Version.php"),
            'open-telemetry/gen-otlp-protobuf' => $this->proto("{$root}/{$prefix}/VERSION"),
            default => null,
        };
    }

    private function semConv(string $file): ?VersionSource
    {
        $php = is_file($file) ? file_get_contents($file) : false;
        if ($php === false || preg_match_all("/case\s+VERSION_\w+\s*=\s*'(\d+\.\d+\.\d+)'/", $php, $m) < 1) {
            return null;
        }

        return new VersionSource(Semver::rsort($m[1])[0]);
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
