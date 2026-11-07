<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer\ValueObject;

use OpenTelemetry\DevTools\Package\Composer\ValueObject\PackageInterface;

trait UsesPackageInterfaceMockTrait
{
    protected function createPackageInterfaceMock(): PackageInterface
    {
        $mock = $this->createMock(PackageInterface::class);

        $mock->method('toArray')
            ->willReturn(PackageAttributes::ATTRIBUTES);

        $mock->method('jsonSerialize')
            ->willReturn(PackageAttributes::ATTRIBUTES);

        $mock->method('getType')
            ->willReturn(PackageAttributes::TYPE);

        $mock->method('getName')
            ->willReturn(PackageAttributes::NAME);

        return $mock;
    }

    abstract protected function createMock(string $originalClassName): object;
}
