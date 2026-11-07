<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Changelog;

use OpenTelemetry\DevTools\Package\Changelog\ChangelogException;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogReleases;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Changelog\ChangelogReleases
 * @covers \OpenTelemetry\DevTools\Package\Changelog\CodeFences
 * @covers \OpenTelemetry\DevTools\Package\Changelog\ChangelogException
 */
class ChangelogReleasesTest extends TestCase
{
    private const CHANGELOG = <<<'MD'
# Changelog

## Unreleased

### Added

- Not released yet.

## 1.45.0 - 2026-11-03

### Added

- New attributes ([#1]).

### Fixed

- A bug.

## 1.44.0 - 2026-10-01

### Changed

- Something.

## 1.0.0 and earlier

See the releases page.

[#1]: https://example.com/1
MD;

    public function test_latest_is_the_newest_dated_heading(): void
    {
        $this->assertSame('1.45.0', (new ChangelogReleases(self::CHANGELOG))->latest());
    }

    public function test_latest_is_null_when_nothing_has_been_dated(): void
    {
        $this->assertNull((new ChangelogReleases("# Changelog\n\n## Unreleased\n\n## 1.0.0 and earlier\n"))->latest());
    }

    public function test_notes_are_the_body_of_the_dated_section(): void
    {
        $this->assertSame(
            "### Added\n\n- New attributes ([#1]).\n\n### Fixed\n\n- A bug.\n\n[#1]: https://example.com/1",
            (new ChangelogReleases(self::CHANGELOG))->notes('1.45.0')
        );
    }

    public function test_only_the_link_definitions_the_notes_use_are_appended(): void
    {
        $changelog = "## 1.0.0 - 2026-01-01\n\n- A ([#1]).\n\n## 0.9.0 - 2025-01-01\n\n- B ([#2]).\n\n[#1]: https://x/1\n[#2]: https://x/2\n";

        $this->assertSame("- A ([#1]).\n\n[#1]: https://x/1", (new ChangelogReleases($changelog))->notes('1.0.0'));
    }

    public function test_definitions_at_the_end_of_the_last_section_are_not_repeated(): void
    {
        $changelog = "## 1.0.0 - 2026-01-01\n\n- A ([#1]).\n\n[#1]: https://x/1\n[#2]: https://x/2\n";

        $this->assertSame("- A ([#1]).\n\n[#1]: https://x/1", (new ChangelogReleases($changelog))->notes('1.0.0'));
    }

    public function test_notes_stop_at_the_next_heading(): void
    {
        $this->assertSame("### Changed\n\n- Something.", (new ChangelogReleases(self::CHANGELOG))->notes('1.44.0'));
    }

    public function test_notes_do_not_include_unreleased_entries(): void
    {
        $this->assertStringNotContainsString('Not released yet', (string) (new ChangelogReleases(self::CHANGELOG))->notes('1.45.0'));
    }

    public function test_notes_for_a_version_with_no_section_are_null(): void
    {
        $this->assertNull((new ChangelogReleases(self::CHANGELOG))->notes('1.46.0'));
    }

    public function test_notes_for_a_section_with_no_entries_are_null(): void
    {
        $changelog = "# Changelog\n\n## 1.45.0 - 2026-11-03\n\n## 1.44.0 - 2026-10-01\n\n- Something.\n";

        $this->assertNull((new ChangelogReleases($changelog))->notes('1.45.0'));
    }

    public function test_a_v_prefixed_tag_finds_the_section(): void
    {
        $this->assertNotNull((new ChangelogReleases(self::CHANGELOG))->notes('v1.45.0'));
    }

    public function test_a_prefix_of_a_version_is_not_a_match(): void
    {
        $this->assertNull((new ChangelogReleases(self::CHANGELOG))->notes('1.4'));
    }

    public function test_headings_in_a_code_block_are_not_headings(): void
    {
        $changelog = <<<'MD'
# Changelog

## 1.1.0 - 2026-02-01

- A new option:
  ```md
## 9.9.9 - 2026-01-01
[x]: https://example.com/x
  ```
- Another [x].

## 1.0.0 - 2026-01-01

- First.
MD;

        $this->assertSame('1.1.0', (new ChangelogReleases("```\n## 9.9.9 - 2026-01-01\n```\n" . $changelog))->latest());
        $this->assertSame(
            "- A new option:\n  ```md\n## 9.9.9 - 2026-01-01\n[x]: https://example.com/x\n  ```\n- Another [x].",
            (new ChangelogReleases($changelog))->notes('1.1.0')
        );
    }

    public function test_an_unclosed_code_block_is_an_error(): void
    {
        $changelog = "# Changelog\n\n## 1.1.0 - 2026-02-01\n\n- A new option:\n  ```md\n  a: 1\n\n## 1.0.0 - 2026-01-01\n\n- First.\n";

        try {
            new ChangelogReleases($changelog);
            $this->fail('No ChangelogException');
        } catch (ChangelogException $e) {
            $this->assertSame(6, $e->lineNumber);
            $this->assertStringContainsString('code block is not closed', $e->getMessage());
        }
    }

    public function test_crlf_line_endings_are_handled(): void
    {
        $changelog = str_replace("\n", "\r\n", self::CHANGELOG);

        $this->assertSame('1.45.0', (new ChangelogReleases($changelog))->latest());
        $this->assertStringContainsString('- A bug.', (string) (new ChangelogReleases($changelog))->notes('1.45.0'));
    }
}
