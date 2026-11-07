<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer;

use Generator;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\PackageFactory;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\RepositoryCollection;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\RepositoryFactory;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\SingleRepositoryInterface;
use OpenTelemetry\DevTools\Util\PhpTypes;
use OpenTelemetry\DevTools\Util\WorkingDirectoryResolver;

class MultiRepositoryInfoResolver
{
    private ConfigResolverInterface $configResolver;
    private PackageAttributeResolverFactory $packageAttributeResolverFactory;
    private RepositoryFactory $repositoryFactory;
    private string $workingDirectory;
    private array $packageAttributeResolvers = [];

    public function __construct(
        ConfigResolverInterface $configResolver,
        ?PackageAttributeResolverFactory $packageAttributeResolverFactory = null,
        ?RepositoryFactory $repositoryFactory = null,
        ?string $workingDirectory = null
    ) {
        $this->configResolver = $configResolver;
        $this->packageAttributeResolverFactory = $packageAttributeResolverFactory
            ?? PackageAttributeResolverFactory::create();
        $this->repositoryFactory = $repositoryFactory
            ?? RepositoryFactory::create(
                PackageFactory::create()
            );
        $this->workingDirectory = $workingDirectory ?? WorkingDirectoryResolver::create()->resolve();
    }

    public static function create(
        ConfigResolverInterface $configResolver,
        ?PackageAttributeResolverFactory $packageAttributeResolverFactory,
        ?RepositoryFactory $repositoryFactory = null,
        ?string $workingDirectory = null
    ): MultiRepositoryInfoResolver {
        return new self($configResolver, $packageAttributeResolverFactory, $repositoryFactory, $workingDirectory);
    }

    public function resolve(): RepositoryCollection
    {
        $collection = RepositoryCollection::create();

        foreach ($this->doResolve() as $packageName => $repository) {
            $collection[$packageName] = $repository;
        }

        return $collection;
    }

    public function setWorkingDirectory(string $workingDirectory): void
    {
        $this->workingDirectory = $workingDirectory;
    }

    public function getWorkingDirectory(): string
    {
        return $this->workingDirectory;
    }

    private function doResolve(): Generator
    {
        foreach ($this->configResolver->resolve() as $directory => $composerFile) {
            $repository = $this->createRepository($composerFile, $directory);

            yield $repository->getPackage()->getName() => $repository;
        }
    }

    private function createRepository(string $composerFile, string $directory): SingleRepositoryInterface
    {
        return $this->repositoryFactory->buildSingleRepository(
            $directory,
            RepositoryTypes::PATH_TYPE,
            $this->getPackageName($composerFile),
            $this->getPackageType($composerFile)
        );
    }

    private function getPackageType(string $composerFile): string
    {
        return (string) $this->getPackageNameResolver($composerFile)
            ->resolve(ConfigAttributes::TYPE, PhpTypes::STRING_TYPE);
    }

    private function getPackageName(string $composerFile): string
    {
        return (string) $this->getPackageNameResolver($composerFile)
            ->resolve(ConfigAttributes::NAME, PhpTypes::STRING_TYPE);
    }

    private function getPackageNameResolver(string $composerFile): PackageAttributeResolver
    {
        return $this->packageAttributeResolvers[$composerFile]
            ?? $this->packageAttributeResolvers[$composerFile] = $this->createPackageNameResolver($composerFile);
    }

    private function createPackageNameResolver(string $composerFile): PackageAttributeResolver
    {
        return $this->packageAttributeResolverFactory->build($composerFile);
    }
}
