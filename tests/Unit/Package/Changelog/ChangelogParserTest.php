<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Changelog;

use InvalidArgumentException;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogParser;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogSection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Changelog\ChangelogParser
 * @covers \OpenTelemetry\DevTools\Package\Changelog\ChangelogSection
 * @covers \OpenTelemetry\DevTools\Package\Changelog\UnreleasedChanges
 */
class ChangelogParserTest extends TestCase
{
    private const HEADER = <<<'MD'
        # Changelog

        Notable changes to `open-telemetry/sdk`.

        MD;

    private ChangelogParser $parser;

    #[\Override]
    public function setUp(): void
    {
        $this->parser = new ChangelogParser();
    }

    public function test_entries_are_grouped_by_section(): void
    {
        $changes = $this->parser->parseUnreleased(self::HEADER . <<<'MD'
            ## Unreleased

            ### Breaking changes

            - Drop the old thing ([#1]).

            ### Added

            - A new thing.
            - Another new thing.

            ### Fixed

            - A bug.

            MD);

        $this->assertSame(['Drop the old thing ([#1]).'], $changes->entries(ChangelogSection::BreakingChanges));
        $this->assertSame(['A new thing.', 'Another new thing.'], $changes->entries(ChangelogSection::Added));
        $this->assertSame(['A bug.'], $changes->entries(ChangelogSection::Fixed));
        $this->assertSame([], $changes->entries(ChangelogSection::Removed));
        $this->assertFalse($changes->isEmpty());
    }

    public function test_wrapped_entry_is_one_entry(): void
    {
        $changes = $this->parser->parseUnreleased(self::HEADER . <<<'MD'
            ## Unreleased

            ### Changed

            - A long entry that
              wraps onto a second line.
            - The next entry.

            MD);

        $this->assertSame(
            ['A long entry that wraps onto a second line.', 'The next entry.'],
            $changes->entries(ChangelogSection::Changed)
        );
    }

    public function test_nested_bullets_stay_on_their_own_lines(): void
    {
        $changes = $this->parser->parseUnreleased(self::HEADER . <<<'MD'
            ## Unreleased

            ### Changed

            - These used to parse:
              - one wrapped
                onto a second line
              - two
            - The next entry.

            MD);

        $this->assertSame(
            ["These used to parse:\n- one wrapped onto a second line\n- two", 'The next entry.'],
            $changes->entries(ChangelogSection::Changed)
        );
    }

    public function test_empty_unreleased_is_empty(): void
    {
        $changes = $this->parser->parseUnreleased(self::HEADER . <<<'MD'
            ## Unreleased

            ## 1.5.0 and earlier

            See the releases page.

            MD);

        $this->assertTrue($changes->isEmpty());
    }

    public function test_section_heading_with_no_entries_is_empty(): void
    {
        $changes = $this->parser->parseUnreleased(self::HEADER . <<<'MD'
            ## Unreleased

            ### Added

            MD);

        $this->assertTrue($changes->isEmpty());
    }

    public function test_released_sections_are_ignored(): void
    {
        $changes = $this->parser->parseUnreleased(self::HEADER . <<<'MD'
            ## Unreleased

            ### Added

            - New.

            ## 1.4.0 - 2026-01-01

            ### Removed

            - Old.

            ### Not a known heading

            - Ignored, as it is already released.

            MD);

        $this->assertSame(['New.'], $changes->entries(ChangelogSection::Added));
        $this->assertSame([], $changes->entries(ChangelogSection::Removed));
    }

    public function test_unknown_heading_under_unreleased_is_an_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Housekeeping');

        $this->parser->parseUnreleased(self::HEADER . <<<'MD'
            ## Unreleased

            ### Housekeeping

            - Tidied.

            MD);
    }

    public function test_missing_unreleased_heading_is_an_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unreleased');

        $this->parser->parseUnreleased(self::HEADER . "## 1.0.0 - 2026-01-01\n");
    }
}
