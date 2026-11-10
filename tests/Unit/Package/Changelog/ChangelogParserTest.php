<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Changelog;

use InvalidArgumentException;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogException;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogParser;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogSection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Changelog\ChangelogParser
 * @covers \OpenTelemetry\DevTools\Package\Changelog\ChangelogException
 * @covers \OpenTelemetry\DevTools\Package\Changelog\CodeFences
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

    public function test_all_entries_are_in_section_order_not_file_order(): void
    {
        $changes = $this->parser->parseUnreleased(self::HEADER . <<<'MD'
            ## Unreleased

            ### Fixed

            - A bug.

            ### Deprecated

            ### Breaking changes

            - Drop the old thing.

            MD);

        $this->assertSame(['Breaking changes' => ['Drop the old thing.'], 'Fixed' => ['A bug.']], $changes->all());
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

    public function test_bracketed_unreleased_heading_is_not_unreleased(): void
    {
        $this->expectException(ChangelogException::class);
        $this->expectExceptionMessage('No "## Unreleased" section found');

        $this->parser->parseUnreleased(self::HEADER . "## [Unreleased]\n\n### Added\n\n- A new thing.\n");
    }

    public function test_unknown_heading_names_its_line(): void
    {
        $error = $this->error(self::HEADER . "## Unreleased\n\n### Housekeeping\n\n- Tidied.\n");

        $this->assertSame(6, $error->lineNumber);
        $this->assertStringContainsString('line 6', $error->getMessage());
    }

    /**
     * @dataProvider strayLines
     */
    public function test_line_that_is_not_an_entry_is_an_error(string $unreleased, int $line, string $message): void
    {
        $error = $this->error(self::HEADER . "## Unreleased\n\n" . $unreleased . "\n## 1.0.0 - 2026-01-01\n\n- Old.\n");

        $this->assertSame($line, $error->lineNumber);
        $this->assertStringContainsString("line {$line}", $error->getMessage());
        $this->assertStringContainsString($message, $error->getMessage());
    }

    public static function strayLines(): iterable
    {
        yield 'star bullet' => ["### Added\n\n* A new thing.\n", 8, '"- "'];
        yield 'plus bullet' => ["### Added\n\n+ A new thing.\n", 8, '"- "'];
        yield 'entry before any section' => ["- A new thing.\n", 6, 'under a ### heading'];
        yield 'paragraph' => ["### Added\n\nSome words about it.\n", 8, '"- "'];
        yield 'indented line after a blank line' => ["### Added\n\n- A new thing.\n\n  More about it.\n", 10, '"- "'];
        yield 'code block outside an entry' => ["### Added\n\n```php\n\$x = 1;\n```\n", 8, '"- "'];
    }

    public function test_second_unreleased_heading_is_an_error(): void
    {
        $error = $this->error(self::HEADER . "## Unreleased\n\n### Added\n\n- One.\n\n## Unreleased\n\n### Fixed\n\n- Two.\n");

        $this->assertSame(10, $error->lineNumber);
        $this->assertStringContainsString('second', $error->getMessage());
    }

    public function test_headings_in_a_code_block_are_not_headings(): void
    {
        $changes = $this->parser->parseUnreleased(self::HEADER . <<<'MD'
            ## Unreleased

            ### Added

            - A new option:
              ```yaml
            ## not a heading
            ### nor this
              ```
            - Another thing.

            ## 1.0.0 - 2026-01-01

            ```
            ## Unreleased
            ```

            MD);

        $this->assertSame(
            ["A new option:\n```yaml\n## not a heading\n### nor this\n```", 'Another thing.'],
            $changes->entries(ChangelogSection::Added)
        );
    }

    public function test_code_block_closes_only_on_a_matching_fence(): void
    {
        $changes = $this->parser->parseUnreleased(self::HEADER . <<<'MD'
            ## Unreleased

            ### Added

            - Markdown in markdown:
              ````md
              ```
              ~~~~
              ## not a heading
              ````

            MD);

        $this->assertSame(
            ["Markdown in markdown:\n````md\n```\n~~~~\n## not a heading\n````"],
            $changes->entries(ChangelogSection::Added)
        );
    }

    public function test_text_after_a_code_block_starts_a_new_line(): void
    {
        $changes = $this->parser->parseUnreleased(self::HEADER . <<<'MD'
            ## Unreleased

            ### Added

            - A new option:
              ```yaml
              a: 1
              ```
              which defaults
              to off.

            MD);

        $this->assertSame(
            ["A new option:\n```yaml\na: 1\n```\nwhich defaults to off."],
            $changes->entries(ChangelogSection::Added)
        );
    }

    public function test_unclosed_code_block_is_an_error(): void
    {
        $error = $this->error(self::HEADER . "## Unreleased\n\n### Added\n\n- A new option:\n  ```yaml\n  a: 1\n\n## 1.0.0 - 2026-01-01\n");

        $this->assertSame(9, $error->lineNumber);
        $this->assertStringContainsString('code block', $error->getMessage());
    }

    public function test_link_definitions_under_unreleased_are_not_entries(): void
    {
        $changes = $this->parser->parseUnreleased(self::HEADER . <<<'MD'
            ## Unreleased

            [#0]: https://example.com/0

            ### Added

            - A new thing ([#1]).
            [#1]: https://example.com/1
            [#2]:  https://example.com/2

            MD);

        $this->assertSame(['A new thing ([#1]).'], $changes->entries(ChangelogSection::Added));
    }

    public function test_link_definition_in_a_code_block_is_part_of_its_entry(): void
    {
        $changes = $this->parser->parseUnreleased(self::HEADER . <<<'MD'
            ## Unreleased

            ### Added

            - Markdown:
              ```md
            [#1]: https://example.com/1
              ```

            MD);

        $this->assertSame(["Markdown:\n```md\n[#1]: https://example.com/1\n```"], $changes->entries(ChangelogSection::Added));
    }

    public function test_unreleased_as_the_last_line_is_empty(): void
    {
        $this->assertTrue($this->parser->parseUnreleased(self::HEADER . '## Unreleased')->isEmpty());
    }

    private function error(string $markdown): ChangelogException
    {
        try {
            $this->parser->parseUnreleased($markdown);
        } catch (ChangelogException $e) {
            return $e;
        }
        $this->fail('No ChangelogException');
    }
}
