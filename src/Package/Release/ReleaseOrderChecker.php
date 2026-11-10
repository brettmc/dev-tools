<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Release;

use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use OpenTelemetry\DevTools\Package\Composer\VersionMatcher;
use UnexpectedValueException;

/**
 * Checks the new versions of a release against each other's composer constraints.
 *
 * - A package whose constraint is satisfied only by another package's new version has to be tagged
 *   after it. That includes the `#[PackageDependency]` on its service providers, which composer
 *   never sees: tagged first, the package installs, but the providers silently do not load.
 *   The packages nothing orders follow, in the order given.
 * - A `require` that excludes the new version of a package, or a `conflict` that matches it, is
 *   warned about. This is how a forced major shows up: dependents that need their constraint widened.
 *
 * Constraints that are not versions (`dev-main`) or cannot be parsed are ignored.
 */
final class ReleaseOrderChecker
{
    private VersionParser $parser;

    public function __construct()
    {
        $this->parser = new VersionParser();
    }

    /**
     * @param list<PackageRelease> $packages
     */
    public function check(array $packages): ReleaseOrderReport
    {
        $released = [];
        foreach ($packages as $package) {
            if ($package->version !== null) {
                $released[$package->name] = $package;
            }
        }

        $warnings = [];
        $after = [];
        foreach ($packages as $package) {
            foreach ($this->requirements($package) as [$verb, $name, $constraint]) {
                $dependency = $released[$name] ?? null;
                $parsed = $dependency === null ? null : $this->constraint($constraint);
                $new = $dependency?->version;
                if ($dependency === null || $new === null || $parsed === null) {
                    continue;
                }
                if (!VersionMatcher::matches($parsed, $new)) {
                    $warnings[] = sprintf('%s %s %s %s, which excludes %s', $package->name, $verb, $name, $constraint, $new);

                    continue;
                }
                if ($name !== $package->name && isset($released[$package->name]) && !VersionMatcher::matches($parsed, $dependency->latest)) {
                    $after[$package->name][$name] = true;
                }
            }
            foreach ($package->conflict as $name => $constraint) {
                $dependency = $released[$name] ?? null;
                $parsed = $dependency === null ? null : $this->constraint($constraint);
                $new = $dependency?->version;
                if ($new !== null && $parsed !== null && VersionMatcher::matches($parsed, $new)) {
                    $warnings[] = sprintf('%s conflicts with %s %s, which matches %s', $package->name, $name, $constraint, $new);
                }
            }
        }

        [$order, $stuck] = $this->sort($after, array_keys($released));
        if ($stuck !== []) {
            $warnings[] = sprintf('circular dependency between %s, no safe tag order', implode(', ', $this->cycle($after, $stuck)));
        }

        $free = array_values(array_diff(array_keys($released), $order, $stuck));

        return new ReleaseOrderReport([...$order, ...$stuck, ...$free], $warnings);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> verb, package name and constraint
     */
    private function requirements(PackageRelease $package): array
    {
        $requirements = [];
        foreach ($package->require as $name => $constraint) {
            $requirements[] = ['requires', $name, $constraint];
        }
        foreach ($package->spi as $name => $constraints) {
            foreach (array_unique($constraints) as $constraint) {
                $requirements[] = ['has #[PackageDependency] on', $name, $constraint];
            }
        }

        return $requirements;
    }

    private function constraint(string $constraint): ?ConstraintInterface
    {
        if (str_contains($constraint, 'dev-') || str_contains($constraint, '-dev')) {
            return null;
        }

        try {
            return $this->parser->parseConstraints($constraint);
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    /**
     * @param array<string, array<string, true>> $after packages each package has to be tagged after
     * @param list<string> $names
     * @return array{0: list<string>, 1: list<string>} packages in tag order, and those stuck behind a cycle
     */
    private function sort(array $after, array $names): array
    {
        $involved = [];
        foreach ($after as $name => $dependencies) {
            $involved[$name] = true;
            foreach (array_keys($dependencies) as $dependency) {
                $involved[$dependency] = true;
            }
        }
        $pending = array_values(array_filter($names, static fn (string $name): bool => isset($involved[$name])));

        $order = [];
        while ($pending !== []) {
            $ready = array_values(array_filter(
                $pending,
                static fn (string $name): bool => array_diff_key($after[$name] ?? [], array_flip($order)) === []
            ));
            if ($ready === []) {
                return [$order, $pending];
            }
            $order[] = $ready[0];
            $pending = array_values(array_diff($pending, [$ready[0]]));
        }

        return [$order, []];
    }

    /**
     * The packages in $stuck that wait on themselves. The rest only wait on one of them.
     *
     * @param array<string, array<string, true>> $after
     * @param list<string> $stuck
     * @return list<string>
     */
    private function cycle(array $after, array $stuck): array
    {
        return array_values(array_filter($stuck, static function (string $name) use ($after): bool {
            $seen = [];
            $next = array_keys($after[$name] ?? []);
            while ($next !== []) {
                $current = array_pop($next);
                if ($current === $name) {
                    return true;
                }
                if (!isset($seen[$current])) {
                    $seen[$current] = true;
                    array_push($next, ...array_keys($after[$current] ?? []));
                }
            }

            return false;
        }));
    }
}
