<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer\ValueObject;

use OpenTelemetry\DevTools\Package\Composer\ValueObject\PackageInterface;

interface PackageAttributes
{
    public const TYPE = 'library';
    public const NAME = 'foo/bar';
    public const ATTRIBUTES = [
        PackageInterface::TYPE_ATTRIBUTE => self::TYPE,
        PackageInterface::NAME_ATTRIBUTE => self::NAME,
    ];
}
