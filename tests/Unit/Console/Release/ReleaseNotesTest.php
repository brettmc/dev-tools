<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Release;

use OpenTelemetry\DevTools\Console\Release\ReleaseNotes;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Console\Release\ReleaseNotes
 */
class ReleaseNotesTest extends TestCase
{
    private const DOWNSTREAM = 'opentelemetry-php/sem-conv';

    public function test_notes_are_the_changelog_section_and_a_compare_link(): void
    {
        $notes = (new ReleaseNotes())->build(self::DOWNSTREAM, '1.44.0', '1.45.0', "### Added\n\n- New attributes ([#7]).\n\n[#7]: https://example.com/7");

        $this->assertSame(
            "### Added\n\n- New attributes ([#7]).\n\n[#7]: https://example.com/7\n\n**Full Changelog**: https://github.com/opentelemetry-php/sem-conv/compare/1.44.0...1.45.0",
            $notes
        );
    }

    public function test_first_release_without_changelog_notes_is_an_initial_release(): void
    {
        $this->assertSame('Initial release', (new ReleaseNotes())->build(self::DOWNSTREAM, null, '0.1.0', null));
    }

    public function test_first_release_uses_changelog_notes_when_there_are_some(): void
    {
        $this->assertSame('- First.', (new ReleaseNotes())->build(self::DOWNSTREAM, null, '0.1.0', '- First.'));
    }
}
