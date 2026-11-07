<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer\ValueObject;

interface SingleRepositoryInterface extends RepositoryInterface
{
    public const COMPOSER_FILE_NAME = 'composer.json';

    public function getRootDirectory(): string;

    public function getComposerFilePath(): string;

    public function getPackage(): PackageInterface;

    public function getPackageName(): string;

    public function getPackageType(): string;
}
