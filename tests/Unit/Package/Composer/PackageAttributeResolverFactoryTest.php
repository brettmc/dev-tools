<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer;

use OpenTelemetry\DevTools\Package\Composer\PackageAttributeResolverFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\PackageAttributeResolverFactory
 */
class PackageAttributeResolverFactoryTest extends TestCase
{
    public function test_build(): void
    {
        $composerFile = 'composer.json';

        $this->assertSame(
            $composerFile,
            PackageAttributeResolverFactory::create()
                ->build($composerFile)
                ->getComposerFilePath()
        );
    }
}
