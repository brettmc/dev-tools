<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Release;

use OpenTelemetry\DevTools\Package\Release\PackageRelease;
use OpenTelemetry\DevTools\Package\Release\ReleaseOrderChecker;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Release\ReleaseOrderChecker
 * @covers \OpenTelemetry\DevTools\Package\Release\ReleaseOrderReport
 * @covers \OpenTelemetry\DevTools\Package\Release\PackageRelease
 */
class ReleaseOrderCheckerTest extends TestCase
{
    private ReleaseOrderChecker $checker;

    #[\Override]
    public function setUp(): void
    {
        $this->checker = new ReleaseOrderChecker();
    }

    public function test_packages_that_do_not_depend_on_each_other_have_no_order_and_no_warnings(): void
    {
        $report = $this->checker->check([
            new PackageRelease('o/api', '1.11.0', '1.10.1'),
            new PackageRelease('o/sdk', '1.16.0', '1.15.0', ['php' => '^8.1']),
        ]);

        $this->assertSame([], $report->order);
        $this->assertSame([], $report->warnings);
    }

    public function test_constraint_only_the_new_version_satisfies_orders_the_dependency_first(): void
    {
        $report = $this->checker->check([
            new PackageRelease('o/sdk', '1.16.0', '1.15.0', ['o/api' => '^1.11']),
            new PackageRelease('o/api', '1.11.0', '1.10.1'),
        ]);

        $this->assertSame(['o/api', 'o/sdk'], $report->order);
        $this->assertSame([], $report->warnings);
    }

    public function test_constraint_the_latest_tag_already_satisfies_needs_no_order(): void
    {
        $report = $this->checker->check([
            new PackageRelease('o/sdk', '1.16.0', '1.15.0', ['o/api' => '^1.10']),
            new PackageRelease('o/api', '1.11.0', '1.10.1'),
        ]);

        $this->assertSame([], $report->order);
    }

    public function test_order_follows_chains(): void
    {
        $report = $this->checker->check([
            new PackageRelease('o/exporter', '1.5.0', '1.4.0', ['o/sdk' => '^1.16']),
            new PackageRelease('o/sdk', '1.16.0', '1.15.0', ['o/api' => '^1.11']),
            new PackageRelease('o/api', '1.11.0', '1.10.1'),
        ]);

        $this->assertSame(['o/api', 'o/sdk', 'o/exporter'], $report->order);
    }

    public function test_package_dependency_only_the_new_version_satisfies_orders_the_dependency_first(): void
    {
        $report = $this->checker->check([
            new PackageRelease('o/sdk-configuration', '0.10.0', '0.9.0', ['o/sdk' => '^1.16'], [], ['o/exporter-otlp' => ['^1.0.5', '^1.5']]),
            new PackageRelease('o/exporter-otlp', '1.5.0', '1.4.0'),
        ]);

        $this->assertSame(['o/exporter-otlp', 'o/sdk-configuration'], $report->order);
        $this->assertSame([], $report->warnings);
    }

    public function test_package_dependency_that_excludes_the_new_version_warns(): void
    {
        $report = $this->checker->check([
            new PackageRelease('o/sdk-configuration', null, '0.9.0', [], [], ['o/exporter-otlp' => ['^1.5']]),
            new PackageRelease('o/exporter-otlp', '2.0.0', '1.5.0'),
        ]);

        $this->assertSame(
            ['o/sdk-configuration has #[PackageDependency] on o/exporter-otlp ^1.5, which excludes 2.0.0'],
            $report->warnings
        );
    }

    public function test_package_dependency_on_its_own_package_is_not_a_cycle(): void
    {
        $report = $this->checker->check([
            new PackageRelease('o/sdk-configuration', '0.10.0', '0.9.0', [], [], ['o/sdk-configuration' => ['^0.10']]),
        ]);

        $this->assertSame([], $report->order);
        $this->assertSame([], $report->warnings);
    }

