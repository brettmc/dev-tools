<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer\ValueObject;

trait PackageTrait
{
    use ValueObjectTrait;

    private string $name;
    private ?DependencyCollection $dependencies;

    public function getName(): string
    {
        return $this->name;
    }

    abstract public function getType(): string;

    public function setDependencies(?DependencyCollection $dependencies): void
    {
        $this->dependencies = $dependencies;
    }

    /**
     * @psalm-suppress RedundantPropertyInitializationCheck
     */
    public function getDependencies(): DependencyCollection
    {
        return $this->dependencies ?? $this->dependencies = DependencyCollection::create();
    }

    public function toArray(): array
    {
        return [
            PackageInterface::TYPE_ATTRIBUTE => $this->getType(),
            PackageInterface::NAME_ATTRIBUTE => $this->getName(),
            PackageInterface::DEPENDENCIES_ATTRIBUTE => $this->getDependencies()->toArray(),
        ];
    }
}
