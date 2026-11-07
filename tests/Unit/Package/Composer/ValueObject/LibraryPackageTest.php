<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer\ValueObject;

use OpenTelemetry\DevTools\Package\Composer\PackageTypes;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\LibraryPackage;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\ValueObject\LibraryPackage
 */
class LibraryPackageTest extends TestCase
{
    private const PACKAGE_TYPE = PackageTypes::LIBRARY_TYPE;

    public function test_get_type(): void
    {
        $this->assertSame(
            self::PACKAGE_TYPE,
            LibraryPackage::create('foo')->getType()
        );
    }
}
