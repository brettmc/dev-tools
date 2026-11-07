<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\GitSplit;

interface ConfigAttributes
{
    public const SPLITS_ATTRIBUTE = 'splits';
    public const PREFIX_ATTRIBUTE = 'prefix';
    public const TARGET_ATTRIBUTE = 'target';
    public const SPLITS_ATTRIBUTES = [
        self::PREFIX_ATTRIBUTE,
        self::TARGET_ATTRIBUTE,
    ];
}
