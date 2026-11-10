<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use UnexpectedValueException;

final class VersionMatcher
{
    /**
     * Whether $constraint allows $version. A missing version, or one composer can't parse, matches
     * nothing.
     */
    public static function matches(ConstraintInterface $constraint, ?string $version): bool
    {
        if ($version === null) {
            return false;
        }

        try {
            return $constraint->matches(new Constraint('==', (new VersionParser())->normalize($version)));
        } catch (UnexpectedValueException) {
            return false;
        }
    }
}
