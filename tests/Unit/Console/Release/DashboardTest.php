<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Release;

use OpenTelemetry\DevTools\Console\Release\Dashboard;
use OpenTelemetry\DevTools\Console\Release\PrepEntry;
use OpenTelemetry\DevTools\Console\Release\Project;
use OpenTelemetry\DevTools\Console\Release\Repository;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogLinks;
use OpenTelemetry\DevTools\Package\Changelog\UnreleasedChanges;
use OpenTelemetry\DevTools\Package\Release\ReleaseOrderReport;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Console\Release\Dashboard
 */
class DashboardTest extends TestCase
{
    private const SHA = '841cc9a1b2c3d4e5f60718293a4b5c6d7e8f9012';
    private const INTRO = "Release dashboard for [open-telemetry/opentelemetry-php](https://github.com/open-telemetry/opentelemetry-php). A workflow rewrites this issue from `release:prep --dry-run`, so edits to it are lost.\n\n";
    private const AT = '`main` at [841cc9a](https://github.com/open-telemetry/opentelemetry-php/commit/' . self::SHA . ')';
    private const LINKS = "[#7]: https://github.com/open-telemetry/opentelemetry-php/pull/7\n";

    public function test_intro_names_the_monorepo_and_says_edits_are_lost(): void
    {
        $body = $this->render([$this->entry('api', '1.11.0', '1.11.1', ['Fixed' => ['D.']])], monorepo: 'open-telemetry/opentelemetry-php-contrib');

        $this->assertStringStartsWith(
            'Release dashboard for [open-telemetry/opentelemetry-php-contrib](https://github.com/open-telemetry/opentelemetry-php-contrib). '
            . "A workflow rewrites this issue from `release:prep --dry-run`, so edits to it are lost.\n\n",
            $body
        );
    }

    public function test_commit_is_a_link_so_it_resolves_from_another_tracker(): void
    {
        $body = $this->render([$this->entry('api', '1.11.0', '1.11.1', ['Fixed' => ['D.']])], monorepo: 'open-telemetry/opentelemetry-php-contrib');

        $this->assertStringContainsString('1 package would be released from `main` at [841cc9a](https://github.com/open-telemetry/opentelemetry-php-contrib/commit/' . self::SHA . ").\n", $body);
    }

    public function test_only_references_to_the_issue_tracker_are_bare(): void
    {
        $warning = 'Dropped X ([#8]). It was in core ([#7]).';
        $entry = $this->entry('contrib-x', '1.0.0', '2.0.0', ['Breaking changes' => [$warning]], warnings: [$warning]);
        $entry->links = new ChangelogLinks(self::LINKS . "[#8]: https://github.com/open-telemetry/opentelemetry-php-contrib/pull/8\n");

        $body = $this->render([$entry], monorepo: 'open-telemetry/opentelemetry-php-contrib', issues: 'open-telemetry/opentelemetry-php');

        $this->assertStringContainsString('- contrib-x: Dropped X ([#8](https://github.com/open-telemetry/opentelemetry-php-contrib/pull/8)). ... (#7)', $body);
        $this->assertStringContainsString('- Dropped X ([#8](https://github.com/open-telemetry/opentelemetry-php-contrib/pull/8)). It was in core (#7).', $body);
    }

