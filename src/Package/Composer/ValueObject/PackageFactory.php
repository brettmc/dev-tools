<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer\ValueObject;

use OpenTelemetry\DevTools\Package\Composer\PackageTypes;

class PackageFactory
{
    public const DEFAULT_TYPE = PackageTypes::LIBRARY_TYPE;
    public const TYPES = [
        PackageTypes::LIBRARY_TYPE => LibraryPackage::class,
    ];

    public static function create(): PackageFactory
    {
        return new self();
    }

    public function build(string $name, string $type = self::DEFAULT_TYPE): PackageInterface
    {
        if (array_key_exists($type, self::TYPES)) {
            $packageClass = self::TYPES[$type];

            return new $packageClass($name);
        }

        return new GenericPackage($name, $type);
    }
}
