<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer;

class PackageAttributeResolverFactory
{
    public static function create(): PackageAttributeResolverFactory
    {
        return new self();
    }

    public function build(string $composerFilePath): PackageAttributeResolver
    {
        return new PackageAttributeResolver($composerFilePath);
    }
}