    public function test_summary_table_order_and_unchanged_packages(): void
    {
        $body = $this->render([
            $this->entry('sdk', '1.16.1', '1.17.0', ['Breaking changes' => ['Dropped X.'], 'Added' => ['A.', 'B.'], 'Fixed' => ['C.']]),
            $this->entry('api', '1.11.0', '1.11.1', ['Fixed' => ['D.']]),
            $this->entry('context', '1.6.0', null, [], 'no unreleased changes'),
            $this->entry('sem-conv', '1.37.0', null, [], 'no unreleased changes'),
        ], new ReleaseOrderReport(['open-telemetry/api', 'open-telemetry/sdk']));

        $this->assertStringStartsWith(self::INTRO . '2 packages would be released from ' . self::AT . ".\n\n", $body);
        $this->assertStringContainsString(<<<'MD'
            | Package | Latest | Next | Changes |
            |---|---|---|---|
            | [sdk](https://github.com/open-telemetry/opentelemetry-php/blob/841cc9a1b2c3d4e5f60718293a4b5c6d7e8f9012/src/sdk/CHANGELOG.md) | [1.16.1](https://github.com/opentelemetry-php/sdk/releases/tag/1.16.1) | 1.17.0 | 1 breaking, 2 added, 1 fixed |
            | [api](https://github.com/open-telemetry/opentelemetry-php/blob/841cc9a1b2c3d4e5f60718293a4b5c6d7e8f9012/src/api/CHANGELOG.md) | [1.11.0](https://github.com/opentelemetry-php/api/releases/tag/1.11.0) | 1.11.1 | 1 fixed |

            Tag order: api, sdk.

            No changes: context, sem-conv.
            MD, $body);
    }

    public function test_one_package_is_singular(): void
    {
        $body = $this->render([$this->entry('api', '1.11.0', '1.11.1', ['Fixed' => ['D.']])]);

        $this->assertStringStartsWith(self::INTRO . '1 package would be released', $body);
        $this->assertStringNotContainsString('Tag order', $body);
        $this->assertStringNotContainsString('No changes', $body);
    }

    public function test_nothing_to_release(): void
    {
        $body = $this->render([$this->entry('api', '1.11.0', null, [], 'no unreleased changes')]);

        $this->assertSame(self::INTRO . 'Nothing would be released from ' . self::AT . ".\n\nNo changes: api.\n", $body);
    }

    public function test_package_without_a_version_shows_why_in_the_table(): void
    {
        $body = $this->render([
            $this->entry('sdk', null, null, ['Added' => ['A.']], 'skipped, no release to base a version on'),
            $this->entry('sem-conv', '1.37.0', '1.38.0', [], 'version source is newer than the latest release'),
        ]);

        $this->assertStringContainsString('| [sdk](https://github.com/open-telemetry/opentelemetry-php/blob/841cc9a1b2c3d4e5f60718293a4b5c6d7e8f9012/src/sdk/CHANGELOG.md) | none | skipped, no release to base a version on | 1 added |', $body);
        $this->assertStringContainsString('| [sem-conv](https://github.com/open-telemetry/opentelemetry-php/blob/841cc9a1b2c3d4e5f60718293a4b5c6d7e8f9012/src/sem-conv/CHANGELOG.md) | [1.37.0](https://github.com/opentelemetry-php/sem-conv/releases/tag/1.37.0) | 1.38.0 | version source is newer than the latest release |', $body);
    }

    public function test_package_without_a_changelog_is_listed_apart(): void
    {
        $body = $this->render([$this->entry('symfony', null, null, null, 'skipped, no CHANGELOG.md')]);

        $this->assertStringContainsString("No CHANGELOG.md: symfony.\n", $body);
        $this->assertStringNotContainsString('No changes', $body);
    }

    public function test_warnings_are_cut_to_the_first_sentence_keeping_references(): void
    {
        $warning = "Requires `sdk` 1.17 and `tbachert/spi`. The factories are registered\nthrough SPI, see `e.g. this` ([#7]).";
        $body = $this->render([$this->entry('exporter-otlp', '1.5.0', '1.6.0', ['Breaking changes' => [$warning]], warnings: [$warning])]);

        $this->assertStringContainsString("### Check before releasing\n\n- exporter-otlp: Requires `sdk` 1.17 and `tbachert/spi`. ... (#7)\n", $body);
    }

    public function test_warning_of_one_sentence_is_kept_whole(): void
    {
        $body = $this->render([$this->entry('sdk', '1.0.0', '2.0.0', [], 'set by --set', warnings: ['major release, but Unreleased records no breaking changes'])]);

        $this->assertStringContainsString("- sdk: major release, but Unreleased records no breaking changes\n", $body);
    }

    public function test_sentence_end_inside_code_is_not_the_end(): void
    {
        $warning = 'Use `a. B` now. Then more.';
        $body = $this->render([$this->entry('sdk', '1.0.0', '1.1.0', ['Removed' => [$warning]], warnings: [$warning])]);

        $this->assertStringContainsString('- sdk: Use `a. B` now. ...', $body);
    }

    public function test_constraint_warnings_and_undecided_packages(): void
    {
        $undecided = $this->entry('sdk-configuration', '1.0.0RC1', null, ['Added' => ['A.']], 'latest release 1.0.0RC1 is a pre-release');
        $undecided->undecided = true;
        $body = $this->render([$undecided], new ReleaseOrderReport([], ['open-telemetry/sdk requires open-telemetry/api ^1.11, which excludes 2.0.0']));

        $this->assertStringContainsString("### Check the constraints between packages\n\n- open-telemetry/sdk requires open-telemetry/api ^1.11, which excludes 2.0.0\n", $body);
        $this->assertStringContainsString("### No version suggested\n\nChoose one with `--set=<package>=<version>`:\n\n- sdk-configuration\n", $body);
    }

    public function test_each_package_with_changes_has_its_entries_collapsed(): void
    {
        $body = $this->render([
            $this->entry('sdk', '1.16.1', '1.17.0', [
                'Added' => ["Example ([#7]):\n```php\n\$x = [#7];\n```"],
                'Fixed' => ["A bug.\n- nested"],
            ]),
        ]);

        $this->assertStringEndsWith(<<<'MD'
            <details><summary>sdk 1.17.0</summary>

            #### Added

            - Example (#7):
              ```php
              $x = [#7];
              ```

            #### Fixed

            - A bug.
              - nested

            </details>

            MD, $body);
    }

    public function test_package_with_changes_but_no_version_is_collapsed_under_its_name(): void
    {
        $body = $this->render([$this->entry('sdk', null, null, ['Added' => ['A.']], 'skipped, no release to base a version on')]);

        $this->assertStringContainsString("<details><summary>sdk</summary>\n", $body);
    }

    public function test_html_in_an_entry_cannot_close_the_details(): void
    {
        $body = $this->render([$this->entry('sdk', '1.0.0', '1.1.0', ['Added' => ['Escapes </details> and <b>.']])]);

        $this->assertStringContainsString('- Escapes &lt;/details&gt; and &lt;b&gt;.', $body);
        $this->assertSame(1, substr_count($body, '</details>'));
    }

    public function test_body_over_the_limit_leaves_the_entries_out(): void
    {
        $long = str_repeat('word ', 14000);
        $body = $this->render([$this->entry('sdk', '1.0.0', '1.1.0', ['Added' => [$long]])]);

        $this->assertLessThanOrEqual(Dashboard::LIMIT, strlen($body));
        $this->assertStringNotContainsString('<details>', $body);
        $this->assertStringContainsString("The entries are left out, as they would make this too long for an issue.\n", $body);
        $this->assertStringContainsString('| [sdk](https://github.com/open-telemetry/opentelemetry-php/blob/841cc9a1b2c3d4e5f60718293a4b5c6d7e8f9012/src/sdk/CHANGELOG.md) | [1.0.0](https://github.com/opentelemetry-php/sdk/releases/tag/1.0.0) | 1.1.0 | 1 added |', $body);
    }

    /**
     * @param array<PrepEntry> $entries
     */
    private function render(array $entries, ?ReleaseOrderReport $order = null, string $monorepo = 'open-telemetry/opentelemetry-php', ?string $issues = null): string
    {
        return (new Dashboard($monorepo, 'main', self::SHA, $issues))->render($entries, $order ?? new ReleaseOrderReport());
    }

    /**
     * @param array<string, list<string>>|null $changes null for a package with no CHANGELOG.md
     * @param list<string> $warnings
     */
    private function entry(string $project, ?string $latest, ?string $version, ?array $changes, string $reason = 'new features', array $warnings = []): PrepEntry
    {
        $repository = new Repository();
        $repository->upstream = new Project('open-telemetry/opentelemetry-php');
        $repository->upstream->path = "src/{$project}";
        $repository->downstream = new Project("opentelemetry-php/{$project}");
        $entry = new PrepEntry($repository);
        $entry->package = "open-telemetry/{$project}";
        $entry->latest = $latest;
        $entry->version = $version;
        $entry->reason = $reason;
        $entry->warnings = $warnings;
        if ($changes !== null) {
            $entry->changes = new UnreleasedChanges($changes);
            $entry->links = new ChangelogLinks(self::LINKS);
        }

        return $entry;
    }
}
