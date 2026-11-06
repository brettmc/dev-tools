<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Changelog;

use InvalidArgumentException;

final class ChangelogException extends InvalidArgumentException
{
    public function __construct(string $message, public readonly ?int $lineNumber = null)
    {
        parent::__construct($lineNumber === null ? $message : "line {$lineNumber}: {$message}");
    }
}
