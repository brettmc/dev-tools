<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer\ValueObject;

use ArrayIterator;
use ArrayObject;
use InvalidArgumentException;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\AbstractCollection;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\ValueObjectInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\ValueObject\AbstractCollection
 */
class AbstractCollectionTest extends TestCase
{
    private AbstractCollection $instance;

    private const OFFSET_KEY = 'foo';

    #[\Override]
    protected function setUp(): void
    {
        $this->instance = $this->createInstance();
    }

    public function test_offset_access(): void
    {
        $item = $this->createMock(ValueObjectInterface::class);

        $this->instance[self::OFFSET_KEY] = $item;

        $this->assertSame(
            $item,
            $this->instance[self::OFFSET_KEY]
        );
    }

    public function test_offset_exists(): void
    {
        $this->instance[self::OFFSET_KEY] = $this->createMock(ValueObjectInterface::class);

        $this->assertTrue(
            $this->instance->offsetExists(self::OFFSET_KEY)
        );
    }

    public function test_offset_unset(): void
    {
        $this->instance[self::OFFSET_KEY] = $this->createMock(ValueObjectInterface::class);

        unset($this->instance[self::OFFSET_KEY]);

        $this->assertFalse(
            isset($this->instance[self::OFFSET_KEY])
        );
    }

    public function test_to_array(): void
    {
        $itemData = [1, 2, 3];

        $item = $this->createMock(ValueObjectInterface::class);
        $item->method('toArray')
            ->willReturn($itemData);

        $this->instance[self::OFFSET_KEY] = $item;

        $this->assertSame(
            [self::OFFSET_KEY => $itemData],
            $this->instance->toArray()
        );
    }

    /**
     * @dataProvider provideInvalidOffsetTypes
     */
    public function test_offset_exists_throws_exception_on_invalid_offset($offset): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore-next-line */
        isset($this->instance[$offset]);
    }

    /**
     * @dataProvider provideInvalidOffsetTypes
     */
    public function test_offset_get_throws_exception_on_invalid_offset($offset): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore-next-line */
        $this->instance[$offset];
    }

    /**
     * @dataProvider provideInvalidOffsetTypes
     */
    public function test_offset_set_throws_exception_on_invalid_offset($offset): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->instance[$offset] = $this->createMock(ValueObjectInterface::class);
    }

    /**
     * @dataProvider provideInvalidOffsetTypes
     */
    public function test_offset_unset_throws_exception_on_invalid_offset($offset): void
    {
        $this->expectException(InvalidArgumentException::class);

        unset($this->instance[$offset]);
    }

    /**
     * @dataProvider provideInvalidOffsetTypes
     */
    public function test_offset_set_throws_exception_on_invalid_value($value): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->instance[self::OFFSET_KEY] = $value;
    }

    public function provideInvalidOffsetTypes(): array
    {
        return [
            [321],
            [3.21],
            [[]],
            [new ArrayObject()],
            [false],
            [null],
        ];
    }

    public function provideInvalidValueTypes(): array
    {
        return [
            ['foo'],
            [321],
            [3.21],
            [[]],
            [new ArrayObject()],
            [false],
            [null],
        ];
    }

    private function createInstance(): AbstractCollection
    {
        return new class() extends AbstractCollection {
            #[\Override]
            public static function create($array = [], $flags = 0, $iteratorClass = ArrayIterator::class): AbstractCollection
            {
                return new self($array, $flags, $iteratorClass);
            }

            #[\Override]
            public function getItemClass(): string
            {
                return ValueObjectInterface::class;
            }
        };
    }
}
