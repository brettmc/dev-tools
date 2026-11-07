<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer;

interface PackageTypes
{
    public const LIBRARY_TYPE = 'library';
    public const PROJECT_TYPE = 'project';
    public const META_PACKAGE_TYPE = 'metapackage';
    public const COMPOSER_PLUGIN_TYPE = 'composer-plugin';

    public const TYPES = [
        self::LIBRARY_TYPE,
        self::PROJECT_TYPE,
        self::META_PACKAGE_TYPE,
        self::COMPOSER_PLUGIN_TYPE,
    ];
}
