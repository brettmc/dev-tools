<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Spi;

final class ProviderDependencies
{
    /**
     * @param list<ProviderDependency> $dependencies
     * @param list<string> $missing providers registered in extra.spi whose class does not load
     */
    public function __construct(
        public readonly array $dependencies = [],
        public readonly array $missing = [],
    ) {
    }

    /**
     * The constraints of the providers in each split package, found by the prefix its file lies
     * under. A package nested in another's directory claims its own files.
     *
     * @param list<string> $prefixes split prefixes, relative to the checkout
     * @return array<string, array<string, list<string>>> constraints by package name, by prefix
     */
    public function byPrefix(array $prefixes): array
    {
        $dirs = [];
        foreach ($prefixes as $prefix) {
            $dirs[$prefix] = trim($prefix, '/') . '/';
        }
        uasort($dirs, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $grouped = [];
        foreach ($this->dependencies as $dependency) {
            foreach ($dirs as $prefix => $dir) {
                if ($dependency->file !== null && str_starts_with($dependency->file, $dir)) {
                    $grouped[$prefix][$dependency->package][] = $dependency->constraint;

                    break;
                }
            }
        }

        return $grouped;
    }
}
