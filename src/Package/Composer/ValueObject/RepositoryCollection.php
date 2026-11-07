<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer\ValueObject;

class RepositoryCollection extends AbstractCollection
{
    #[\Override]
    public static function create($array = [], $flags = 0, $iteratorClass = 'ArrayIterator'): RepositoryCollection
    {
        return new self($array, $flags, $iteratorClass);
    }

    #[\Override]
    public function getItemClass(): string
    {
        return RepositoryInterface::class;
    }
}
