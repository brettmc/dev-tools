<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Release;

use OpenTelemetry\DevTools\Console\Release\Overrides;
use OpenTelemetry\DevTools\Console\Release\ReleaseBuild;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Console\Release\ReleaseBuild
 */
class ReleaseBuildTest extends TestCase
{
    private const SHA = '841cc9a1b2c3d4e5f60718293a4b5c6d7e8f9012';
    private const HASH = '646ae93db4df7370';

    public function test_trailers_name_main_the_breaking_hash_and_each_override(): void
    {
        $build = new ReleaseBuild(self::SHA, self::HASH, new Overrides(['api=1.11.0', 'sdk=skip']));

        $this->assertSame("Built-On: 841cc9a1b2c3d4e5f60718293a4b5c6d7e8f9012\nBreaking-Changes: 646ae93db4df7370\nOverride: api=1.11.0\nOverride: sdk=skip\n", $build->trailers());
    }

    public function test_trailers_without_breaking_changes_or_overrides_name_main_only(): void
    {
        $this->assertSame('Built-On: ' . self::SHA . "\n", (new ReleaseBuild(self::SHA, null, new Overrides()))->trailers());
    }

    public function test_a_message_ending_in_the_trailers_is_read_back(): void
    {
        $build = new ReleaseBuild(self::SHA, self::HASH, new Overrides(['api=1.11.0', 'sdk=skip']));

        $read = ReleaseBuild::fromMessage("Release 2026-11-14\n\nopen-telemetry/api 1.11.0\n\n" . $build->trailers());

        $this->assertEquals($build, $read);
    }

    public function test_crlf_and_trailing_blank_lines_are_read(): void
    {
        $read = ReleaseBuild::fromMessage("Release\r\n\r\nBuilt-On: " . self::SHA . "\r\nOverride: api=1.11.0\r\n\r\n");

        $this->assertNotNull($read);
        $this->assertSame(self::SHA, $read->main);
        $this->assertNull($read->breaking);
        $this->assertSame(['api=1.11.0'], $read->overrides->lines);
    }

    public function test_other_trailers_are_ignored(): void
    {
        $read = ReleaseBuild::fromMessage("Release\n\nBuilt-On: " . self::SHA . "\nSigned-off-by: someone\n");

        $this->assertNotNull($read);
        $this->assertSame([], $read->overrides->lines);
    }

    /**
     * @dataProvider notBuilds
     */
    public function test_a_message_without_a_build_record_is_none(string $message): void
    {
        $this->assertNull(ReleaseBuild::fromMessage($message));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notBuilds(): iterable
    {
        yield 'no trailers' => ["Release 2026-11-14\n\nopen-telemetry/api 1.11.0\n"];
        yield 'a merge' => ["Merge branch 'main' into otelbot/release\n"];
        yield 'empty' => [''];
        yield 'short sha' => ["Release\n\nBuilt-On: 841cc9a\n"];
        yield 'two shas' => ["Release\n\nBuilt-On: " . self::SHA . "\nBuilt-On: " . self::SHA . "\n"];
        yield 'not in the last paragraph' => ["Release\n\nBuilt-On: " . self::SHA . "\n\nMore text\n"];
        yield 'bad breaking hash' => ["Release\n\nBuilt-On: " . self::SHA . "\nBreaking-Changes: xyz\n"];
        yield 'two breaking hashes' => ["Release\n\nBuilt-On: " . self::SHA . "\nBreaking-Changes: " . self::HASH . "\nBreaking-Changes: " . self::HASH . "\n"];
    }
}
