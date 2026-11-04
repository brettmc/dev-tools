<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Changelog;

enum ChangelogSection: string
{
    case BreakingChanges = 'Breaking changes';
    case Added = 'Added';
    case Changed = 'Changed';
    case Deprecated = 'Deprecated';
    case Removed = 'Removed';
    case Fixed = 'Fixed';
    case Security = 'Security';
}
