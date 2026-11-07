<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer\ValueObject;

use InvalidArgumentException;
use OpenTelemetry\DevTools\Package\Composer\RepositoryTypes;

class RepositoryFactory
{
    public const TYPES = [
        RepositoryTypes::COMPOSER_TYPE => GenericRepository::class,
        RepositoryTypes::PATH_TYPE => LocalRepository::class,
        RepositoryTypes::VCS_TYPE => VcsRepository::class,
    ];
    public const SINGLE_TYPES = [
        RepositoryTypes::PATH_TYPE,
        RepositoryTypes::VCS_TYPE,
    ];

    private PackageFactory $packageFactory;

    public function __construct(PackageFactory $packageFactory)
    {
        $this->packageFactory = $packageFactory;
    }

    public static function create(PackageFactory $packageFactory): RepositoryFactory
    {
        return new self($packageFactory);
    }

    public function build(string $url, string $type, array $packages = []): RepositoryInterface
    {
        return self::isSingleRepositoryType($type)
            ? $this->buildSingleRepositoryFromConfig($url, $type, $packages)
            : $this->buildRepository($url, $type, $packages);
    }

    public function buildRepository(string $url, string $type, array $packages = []): RepositoryInterface
    {
        self::ensureType($type);

        if (self::isSingleRepositoryType($type)) {
            throw new InvalidArgumentException(
                sprintf('Repository Type  "%s" is a single repository type.', $type)
            );
        }

        $repositoryClass = self::TYPES[$type];

        /** @phpstan-ignore-next-line */
        return new $repositoryClass(
            $url,
            /** @phpstan-ignore-next-line */
            $type,
            $this->buildPackages(
                $packages
            )
        );
    }

    /**
     * @suppress PhanTypeMismatchArgumentReal
     * @psalm-suppress InvalidReturnType
     * @psalm-suppress InvalidReturnStatement
     */
    public function buildSingleRepository(string $url, string $type, string $packageName, string $packageType): SingleRepositoryInterface
    {
        self::ensureType($type);

        if (!self::isSingleRepositoryType($type)) {
            throw new InvalidArgumentException(
                sprintf('Type "%s" is a single repository type.', $type)
            );
        }

        $repositoryClass = self::TYPES[$type];

        return new $repositoryClass(
            $url,
            /** @phpstan-ignore-next-line */
            $this->buildPackage(
                $packageName,
                $packageType
            )
        );
    }

    private function buildSingleRepositoryFromConfig(string $url, string $type, array $packages): SingleRepositoryInterface
    {
        if (empty($packages)) {
            throw new InvalidArgumentException(
                sprintf('Single Repository at "%s" must have a package configured', $url)
            );
        }

        $packageConfig = array_shift($packages);

        self::ensurePackageConfig($packageConfig);

        return $this->buildSingleRepository(
            $url,
            $type,
            $packageConfig[PackageInterface::NAME_ATTRIBUTE],
            $packageConfig[PackageInterface::TYPE_ATTRIBUTE]
        );
    }

    private function buildPackage(string $name, string $type): PackageInterface
    {
        return $this->packageFactory->build($name, $type);
    }

    private function buildPackages(array $packages): array
    {
        $result = [];

        foreach ($packages as $config) {
            self::ensurePackageConfig($config);
            $result[] = $this->buildPackage(
                $config[PackageInterface::NAME_ATTRIBUTE],
                $config[PackageInterface::TYPE_ATTRIBUTE]
            );
        }

        return $result;
    }

    private static function ensureType(string $type): void
    {
        if (!array_key_exists($type, self::TYPES)) {
            throw new InvalidArgumentException(
                sprintf('Repository Type "%s" is not implemented (yet)', $type)
            );
        }
    }

    private static function ensurePackageConfig($config): void
    {
        if (!is_array($config)) {
            throw new InvalidArgumentException('Package config must be an array');
        }

        foreach (PackageInterface::MANDATORY_ATTRIBUTES as $attribute) {
            if (!isset($config[$attribute])) {
                throw new InvalidArgumentException(
                    sprintf('Attribute "%s" not found in package config', $attribute)
                );
            }
        }
    }

    private static function isSingleRepositoryType(string $type): bool
    {
        return in_array($type, self::SINGLE_TYPES, true);
    }
}
