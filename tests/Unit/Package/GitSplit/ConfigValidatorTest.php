<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\GitSplit;

use Generator;
use OpenTelemetry\DevTools\Package\GitSplit\ConfigAttributes;
use OpenTelemetry\DevTools\Package\GitSplit\ConfigValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\GitSplit\ConfigValidator
 */
class ConfigValidatorTest extends TestCase
{
    private const VALID_CONFIG = [
        ConfigAttributes::SPLITS_ATTRIBUTE => [[
            ConfigAttributes::PREFIX_ATTRIBUTE => 'src/A',
            ConfigAttributes::TARGET_ATTRIBUTE => 'https://example.com/A/package.git',
        ],[
            ConfigAttributes::PREFIX_ATTRIBUTE => 'src/B',
            ConfigAttributes::TARGET_ATTRIBUTE => 'https://example.com/B/package.git',
        ],],
    ];

    private const INVALID_CONFIGS = [
        [],
        [ConfigAttributes::SPLITS_ATTRIBUTE => 'foo'],
        [ConfigAttributes::SPLITS_ATTRIBUTE => [
            ConfigAttributes::PREFIX_ATTRIBUTE => 'src/B',
            ConfigAttributes::TARGET_ATTRIBUTE => 'https://example.com/B/package.git',
        ]],
        [ConfigAttributes::SPLITS_ATTRIBUTE => [[
            ConfigAttributes::PREFIX_ATTRIBUTE => 'src/A',
        ]]],
        [ConfigAttributes::SPLITS_ATTRIBUTE => [[
            ConfigAttributes::TARGET_ATTRIBUTE => 'https://example.com/A/package.git',
        ]]],
    ];

    public function test_valid(): void
    {
        $this->assertTrue(
            ConfigValidator::create(self::VALID_CONFIG)->validate()
        );
    }

    /**
     * @dataProvider provideInvalidConfig
     */
    public function test_invalid(array $config): void
    {
        $this->assertFalse(
            ConfigValidator::create($config)->validate()
        );
    }

    public function provideInvalidConfig(): Generator
    {
        foreach (self::INVALID_CONFIGS as $config) {
            yield [$config];
        }
    }
}
