<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer;

interface ConfigAttributes
{
    public const NAME = 'name';
    public const DESCRIPTION = 'description';
    public const TYPE = 'type';
    public const LICENSE = 'license';
    public const MINIMUM_STABILITY = 'minimum-stability';
    public const PREFER_STABLE = 'prefer-stable';
    public const AUTOLOAD = 'autoload';
    public const REQUIRE = 'require';
    public const REPOSITORIES = 'repositories';
    public const PSR4 = 'psr-4';
    public const URL = 'url';
    public const EXTRA = 'extra';
    public const BRANCH_ALIAS = 'branch-alias';
}
