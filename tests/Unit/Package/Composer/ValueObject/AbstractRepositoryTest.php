<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer\ValueObject;

use OpenTelemetry\DevTools\Package\Composer\ValueObject\RepositoryInterface;
use PHPUnit\Framework\TestCase;

abstract class AbstractRepositoryTest extends TestCase
{
    use UsesPackageInterfaceMockTrait;

    public const REPOSITORY_TYPE = 'path';
    public const ROOT_DIRECTORY = 'src';
    public const REPOSITORY_URL = self::ROOT_DIRECTORY . '/library';
    public const ATTRIBUTES = [
        RepositoryInterface::TYPE_ATTRIBUTE => self::REPOSITORY_TYPE,
        RepositoryInterface::URL_ATTRIBUTE => self::REPOSITORY_URL,
        RepositoryInterface::PACKAGES_ATTRIBUTE => [
            self::PACKAGE_ATTRIBUTES,
        ],
    ];
    public const PACKAGE_ATTRIBUTES = PackageAttributes::ATTRIBUTES;
}
