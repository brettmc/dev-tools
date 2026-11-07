<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer\ValueObject;

use OpenTelemetry\DevTools\Package\Composer\PackageTypes;

class LibraryPackage implements PackageInterface
{
    use PackageTrait;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function create(string $path): self
    {
        return new self($path);
    }

    #[\Override]
    public function getType(): string
    {
        return PackageTypes::LIBRARY_TYPE;
    }
}
