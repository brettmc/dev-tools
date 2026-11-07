<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer;

use Generator;
use OpenTelemetry\DevTools\Package\Composer\ConfigAttributes;
use OpenTelemetry\DevTools\Package\Composer\TestConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\TestConfig
 */
class TestConfigTest extends TestCase
{
    private const DEFAULT_SCALAR_VALUES = [
        ConfigAttributes::NAME => TestConfig::DEFAULT_NAME,
        ConfigAttributes::DESCRIPTION => TestConfig::DEFAULT_DESCRIPTION,
        ConfigAttributes::TYPE => TestConfig::DEFAULT_TYPE,
        ConfigAttributes::LICENSE => TestConfig::DEFAULT_LICENSE,
        ConfigAttributes::MINIMUM_STABILITY => TestConfig::DEFAULT_MINIMUM_STABILITY,
        ConfigAttributes::PREFER_STABLE => TestConfig::DEFAULT_PREFER_STABLE,
    ];
    private const NON_SCALAR_ATTRIBUTES = [
        ConfigAttributes::AUTOLOAD,
        ConfigAttributes::REQUIRE,
        ConfigAttributes::REPOSITORIES,
    ];
    private const TEST_VALUES = [
        ConfigAttributes::NAME => 'foo/bar',
        ConfigAttributes::DESCRIPTION => 'foo bar',
        ConfigAttributes::TYPE => 'foo type',
        ConfigAttributes::LICENSE => 'foo license',
        ConfigAttributes::MINIMUM_STABILITY => 'test',
        ConfigAttributes::PREFER_STABLE => false,
        ConfigAttributes::AUTOLOAD => [
            ConfigAttributes::PSR4 => [
                'Foo\\Bar\\' => 'src/Foo/Bar',
            ],
        ],
        ConfigAttributes::REQUIRE => [
            'foo/bar' => '^1.0.0',
        ],
        ConfigAttributes::REPOSITORIES => [[
            ConfigAttributes::TYPE => 'foo type',
            ConfigAttributes::URL => 'https://example.com/foo/bar.git',
        ]],
    ];

    public function test_create_with_arguments(): void
    {
        $name = self::TEST_VALUES[ConfigAttributes::NAME];
        $type = self::TEST_VALUES[ConfigAttributes::TYPE];

        $data = $this->createSerialization($name, $type);

        $this->assertSame(
            $name,
            $data[ConfigAttributes::NAME]
        );

        $this->assertSame(
            $type,
            $data[ConfigAttributes::TYPE]
        );
    }

    /**
     * @dataProvider provideDefaultValues
     */
    public function test_create_default(string $attribute, $value): void
    {
        $this->assertSame(
            $value,
            $this->createSerialization()[$attribute]
        );
    }

    /**
     * @dataProvider provideDefaultUnsetValues
     */
    public function test_create_default_unset_attributes(string $attribute): void
    {
        $this->assertArrayNotHasKey(
            $attribute,
            $this->createSerialization()
        );
    }

    /**
     * @dataProvider provideScalarValues
     */
    public function test_scalar_setters(string $attribute, $value): void
    {
        $setter = $this->getSetterFromAttribute($attribute);
        $instance = $this->createInstance();

        $instance->{$setter}($value);

        $this->assertSame(
            $value,
            $instance->toArray()[$attribute]
        );
    }

    public function test_add_autoload(): void
    {
        $instance = $this->createInstance();

        foreach (self::TEST_VALUES[ConfigAttributes::AUTOLOAD][ConfigAttributes::PSR4] as $namespace => $directory) {
            $instance->addAutoloadPsr4(
                $namespace,
                $directory
            );
        }

        $this->assertSame(
            self::TEST_VALUES[ConfigAttributes::AUTOLOAD],
            $instance->toArray()[ConfigAttributes::AUTOLOAD]
        );
    }

    public function test_add_dependency(): void
    {
        $instance = $this->createInstance();

        foreach (self::TEST_VALUES[ConfigAttributes::REQUIRE] as $packageName => $versionConstraint) {
            $instance->addRequire(
                $packageName,
                $versionConstraint
            );
        }

        $this->assertSame(
            self::TEST_VALUES[ConfigAttributes::REQUIRE],
            $instance->toArray()[ConfigAttributes::REQUIRE]
        );
    }

    public function test_add_repository(): void
    {
        $instance = $this->createInstance();

        foreach (self::TEST_VALUES[ConfigAttributes::REPOSITORIES] as $config) {
            $instance->addRepository(
                $config[ConfigAttributes::TYPE],
                $config[ConfigAttributes::URL]
            );
        }

        $this->assertSame(
            self::TEST_VALUES[ConfigAttributes::REPOSITORIES],
            $instance->toArray()[ConfigAttributes::REPOSITORIES]
        );
    }

    public function provideDefaultValues(): Generator
    {
        foreach (self::DEFAULT_SCALAR_VALUES as $key => $value) {
            yield [$key, $value];
        }
    }

    public function provideDefaultUnsetValues(): Generator
    {
        foreach (self::NON_SCALAR_ATTRIBUTES as $attribute) {
            yield [$attribute];
        }
    }

    public function provideScalarValues(): Generator
    {
        foreach (array_keys(self::DEFAULT_SCALAR_VALUES) as $attribute) {
            yield [$attribute, self::TEST_VALUES[$attribute]];
        }
    }

    private function createSerialization(?string $name = null, ?string $type = null): array
    {
        return $this->createInstance($name, $type)->jsonSerialize();
    }

    private function createInstance(?string $name = null, ?string $type = null): TestConfig
    {
        return TestConfig::create($name, $type);
    }

    private function getSetterFromAttribute(string $attribute): string
    {
        return 'set' . ucfirst(str_replace('-', '', $attribute));
    }
}
