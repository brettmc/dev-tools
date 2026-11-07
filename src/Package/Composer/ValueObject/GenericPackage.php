<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer\ValueObject;

class GenericPackage implements PackageInterface
{
    use PackageTrait;

    private string $type;

    public function __construct(string $name, string $type)
    {
        $this->name = $name;
        $this->type = $type;
    }

    public static function create(string $name, string $type): self
    {
        return new self($name, $type);
    }

    #[\Override]
    public function getType(): string
    {
        return $this->type;
    }
}
