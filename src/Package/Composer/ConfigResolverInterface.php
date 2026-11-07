<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer;

interface ConfigResolverInterface
{
    public function resolve(): iterable;
}
