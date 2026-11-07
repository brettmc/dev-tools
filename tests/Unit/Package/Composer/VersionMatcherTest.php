<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer;

use Composer\Semver\VersionParser;
use OpenTelemetry\DevTools\Package\Composer\VersionMatcher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\VersionMatcher
 */
class VersionMatcherTest extends TestCase
{
    /**
     * @dataProvider versions
     */
    public function test_matches(string $constraint, ?string $version, bool $expected): void
    {
        $this->assertSame($expected, VersionMatcher::matches((new VersionParser())->parseConstraints($constraint), $version));
    }

    public static function versions(): iterable
    {
        yield 'in range' => ['^1.2', '1.4.0', true];
        yield 'below range' => ['^1.2', '1.1.9', false];
        yield 'v prefix' => ['^1.2', 'v1.2.0', true];
        yield 'dev alias' => ['^1.2', '1.3.x-dev', true];
        yield 'not a version' => ['^1.2', 'banana', false];
        yield 'no version' => ['^1.2', null, false];
    }
}
