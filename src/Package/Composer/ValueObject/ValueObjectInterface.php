<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer\ValueObject;

use JsonSerializable;

interface ValueObjectInterface extends JsonSerializable
{
    public function toArray(): array;
}
