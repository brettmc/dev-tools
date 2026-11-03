<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Changelog;

use OpenTelemetry\DevTools\Package\Changelog\VersionSourceResolver;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsConstants;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsTrait;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Changelog\VersionSourceResolver
 * @covers \OpenTelemetry\DevTools\Package\Changelog\VersionSource
 */
class VersionSourceResolverTest extends TestCase
{
    use UsesVfsTrait;

    private VersionSourceResolver $resolver;

    #[\Override]
    public function setUp(): void
    {
        $this->setUpVcs();
        $this->resolver = new VersionSourceResolver();
    }

    private function root(): string
    {
        return vfsStream::url(UsesVfsConstants::ROOT_DIR);
    }

    public function test_package_without_a_source_has_none(): void
    {
        $this->assertNull($this->resolver->resolve('open-telemetry/api', $this->root(), 'src/API'));
    }

    public function test_sem_conv_takes_the_highest_version_case(): void
    {
        vfsStream::create(['src' => ['SemConv' => ['Version.php' => <<<'PHP'
                <?php
                enum Version: string
                {
                    case VERSION_1_44_0 = '1.44.0';
                    case VERSION_1_41_1 = '1.41.1';
                    case VERSION_1_9_0 = '1.9.0';
                }
                PHP]]], $this->root);

        $source = $this->resolver->resolve('open-telemetry/sem-conv', $this->root(), 'src/SemConv');

        $this->assertSame('1.44.0', $source?->version);
    }

    public function test_sem_conv_without_version_file_has_none(): void
    {
        $this->assertNull($this->resolver->resolve('open-telemetry/sem-conv', $this->root(), 'src/SemConv'));
    }

    public function test_gen_otlp_protobuf_mirrors_the_proto_version_exactly(): void
    {
        vfsStream::create(['proto' => ['otel' => ['VERSION' => "v1.11.1\n"]]], $this->root);

        $source = $this->resolver->resolve('open-telemetry/gen-otlp-protobuf', $this->root(), 'proto/otel');

        $this->assertSame('1.11.1', $source?->version);
    }

    public function test_unparseable_proto_version_has_none(): void
    {
        vfsStream::create(['proto' => ['otel' => ['VERSION' => "main\n"]]], $this->root);

        $this->assertNull($this->resolver->resolve('open-telemetry/gen-otlp-protobuf', $this->root(), 'proto/otel'));
    }

    public function test_pre_release_proto_version_has_none(): void
    {
        vfsStream::create(['proto' => ['otel' => ['VERSION' => "v1.12.0-rc1\n"]]], $this->root);

        $this->assertNull($this->resolver->resolve('open-telemetry/gen-otlp-protobuf', $this->root(), 'proto/otel'));
    }
}
