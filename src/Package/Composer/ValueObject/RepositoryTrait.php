<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer\ValueObject;

trait RepositoryTrait
{
    use ValueObjectTrait;

    private string $url;
    private string $type;
    private array $packages;

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPackages(): array
    {
        return $this->packages;
    }

    public function toArray(): array
    {
        return [
            RepositoryInterface::TYPE_ATTRIBUTE => $this->getType(),
            RepositoryInterface::URL_ATTRIBUTE => $this->getUrl(),
            RepositoryInterface::PACKAGES_ATTRIBUTE => $this->getPackagesAsArray(),
        ];
    }

    private function getPackagesAsArray(): array
    {
        $result = [];

        foreach ($this->getPackages() as $package) {
            $result[] = $package->toArray();
        }

        return $result;
    }
}
