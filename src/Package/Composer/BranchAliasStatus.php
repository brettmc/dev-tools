<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer;

enum BranchAliasStatus
{
    case Updated;
    case Unchanged;
    case Ahead;
    case Missing;
    case NotFound;
    case Ambiguous;
    case WriteFailed;

    public function description(): string
    {
        return match ($this) {
            self::Updated => 'updated',
            self::Unchanged => 'up to date',
            self::Ahead => 'skipped, alias ahead of releases',
            self::Missing => 'no branch-alias',
            self::NotFound => 'no composer.json',
            self::Ambiguous => 'ambiguous, update manually',
            self::WriteFailed => 'WRITE FAILED, check the file',
        };
    }
}
