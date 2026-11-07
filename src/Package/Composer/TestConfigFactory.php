<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer;

class TestConfigFactory
{
    private array $defaultDependencies = [];

    public function __construct(array $defaultDependencies = [])
    {
        foreach ($defaultDependencies as $packageName => $versionConstraint) {
            $this->addDefaultDependency(
                $packageName,
                $versionConstraint
            );
        }
    }

    public static function create(array $defaultDependencies = []): self
    {
        return new self($defaultDependencies);
    }

    public function build(?string $name = null, ?string $type = null): TestConfig
    {
        $installation = TestConfig::create($name, $type);

        foreach ($this->defaultDependencies as $packageName => $versionConstraint) {
            $installation->addRequire($packageName, $versionConstraint);
        }

        return $installation;
    }

    public function addDefaultDependency(string $packageName, string $versionConstraint): void
    {
        $this->defaultDependencies[$packageName] = $versionConstraint;
    }
}
