<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer\ValueObject;

use OpenTelemetry\DevTools\Package\Composer\ValueObject\DependencyCollection;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\DependencyInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\ValueObject\DependencyCollection
 */
class DependencyCollectionTest extends TestCase
{
    public function test_get_item_class(): void
    {
        $this->assertSame(
            DependencyInterface::class,
            DependencyCollection::create()->getItemClass()
        );
    }
}
