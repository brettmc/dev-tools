<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer\ValueObject;

use OpenTelemetry\DevTools\Package\Composer\ValueObject\RepositoryCollection;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\RepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\ValueObject\RepositoryCollection
 */
class RepositoryCollectionTest extends TestCase
{
    public function test_get_item_class(): void
    {
        $this->assertSame(
            RepositoryInterface::class,
            RepositoryCollection::create()->getItemClass()
        );
    }
}