    public function test_constraint_that_excludes_a_new_major_names_the_dependent(): void
    {
        $report = $this->checker->check([
            new PackageRelease('o/sdk', '2.0.0', '1.16.0'),
            new PackageRelease('o/exporter', null, '1.4.0', ['o/sdk' => '^1.16']),
        ]);

        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('o/exporter requires o/sdk ^1.16', $report->warnings[0]);
        $this->assertStringContainsString('2.0.0', $report->warnings[0]);
    }

    public function test_constraint_that_allows_both_majors_does_not_warn(): void
    {
        $report = $this->checker->check([
            new PackageRelease('o/sdk', '2.0.0', '1.16.0'),
            new PackageRelease('o/exporter', null, '1.4.0', ['o/sdk' => '^1.16 || ^2.0']),
        ]);

        $this->assertSame([], $report->warnings);
    }

    public function test_conflict_that_matches_the_new_version_warns(): void
    {
        $report = $this->checker->check([
            new PackageRelease('o/sdk', '2.0.0', '1.16.0'),
            new PackageRelease('o/exporter', null, '1.4.0', [], ['o/sdk' => '>=2.0']),
        ]);

        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('o/exporter conflicts with o/sdk >=2.0', $report->warnings[0]);
    }

    public function test_conflict_that_does_not_match_the_new_version_is_fine(): void
    {
        $report = $this->checker->check([
            new PackageRelease('o/sdk', '1.16.0', '1.15.0'),
            new PackageRelease('o/exporter', null, '1.4.0', [], ['o/sdk' => '<1.10']),
        ]);

        $this->assertSame([], $report->warnings);
    }

    public function test_package_not_being_released_is_not_a_dependency(): void
    {
        $report = $this->checker->check([
            new PackageRelease('o/sdk', null, '1.15.0', ['o/api' => '^2.0']),
            new PackageRelease('o/api', null, '1.10.1'),
        ]);

        $this->assertSame([], $report->order);
        $this->assertSame([], $report->warnings);
    }

    public function test_dependent_that_is_not_released_is_not_ordered(): void
    {
        $report = $this->checker->check([
            new PackageRelease('o/sdk', null, '1.15.0', ['o/api' => '^1.11']),
            new PackageRelease('o/api', '1.11.0', '1.10.1'),
        ]);

        $this->assertSame([], $report->order);
    }

    public function test_a_cycle_is_reported_and_every_package_is_still_listed(): void
    {
        $report = $this->checker->check([
            new PackageRelease('o/a', '1.1.0', '1.0.0', ['o/b' => '^1.1']),
            new PackageRelease('o/b', '1.1.0', '1.0.0', ['o/a' => '^1.1']),
        ]);

        $this->assertEqualsCanonicalizing(['o/a', 'o/b'], $report->order);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('circular', $report->warnings[0]);
    }

    public function test_a_cycle_names_only_its_members_not_what_waits_on_it(): void
    {
        $report = $this->checker->check([
            new PackageRelease('o/c', '1.1.0', '1.0.0', ['o/a' => '^1.1']),
            new PackageRelease('o/a', '1.1.0', '1.0.0', ['o/b' => '^1.1']),
            new PackageRelease('o/b', '1.1.0', '1.0.0', ['o/a' => '^1.1']),
        ]);

        $this->assertEqualsCanonicalizing(['o/a', 'o/b', 'o/c'], $report->order);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('circular dependency between o/a, o/b,', $report->warnings[0]);
    }

    public function test_unparseable_constraint_is_ignored(): void
    {
        $report = $this->checker->check([
            new PackageRelease('o/sdk', '1.16.0', '1.15.0', ['o/api' => 'not a constraint']),
            new PackageRelease('o/api', '1.11.0', '1.10.1'),
        ]);

        $this->assertSame([], $report->warnings);
        $this->assertSame([], $report->order);
    }

    public function test_dev_branch_constraint_is_ignored(): void
    {
        $report = $this->checker->check([
            new PackageRelease('o/sdk', '1.16.0', '1.15.0', ['o/api' => 'dev-main']),
            new PackageRelease('o/api', '1.11.0', '1.10.1'),
        ]);

        $this->assertSame([], $report->warnings);
    }
}
