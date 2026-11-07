<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer;

use OpenTelemetry\DevTools\Package\Composer\ValueObject\RepositoryCollection;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\SingleRepositoryInterface;

class TestInstallationFactory
{
    private TestConfigFactory $testConfigFactory;

    public function __construct(?TestConfigFactory $testConfigFactory = null, array $defaultDependencies = [])
    {
        $this->testConfigFactory = $testConfigFactory ?? TestConfigFactory::create($defaultDependencies);
    }

    public static function create(
        ?TestConfigFactory $testConfigFactory = null,
        array $defaultDependencies = []
    ): TestInstallationFactory {
        return new self($testConfigFactory, $defaultDependencies);
    }

    public function build(
        SingleRepositoryInterface $testedRepository,
        ?string $testedBranch = null,
        ?RepositoryCollection $dependencies = null
    ): TestInstallation {
        return TestInstallation::create(
            $testedRepository,
            $this->testConfigFactory->build(
                $testedRepository->getPackageName(),
                $testedRepository->getPackageType()
            ),
            $dependencies,
            $testedBranch
        );
    }

    public function getTestConfigFactory(): TestConfigFactory
    {
        return $this->testConfigFactory;
    }

    public function addDefaultDependency(string $packageName, string $versionConstraint): void
    {
        $this->getTestConfigFactory()->addDefaultDependency($packageName, $versionConstraint);
    }
}
