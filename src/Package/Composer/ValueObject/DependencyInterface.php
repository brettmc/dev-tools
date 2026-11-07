<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer\ValueObject;

interface DependencyInterface extends ValueObjectInterface
{
    public const VERSION_ATTRIBUTE = 'version';
    public const PACKAGE_ATTRIBUTE = 'package';
    public const ATTRIBUTES = [
        self::VERSION_ATTRIBUTE,
        self::PACKAGE_ATTRIBUTE,
    ];

    public function getVersionConstraint(): string;

    public function getPackage(): PackageInterface;
}
