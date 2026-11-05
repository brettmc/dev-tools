<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Spi;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use UnexpectedValueException;

/**
 * Checks `#[PackageDependency]` constraints on packages split from a monorepo against the monorepo
 * itself, rather than against the latest release.
 *
 * Inside the monorepo, composer resolves a split package to its entry in the root `replace`, so that
 * is what decides at runtime whether the provider loads. Once released, the package resolves to its
 * tag, which is cut from the line its `dev-main` alias names. Both have to satisfy the constraint.
 */
final class SplitDependencyChecker
{
    private VersionParser $parser;

    public function __construct()
    {
        $this->parser = new VersionParser();
    }

    /**
     * @param list<ProviderDependency> $dependencies
     * @param array<string, ?string> $aliases `dev-main` alias by split package name, null when it has none
     * @param array<string, string> $replace the root composer.json `replace`
     */
    public function check(array $dependencies, array $aliases, array $replace): SplitDependencyReport
    {
        $rows = [];
        $external = [];
        foreach ($dependencies as $dependency) {
            $package = $dependency->package;
            if (!array_key_exists($package, $aliases)) {
                $external[$package] = true;

                continue;
            }
            $rows[] = new SplitDependencyRow(
                $dependency,
                $aliases[$package],
                $replace[$package] ?? null,
                $this->problems($dependency->constraint, $aliases[$package], $replace[$package] ?? null),
            );
        }

        return new SplitDependencyReport($rows, array_keys($external));
    }

    /**
     * @return list<string>
     */
    private function problems(string $constraint, ?string $alias, ?string $replace): array
    {
        try {
            $parsed = $this->parser->parseConstraints($constraint);
        } catch (UnexpectedValueException) {
            return ['constraint does not parse'];
        }
        $problems = [];
        if ($alias === null) {
            $problems[] = 'no dev-main alias';
        } elseif (!$this->satisfies($parsed, $alias)) {
            $problems[] = "dev-main alias {$alias} does not satisfy {$constraint}";
        }
        if ($replace === null) {
            $problems[] = 'not in root replace';
        } elseif (!$this->satisfies($parsed, $replace)) {
            $problems[] = "root replace {$replace} does not satisfy {$constraint}";
        }

        return $problems;
    }

    private function satisfies(ConstraintInterface $constraint, string $version): bool
    {
        try {
            return $constraint->matches(new Constraint('==', $this->parser->normalize($version)));
        } catch (UnexpectedValueException) {
            return false;
        }
    }
}
