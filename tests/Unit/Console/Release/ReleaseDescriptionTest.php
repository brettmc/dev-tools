<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Release;

use OpenTelemetry\DevTools\Console\Release\Overrides;
use OpenTelemetry\DevTools\Console\Release\ReleaseDescription;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Console\Release\ReleaseDescription
 */
class ReleaseDescriptionTest extends TestCase
{
    private const SHA = '841cc9a1b2c3d4e5f60718293a4b5c6d7e8f9012';
    private const MARKDOWN = "| Package | Latest | Suggested | Reason |\n|---|---|---|---|\n| opentelemetry-php/sdk | 1.15.0 | 2.0.0 | set by --set |\n\nTag in this order: open-telemetry/sdk\n";
    /** `jq -c | sha256sum` of the checks for warnings(), cut to 16 digits */
    private const HASH = '646ae93db4df7370';

    public function test_description_without_breaking_changes(): void
    {
        $body = $this->render(self::prep(), overrides: new Overrides(['sdk=2.0.0']));

        $this->assertSame(<<<'MD'
            Releases [open-telemetry/opentelemetry-php](https://github.com/open-telemetry/opentelemetry-php) from `main` at [841cc9a](https://github.com/open-telemetry/opentelemetry-php/commit/841cc9a1b2c3d4e5f60718293a4b5c6d7e8f9012).
            To bring in later changes to `main`, rebuild this pull request: tick Open a release PR on the
            release dashboard issue, or run the Release PR workflow. Update branch doesn't rebuild it. A
            rebuild keeps the overrides and the tick below, and replaces the rest of this description.
            Fix changelog entries in a pull request to `main`, then rebuild.

            | Package | Latest | Suggested | Reason |
            |---|---|---|---|
            | opentelemetry-php/sdk | 1.15.0 | 2.0.0 | set by --set |

            Tag in this order: open-telemetry/sdk

            ### Change a version

            Edit this description, with one `<package>=<version|skip>` per line in the block below. The
            package is the composer name or the split repository name.
            Saving rebuilds the pull request with them.

            ```overrides
            sdk=2.0.0
            ```

            ### What happens when you merge

            gitsplit pushes each package to its split repository. Then tag the packages in the order above
            with [release:run](https://github.com/opentelemetry-php/dev-tools#tag-a-release).

            MD, $body);
    }

    public function test_breaking_changes_ask_for_a_tick(): void
    {
        $body = $this->render(self::prep(self::warnings()));

        $this->assertStringContainsString(<<<'MD'

            Tag in this order: open-telemetry/sdk

            ### Breaking changes

            A package above records breaking changes, is a new major version, or breaks a constraint
            between packages. Read the warnings, then tick the box. The "Release approval" check fails
            until you do.

            - [ ] I have read the breaking changes
            <!-- breaking: 646ae93db4df7370 -->

            ### Change a version

            MD, $body);
    }

    public function test_the_hash_is_the_one_jq_and_sha256sum_give(): void
    {
        $this->assertStringContainsString('<!-- breaking: ' . self::HASH . ' -->', $this->render(self::prep(self::warnings())));
    }

    public function test_a_package_warning_alone_asks_for_a_tick(): void
    {
        $prep = self::prep(self::warnings());
        $prep['constraint_warnings'] = [];

        $this->assertStringContainsString(ReleaseDescription::APPROVAL . "\n<!-- breaking: ", $this->render($prep));
    }

    public function test_a_constraint_warning_alone_asks_for_a_tick(): void
    {
        $prep = self::prep();
        $prep['constraint_warnings'] = ['x'];

        $this->assertStringContainsString(ReleaseDescription::APPROVAL . "\n<!-- breaking: ", $this->render($prep));
    }

    public function test_the_tick_stays_while_the_warnings_are_the_same(): void
    {
        $previous = "Old\n\n" . ReleaseDescription::APPROVED . "\n<!-- breaking: " . self::HASH . " -->\n";

        $body = $this->render(self::prep(self::warnings()), $previous);

        $this->assertStringContainsString(ReleaseDescription::APPROVED . "\n<!-- breaking: " . self::HASH . " -->\n", $body);
        $this->assertStringNotContainsString(ReleaseDescription::APPROVAL, $body);
    }

    public function test_the_tick_goes_when_the_warnings_change(): void
    {
        $previous = ReleaseDescription::APPROVED . "\n<!-- breaking: 0000000000000000 -->\n";

        $this->assertStringContainsString(ReleaseDescription::APPROVAL . "\n", $this->render(self::prep(self::warnings()), $previous));
    }

    public function test_the_marker_without_the_tick_is_unticked(): void
    {
        $previous = ReleaseDescription::APPROVAL . "\n<!-- breaking: " . self::HASH . " -->\n";

        $this->assertStringContainsString(ReleaseDescription::APPROVAL . "\n", $this->render(self::prep(self::warnings()), $previous));
    }

    /**
     * @dataProvider ticks
     */
    public function test_a_tick_typed_by_hand_stays(string $tick): void
    {
        $previous = "{$tick}\n<!-- breaking: " . self::HASH . " -->\n";

        $this->assertStringContainsString(ReleaseDescription::APPROVED . "\n", $this->render(self::prep(self::warnings()), $previous));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ticks(): array
    {
        return [
            'capital X' => ['- [X] I have read the breaking changes'],
            'star bullet' => ['* [x] I have read the breaking changes'],
            'trailing space' => ['- [x] I have read the breaking changes '],
            'more spaces' => ['-  [x]  I have read the breaking changes'],
        ];
    }

    public function test_the_tick_must_be_a_whole_line(): void
    {
        $previous = '> ' . ReleaseDescription::APPROVED . "\n<!-- breaking: " . self::HASH . " -->\n";

        $this->assertStringContainsString(ReleaseDescription::APPROVAL . "\n", $this->render(self::prep(self::warnings()), $previous));
    }

    public function test_breaking_hash_is_the_one_in_the_marker(): void
    {
        $this->assertSame(self::HASH, ReleaseDescription::breakingHash(self::prep(self::warnings())));
    }

    public function test_no_warnings_have_no_breaking_hash(): void
    {
        $this->assertNull(ReleaseDescription::breakingHash(self::prep()));
    }

    public function test_approved_needs_the_tick_and_the_marker_for_the_hash(): void
    {
        $marker = '<!-- breaking: ' . self::HASH . ' -->';

        $this->assertTrue(ReleaseDescription::approved("Text\r\n* [X] I have read the breaking changes\r\n{$marker}\r\n", self::HASH));
        $this->assertFalse(ReleaseDescription::approved(ReleaseDescription::APPROVAL . "\n{$marker}\n", self::HASH), 'unticked');
        $this->assertFalse(ReleaseDescription::approved(ReleaseDescription::APPROVED . "\n", self::HASH), 'marker deleted');
        $this->assertFalse(ReleaseDescription::approved("{$marker}\n", self::HASH), 'box deleted');
        $this->assertFalse(ReleaseDescription::approved(ReleaseDescription::APPROVED . "\n<!-- breaking: 0000000000000000 -->\n", self::HASH), 'another hash');
    }

    public function test_contrib_is_rebuilt_by_hand(): void
    {
        $body = (new ReleaseDescription('open-telemetry/opentelemetry-php-contrib', self::SHA, false))
            ->render(self::MARKDOWN, self::prep(), '', new Overrides());

        $this->assertStringContainsString("split repository name.\nThen rebuild it.\n\n```overrides\n```\n", $body);
        $this->assertStringStartsWith('Releases [open-telemetry/opentelemetry-php-contrib](https://github.com/open-telemetry/opentelemetry-php-contrib) from `main` at [841cc9a](https://github.com/open-telemetry/opentelemetry-php-contrib/commit/' . self::SHA . ").\n", $body);
    }

    public function test_unmarked_drops_the_stale_note_and_the_blank_line_after_it(): void
    {
        $body = ReleaseDescription::STALE . "\r\n\r\nReleases x.\r\n\r\nMore.\r\n\r\n";

        $this->assertSame("Releases x.\n\nMore.", ReleaseDescription::unmarked($body));
    }

    public function test_unmarked_keeps_a_body_without_the_note(): void
    {
        $this->assertSame("Releases x.\n\nMore.", ReleaseDescription::unmarked("Releases x.\n\nMore.\n"));
    }

    public function test_mark_stale_puts_the_note_first(): void
    {
        $marked = ReleaseDescription::markStale("Releases x.\r\n\r\nMore.\r\n");

        $this->assertSame(ReleaseDescription::STALE . "\n\nReleases x.\n\nMore.\n", $marked);
        $this->assertTrue(ReleaseDescription::isStale($marked));
        $this->assertFalse(ReleaseDescription::isStale("Releases x.\n"));
        $this->assertFalse(ReleaseDescription::isStale('> ' . ReleaseDescription::STALE . "\n"));
    }

    /**
     * @param array<string, mixed> $prep
     */
    private function render(array $prep, string $previous = '', ?Overrides $overrides = null): string
    {
        return (new ReleaseDescription('open-telemetry/opentelemetry-php', self::SHA, true))
            ->render(self::MARKDOWN, $prep, $previous, $overrides ?? new Overrides());
    }

    /**
     * The JSON of release:prep, decoded.
     *
     * @param list<string> $warnings of open-telemetry/sdk
     * @return array<string, mixed>
     */
    private static function prep(array $warnings = []): array
    {
        return [
            'packages' => [
                ['package' => 'open-telemetry/api', 'repository' => 'opentelemetry-php/api', 'latest' => '1.10.1', 'version' => null, 'reason' => 'no unreleased changes', 'warnings' => [], 'undecided' => false, 'changes' => []],
                ['package' => 'open-telemetry/sdk', 'repository' => 'opentelemetry-php/sdk', 'latest' => '1.15.0', 'version' => '2.0.0', 'reason' => 'set by --set', 'warnings' => $warnings, 'undecided' => false, 'changes' => []],
            ],
            'order' => ['open-telemetry/sdk'],
            'constraint_warnings' => $warnings === [] ? [] : ['a/b ^1 is broken by 2.0.0'],
            'release' => true,
            'completed' => true,
        ];
    }

    /**
     * @return list<string>
     */
    private static function warnings(): array
    {
        return ['Dropped X.'];
    }
}
