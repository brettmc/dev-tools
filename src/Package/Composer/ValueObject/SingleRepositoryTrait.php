<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer\ValueObject;

trait SingleRepositoryTrait
{
    use RepositoryTrait ;

    private PackageInterface $package;

    public function __construct(string $url, PackageInterface $package)
    {
        $this->url = $url;
        $this->package = $package;
        $this->packages[] = $package;
        $this->setType();
    }

    /**
     * @suppress PhanTypeInstantiateTraitStaticOrSelf
     */
    public static function create(string $url, PackageInterface $package): self
    {
        /** @phpstan-ignore-next-line */
        return new static($url, $package);
    }

    public function getRootDirectory(): string
    {
        return dirname($this->getUrl());
    }

    public function getComposerFilePath(): string
    {
        return $this->getUrl() . DIRECTORY_SEPARATOR . SingleRepositoryInterface::COMPOSER_FILE_NAME;
    }

    public function getPackage(): PackageInterface
    {
        return $this->package;
    }

    public function getPackageName(): string
    {
        return $this->getPackage()->getName();
    }

    public function getPackageType(): string
    {
        return $this->getPackage()->getType();
    }

    abstract protected function setType(): void;
}
