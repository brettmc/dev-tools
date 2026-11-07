<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer;

interface RepositoryTypes
{
    public const COMPOSER_TYPE = 'composer';
    public const VCS_TYPE = 'vcs';
    public const PACKAGE_TYPE = 'package';
    public const PATH_TYPE = 'path';

    public const TYPES = [
        self::COMPOSER_TYPE,
        self::VCS_TYPE,
        self::PACKAGE_TYPE,
        self::PATH_TYPE,
    ];
}
