<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer\ValueObject;

use OpenTelemetry\DevTools\Package\Composer\ValueObject\GenericPackage;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\ValueObject\GenericPackage
 */
class GenericPackageTest extends TestCase
{
    private const PACKAGE_TYPE = 'foo';

    public function test_get_type(): void
    {
        $this->assertSame(
            self::PACKAGE_TYPE,
            GenericPackage::create('foo', self::PACKAGE_TYPE)->getType()
        );
    }
}
