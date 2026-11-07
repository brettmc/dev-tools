<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer;

use OpenTelemetry\DevTools\Package\Composer\ConfigAttributes;
use OpenTelemetry\DevTools\Package\Composer\TestConfig;
use OpenTelemetry\DevTools\Package\Composer\TestConfigFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\TestConfigFactory
 */
class TestConfigFactoryTest extends TestCase
{
    private const DEFAULT_DEPENDENCIES = [
        'foo/bar' => '^1.0.0',
        'bar/baz' => '^2.0.0',
    ];
    private const PACKAGE_NAME = 'foo/bar';
    private const PACKAGE_TYPE = 'foo';

    public function test_build_default(): void
    {
        $data = $this->createInstance()->build()->toArray();

        $this->assertSame(
            TestConfig::DEFAULT_NAME,
            $data[ConfigAttributes::NAME]
        );

        $this->assertSame(
            TestConfig::DEFAULT_TYPE,
            $data[ConfigAttributes::TYPE]
        );
    }

    public function test_build_custom_package(): void
    {
        $data = $this->createInstance()->build(
            self::PACKAGE_NAME,
            self::PACKAGE_TYPE
        )->toArray();

        $this->assertSame(
            self::PACKAGE_NAME,
            $data[ConfigAttributes::NAME]
        );

        $this->assertSame(
            self::PACKAGE_TYPE,
            $data[ConfigAttributes::TYPE]
        );
    }

    public function test_build_constructor_dependencies(): void
    {
        $data = $this->createInstance(self::DEFAULT_DEPENDENCIES)
            ->build()
            ->toArray();

        $this->assertSame(
            self::DEFAULT_DEPENDENCIES,
            $data[ConfigAttributes::REQUIRE]
        );
    }

    public function test_build_setter_dependencies(): void
    {
        $instance = $this->createInstance();

        foreach (self::DEFAULT_DEPENDENCIES as $packageName => $versionConstraint) {
            $instance->addDefaultDependency($packageName, $versionConstraint);
        }

        $this->assertSame(
            self::DEFAULT_DEPENDENCIES,
            $instance->build()->toArray()[ConfigAttributes::REQUIRE]
        );
    }

    private function createInstance(array $defaultDependencies = []): TestConfigFactory
    {
        return TestConfigFactory::create($defaultDependencies);
    }
}
