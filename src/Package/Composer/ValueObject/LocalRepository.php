<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer\ValueObject;

use OpenTelemetry\DevTools\Package\Composer\RepositoryTypes;

class LocalRepository implements SingleRepositoryInterface
{
    use SingleRepositoryTrait;

    #[\Override]
    protected function setType(): void
    {
        $this->type = RepositoryTypes::PATH_TYPE;
    }
}
