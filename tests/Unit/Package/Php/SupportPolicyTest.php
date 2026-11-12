<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Php;

use OpenTelemetry\DevTools\Package\Php\PhpReleaseStates;
use OpenTelemetry\DevTools\Package\Php\ProjectPhpVersions;
use OpenTelemetry\DevTools\Package\Php\SupportPolicy;
use OpenTelemetry\DevTools\Package\Php\SupportReport;
use OpenTelemetry\DevTools\Package\Php\SupportRow;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesPhpStatesTrait;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Php\SupportPolicy
 * @covers \OpenTelemetry\DevTools\Package\Php\SupportReport
 * @covers \OpenTelemetry\DevTools\Package\Php\SupportRow
 */
class SupportPolicyTest extends TestCase
{
    use UsesPhpStatesTrait;

    /** every branch of the fixture, once 8.6 is released */
    private const ALL = ['8.2', '8.3', '8.4', '8.5', '8.6'];

    public function test_a_branch_is_supported_for_12_months_after_its_security_support_ends(): void
    {
        $until = (new SupportPolicy())->supportedUntil($this->branch('8.2', '2022-12-08', '2026-12-31'));

        $this->assertSame('2027-12-31', $until->format('Y-m-d'));
    }

    public function test_the_months_can_be_changed(): void
    {
        $until = (new SupportPolicy(6))->supportedUntil($this->branch('8.2', '2022-12-08', '2026-12-31'));

        $this->assertSame('2027-06-30', $until->format('Y-m-d'));
    }

    public function test_the_end_of_support_stays_in_the_month_it_falls_in(): void
    {
        $until = (new SupportPolicy())->supportedUntil($this->branch('8.5', '2025-11-20', '2028-02-29'));

        $this->assertSame('2029-02-28', $until->format('Y-m-d'));
    }

    public function test_it_lists_the_released_branches_from_the_minimum_with_their_dates(): void
    {
        $report = $this->evaluate('8.2', ['8.2', '8.3', '8.4', '8.5'], '2026-11-05');

        $this->assertSame(['8.2', '8.3', '8.4', '8.5'], $this->versions($report->rows));
        $this->assertSame(['8.2', '8.3', '8.4', '8.5'], $this->versions($report->supported()));
        $this->assertSame('2027-12-31', $report->rows[0]->supportedUntil->format('Y-m-d'));
        $this->assertSame([], $report->actions());
    }

    public function test_the_minimum_is_to_be_dropped_when_its_end_of_support_is_within_the_notice(): void
    {
        //2027-12-31 less 60 days
        $report = $this->evaluate('8.2', self::ALL, '2027-11-01');

        $this->assertSame(['8.2'], $this->versions($report->actions()));
        $this->assertSame(SupportRow::DROP, $report->actions()[0]->action);
    }

    public function test_the_minimum_is_kept_before_the_notice_starts(): void
    {
        $report = $this->evaluate('8.2', self::ALL, '2027-10-31');

        $this->assertSame([], $report->actions());
    }

    public function test_a_branch_above_the_minimum_is_not_dropped_even_when_its_end_is_near(): void
    {
        //8.3 is supported until 2028-12-31, but only the minimum, 8.2, is asked to be dropped
        $report = $this->evaluate('8.2', self::ALL, '2028-12-01');

        $this->assertSame(['8.2'], $this->versions($report->actions()));
    }

    public function test_a_released_branch_that_ci_does_not_test_is_to_be_added(): void
    {
        $report = $this->evaluate('8.2', ['8.2', '8.3', '8.4', '8.5'], '2026-11-20');

        $this->assertSame(['8.6'], $this->versions($report->actions()));
        $this->assertSame(SupportRow::ADD, $report->actions()[0]->action);
        $this->assertFalse($report->actions()[0]->isSupported());
        $this->assertSame(['8.2', '8.3', '8.4', '8.5'], $this->versions($report->supported()));
    }

    public function test_it_fails_when_the_minimum_is_not_a_listed_branch(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PHP 0.0, the minimum the project requires, is not a branch php.net lists');

        $this->evaluate('0.0', ['8.2'], '2026-11-05');
    }

    public function test_a_branch_whose_support_has_ended_is_not_added(): void
    {
        //8.1 is supported until 2026-12-31, and CI no longer tests it. 8.0, the minimum, is the one to drop
        $report = $this->evaluate('8.0', ['8.0', '8.2', '8.3', '8.4', '8.5', '8.6'], '2027-06-01');

        $this->assertSame(['8.0'], $this->versions($report->actions()));
        $this->assertSame(SupportRow::DROP, $report->actions()[0]->action);
        $this->assertContains('8.1', $this->versions($report->rows));
    }

    public function test_a_branch_is_added_on_the_last_day_of_its_support_and_not_after(): void
    {
        //8.1 is supported until 2026-12-31, and is above the minimum 8.0
        $ci = ['8.0', '8.2', '8.3', '8.4', '8.5', '8.6'];

        $lastDay = $this->evaluate('8.0', $ci, '2026-12-31');
        $after = $this->evaluate('8.0', $ci, '2027-01-01');

        $this->assertSame(SupportRow::ADD, $this->row($lastDay, '8.1')->action);
        $this->assertNull($this->row($after, '8.1')->action);
    }

    public function test_a_branch_is_not_added_before_it_is_released(): void
    {
        $report = $this->evaluate('8.2', ['8.2', '8.3', '8.4', '8.5'], '2026-11-18');

        $this->assertNotContains('8.6', $this->versions($report->rows));
    }

    public function test_a_minimum_that_ci_does_not_test_is_to_be_added(): void
    {
        $report = $this->evaluate('8.2', ['8.3', '8.4', '8.5', '8.6'], '2027-06-01');

        $this->assertSame(['8.2'], $this->versions($report->actions()));
        $this->assertSame(SupportRow::ADD, $report->actions()[0]->action);
    }

    public function test_a_minimum_that_ci_does_not_test_is_dropped_rather_than_added_near_its_end(): void
    {
        $report = $this->evaluate('8.2', ['8.3', '8.4', '8.5', '8.6'], '2027-12-01');

        $this->assertSame(['8.2'], $this->versions($report->actions()));
        $this->assertSame(SupportRow::DROP, $report->actions()[0]->action);
    }

    public function test_the_notice_can_be_changed(): void
    {
        $project = new ProjectPhpVersions('8.2', self::ALL);

        $report = (new SupportPolicy(12, 90))->evaluate(PhpReleaseStates::parse($this->phpStatesJson()), $project, $this->date('2027-10-02'));

        $this->assertSame(['8.2'], $this->versions($report->actions()));
    }

    /**
     * @param list<string> $ci
     */
    private function evaluate(string $minimum, array $ci, string $today): SupportReport
    {
        return (new SupportPolicy())->evaluate(
            PhpReleaseStates::parse($this->phpStatesJson()),
            new ProjectPhpVersions($minimum, $ci),
            $this->date($today),
        );
    }

    private function row(SupportReport $report, string $version): SupportRow
    {
        foreach ($report->rows as $row) {
            if ($row->branch->version === $version) {
                return $row;
            }
        }

        $this->fail("No row for PHP {$version}");
    }

    /**
     * @param list<SupportRow> $rows
     * @return list<string>
     */
    private function versions(array $rows): array
    {
        return array_map(static fn (SupportRow $row): string => $row->branch->version, $rows);
    }
}
