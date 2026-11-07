<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer;

use JsonSerializable;

class TestConfig implements JsonSerializable
{
    public const DEFAULT_NAME = 'test/package';
    public const DEFAULT_DESCRIPTION = 'Test Package';
    public const DEFAULT_TYPE = PackageTypes::PROJECT_TYPE;
    public const DEFAULT_LICENSE = 'Apache-2.0';
    public const DEFAULT_MINIMUM_STABILITY = 'dev';
    public const DEFAULT_PREFER_STABLE = true;

    private string $name;
    private string $type;
    private string $description = self::DEFAULT_DESCRIPTION;
    private string $license = self::DEFAULT_LICENSE;
    private string $minimumStability = self::DEFAULT_MINIMUM_STABILITY;
    private bool $preferStable = self::DEFAULT_PREFER_STABLE;
    private array $autoload = [
        ConfigAttributes::PSR4 => [],
    ];
    private array $require = [];
    private array $repositories = [];

    public function __construct(?string $name = null, ?string $type = null)
    {
        $this->name = $name ?? self::DEFAULT_NAME;
        $this->type = $type ?? self::DEFAULT_TYPE;
    }

    public static function create(?string $name = null, ?string $type = null): TestConfig
    {
        return new self($name, $type);
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function setLicense(string $license): void
    {
        $this->license = $license;
    }

    public function setMinimumStability(string $minimumStability): void
    {
        $this->minimumStability = $minimumStability;
    }

    public function setPreferStable(bool $preferStable): void
    {
        $this->preferStable = $preferStable;
    }

    public function addRequire(string $packageName, string $versionConstraint): void
    {
        $this->require[$packageName] = $versionConstraint;
    }

    public function addAutoloadPsr4(string $namespace, string $directory): void
    {
        $this->autoload[ConfigAttributes::PSR4][$namespace] = $directory;
    }

    public function addRepository(string $type, string $url): void
    {
        $this->repositories[] = [
            ConfigAttributes::TYPE => $type,
            ConfigAttributes::URL => $url,
        ];
    }

    public function toArray(): array
    {
        $config = [
            ConfigAttributes::NAME => $this->name,
            ConfigAttributes::DESCRIPTION => $this->description,
            ConfigAttributes::TYPE => $this->type,
            ConfigAttributes::LICENSE => $this->license,
            ConfigAttributes::MINIMUM_STABILITY => $this->minimumStability,
            ConfigAttributes::PREFER_STABLE => $this->preferStable,
        ];

        if (!empty($this->autoload[ConfigAttributes::PSR4])) {
            $config[ConfigAttributes::AUTOLOAD] = $this->autoload;
        }

        if (!empty($this->require)) {
            $config[ConfigAttributes::REQUIRE] = $this->require;
        }

        if (!empty($this->repositories)) {
            $config[ConfigAttributes::REPOSITORIES] = $this->repositories;
        }

        return $config;
    }

    #[\Override]
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
