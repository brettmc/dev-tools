<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer\ValueObject;

use ArrayIterator;
use ArrayObject;
use InvalidArgumentException;

abstract class AbstractCollection extends ArrayObject implements CollectionInterface
{
    use ValueObjectTrait;

    #[\Override]
    abstract public function getItemClass(): string;

    abstract public static function create($array = [], $flags = 0, $iteratorClass = ArrayIterator::class): AbstractCollection;

    #[\Override]
    public function offsetExists($key): bool
    {
        $this->ensureOffsetType($key);

        return parent::offsetExists($key);
    }

    #[\Override]
    public function offsetGet($key): ValueObjectInterface
    {
        $this->ensureOffsetType($key);

        return parent::offsetGet($key);
    }

    #[\Override]
    public function offsetSet($key, $value): void
    {
        $this->ensureOffsetType($key);
        $this->ensureValueType($value);

        parent::offsetSet($key, $value);
    }

    #[\Override]
    public function offsetUnset($key): void
    {
        $this->ensureOffsetType($key);

        parent::offsetUnset($key);
    }

    #[\Override]
    public function toArray(): array
    {
        $result = [];

        foreach ($this as $key => $item) {
            $result[$key] = $item->toArray();
        }

        return $result;
    }

    private function ensureOffsetType($key): void
    {
        if (!is_string($key)) {
            throw new InvalidArgumentException(
                sprintf('Offset must be of type string. Given "%s".', gettype($key))
            );
        }
    }

    protected function ensureValueType($value): void
    {
        if (!$this->validateValueType($value)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Value must be instance of "%s". Given "%s".',
                    RepositoryInterface::class,
                    get_debug_type($value)
                )
            );
        }
    }

    protected function validateValueType($value): bool
    {
        $itemClass = $this->getItemClass();

        return $value instanceof $itemClass;
    }
}
