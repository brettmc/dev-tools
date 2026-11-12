<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Php;

use DateTimeImmutable;

/**
 * One PHP branch a project supports or may have to start or stop supporting.
 */
final class SupportRow
{
    public const ADD = 'add';
    public const DROP = 'drop';

    /**
     * @param bool $tested whether the branch is in the CI matrix
     * @param string|null $action {@see self::ADD} or {@see self::DROP} when the project has to act on the branch now
     */
    public function __construct(
        public readonly PhpBranch $branch,
        public readonly DateTimeImmutable $supportedUntil,
        public readonly bool $tested,
        public readonly ?string $action,
    ) {
    }

    /**
     * Whether the project supports the branch. The rows of a report are the released branches from the
     * minimum up, so a branch that CI tests is supported, until it is dropped from CI.
     */
    public function isSupported(): bool
    {
        return $this->tested;
    }
}
