<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer\ValueObject;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use Serializable;

interface CollectionInterface extends IteratorAggregate, ArrayAccess, Serializable, Countable, ValueObjectInterface
{
    public function getItemClass(): string;
}
