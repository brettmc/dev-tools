<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Spi;

use OpenTelemetry\DevTools\Package\Spi\ProviderDependency;
use OpenTelemetry\DevTools\Package\Spi\SplitDependencyChecker;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Spi\SplitDependencyChecker
 * @covers \OpenTelemetry\DevTools\Package\Spi\SplitDependencyReport
 */
class SplitDependencyCheckerTest extends TestCase
{
    private const OTLP = 'open-telemetry/exporter-otlp';
    private const PROVIDER = 'Config\SpanExporterOtlpHttp';

    public function test_a_constraint_the_alias_and_replace_satisfy_passes(): void
    {
        $report = $this->check('^1.5', [self::OTLP => '1.5.x-dev'], [self::OTLP => '1.5.x-dev']);

        $this->assertFalse($report->failed());
        $this->assertSame([], $report->rows[0]->problems);
    }

    public function test_a_constraint_the_alias_does_not_satisfy_fails(): void
    {
        $report = $this->check('^1.5', [self::OTLP => '1.4.x-dev'], [self::OTLP => '1.5.x-dev']);

        $this->assertTrue($report->failed());
        $this->assertSame(['dev-main alias 1.4.x-dev does not satisfy ^1.5'], $report->rows[0]->problems);
    }

    public function test_a_root_replace_that_does_not_satisfy_the_constraint_fails(): void
    {
        $report = $this->check('^1.5', [self::OTLP => '1.5.x-dev'], [self::OTLP => '1.0.x-dev']);

        $this->assertTrue($report->failed());
        $this->assertSame(['root replace 1.0.x-dev does not satisfy ^1.5'], $report->rows[0]->problems);
    }

    public function test_a_split_package_missing_from_root_replace_fails(): void
    {
        $report = $this->check('^1.5', [self::OTLP => '1.5.x-dev'], []);

        $this->assertSame(['not in root replace'], $report->rows[0]->problems);
    }

    public function test_a_split_package_without_an_alias_fails(): void
    {
        $report = $this->check('^1.5', [self::OTLP => null], [self::OTLP => '1.5.x-dev']);

        $this->assertSame(['no dev-main alias'], $report->rows[0]->problems);
    }

    public function test_a_major_alias_covers_its_minors(): void
    {
        $report = $this->check('^1.5', [self::OTLP => '1.x-dev'], [self::OTLP => '1.x-dev']);

        $this->assertFalse($report->failed());
    }

    public function test_a_wildcard_is_satisfied_by_a_pre_1_alias(): void
    {
        $report = $this->check('*', [self::OTLP => '0.10.x-dev'], [self::OTLP => '0.10.x-dev']);

        $this->assertFalse($report->failed());
    }

    public function test_an_unparseable_constraint_fails(): void
    {
        $report = $this->check('not a constraint', [self::OTLP => '1.5.x-dev'], [self::OTLP => '1.5.x-dev']);

        $this->assertSame(['constraint does not parse'], $report->rows[0]->problems);
    }

    public function test_dependencies_on_other_packages_are_left_for_tbachert_spi(): void
    {
        $report = (new SplitDependencyChecker())->check(
            [
                new ProviderDependency('Dotenv', 'symfony/dotenv', '^7.0'),
                new ProviderDependency(self::PROVIDER, self::OTLP, '^1.5'),
            ],
            [self::OTLP => '1.5.x-dev'],
            [self::OTLP => '1.5.x-dev'],
        );

        $this->assertCount(1, $report->rows);
        $this->assertSame(self::OTLP, $report->rows[0]->dependency->package);
        $this->assertSame(['symfony/dotenv'], $report->external);
    }

    /**
     * @param array<string, ?string> $aliases
     * @param array<string, string> $replace
     */
    private function check(string $constraint, array $aliases, array $replace): \OpenTelemetry\DevTools\Package\Spi\SplitDependencyReport
    {
        return (new SplitDependencyChecker())->check(
            [new ProviderDependency(self::PROVIDER, self::OTLP, $constraint)],
            $aliases,
            $replace,
        );
    }
}
