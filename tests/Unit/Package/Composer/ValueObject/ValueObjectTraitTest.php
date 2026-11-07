<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer\ValueObject;

use OpenTelemetry\DevTools\Package\Composer\ValueObject\ValueObjectInterface;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\ValueObjectTrait;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\ValueObject\ValueObjectTrait
 */
class ValueObjectTraitTest extends TestCase
{
    public const ATTRIBUTES = [
        'foo' => 'bar',
        'bar' => 'baz',
    ];

    public function test_json_serialize(): void
    {
        $this->assertSame(
            self::ATTRIBUTES,
            $this->createInstance()->jsonSerialize()
        );
    }

    private function createInstance(): ValueObjectInterface
    {
        return new class() implements ValueObjectInterface {
            use ValueObjectTrait;

            #[\Override]
            public function toArray(): array
            {
                return ValueObjectTraitTest::ATTRIBUTES;
            }
        };
    }
}
