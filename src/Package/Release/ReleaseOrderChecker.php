<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Release;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use UnexpectedValueException;

/**
 * Checks the new versions of a release against each other's composer constraints.
 *
 * - A package whose constraint is satisfied only by another package's new version has to be tagged
 *   after it. That includes the `#[PackageDependency]` on its service providers, which composer
 *   never sees: tagged first, the package installs, but the providers silently do not load.
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
                if (!$this->matches($parsed, $new)) {
                    $warnings[] = sprintf('%s %s %s %s, which excludes %s', $package->name, $verb, $name, $constraint, $new);

                    continue;
                }
                if ($name !== $package->name && isset($released[$package->name]) && !$this->matches($parsed, $dependency->latest)) {
                    $after[$package->name][$name] = true;
                }
            }
            foreach ($package->conflict as $name => $constraint) {
                $dependency = $released[$name] ?? null;
                $parsed = $dependency === null ? null : $this->constraint($constraint);
                $new = $dependency?->version;
                if ($new !== null && $parsed !== null && $this->matches($parsed, $new)) {
                    $warnings[] = sprintf('%s conflicts with %s %s, which matches %s', $package->name, $name, $constraint, $new);
                }
            }
        }

        [$order, $cycle] = $this->sort($after, array_keys($released));
        if ($cycle !== []) {
            $warnings[] = sprintf('circular dependency between %s, no safe tag order', implode(', ', $cycle));
        }

        return new ReleaseOrderReport([...$order, ...$cycle], $warnings);
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

    private function matches(ConstraintInterface $constraint, ?string $version): bool
    {
        if ($version === null) {
            return false;
        }

        try {
            return $constraint->matches(new Constraint('==', $this->parser->normalize($version)));
        } catch (UnexpectedValueException) {
            return false;
        }
    }

    /**
     * @param array<string, array<string, true>> $after packages each package has to be tagged after
     * @param list<string> $names
     * @return array{0: list<string>, 1: list<string>} packages in tag order, and those stuck in a cycle
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
}
