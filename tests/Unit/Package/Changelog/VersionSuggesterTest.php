<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Changelog;

use Generator;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogSection;
use OpenTelemetry\DevTools\Package\Changelog\UnreleasedChanges;
use OpenTelemetry\DevTools\Package\Changelog\VersionSource;
use OpenTelemetry\DevTools\Package\Changelog\VersionSuggester;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Changelog\VersionSuggester
 * @covers \OpenTelemetry\DevTools\Package\Changelog\VersionSuggestion
 */
class VersionSuggesterTest extends TestCase
{
    /**
     * @dataProvider provideRules
     * @param array<string, list<string>> $entries
     */
    public function test_rule(string $latest, array $entries, ?string $expected): void
    {
        $suggestion = (new VersionSuggester())->suggest($latest, new UnreleasedChanges($entries));

        $this->assertSame($expected, $suggestion->version);
        $this->assertNotSame('', $suggestion->reason);
    }

    public static function provideRules(): Generator
    {
        yield 'no entries' => ['1.10.1', [], null];
        yield 'fixed only' => ['1.10.1', ['Fixed' => ['a']], '1.10.2'];
        yield 'security only' => ['1.10.1', ['Security' => ['a']], '1.10.2'];
        yield 'added' => ['1.10.1', ['Added' => ['a']], '1.11.0'];
        yield 'changed' => ['1.10.1', ['Changed' => ['a']], '1.11.0'];
        yield 'deprecated' => ['1.10.1', ['Deprecated' => ['a']], '1.11.0'];
        yield 'added and fixed' => ['1.10.1', ['Added' => ['a'], 'Fixed' => ['b']], '1.11.0'];
        yield 'breaking is still a minor' => ['1.10.1', ['Breaking changes' => ['a']], '1.11.0'];
        yield 'removed is still a minor' => ['1.10.1', ['Removed' => ['a']], '1.11.0'];
        yield '0.x added' => ['0.9.1', ['Added' => ['a']], '0.10.0'];
        yield '0.x fixed' => ['0.9.1', ['Fixed' => ['a']], '0.9.2'];
        yield 'v prefix' => ['v1.2.3', ['Fixed' => ['a']], '1.2.4'];
        yield 'two digit minor rolls over' => ['1.99.9', ['Added' => ['a']], '1.100.0'];
    }

    /**
     * @dataProvider provideReasons
     * @param array<string, list<string>> $entries
     */
    public function test_reason_names_the_section_that_decided_the_bump(array $entries, string $expected): void
    {
        $suggestion = (new VersionSuggester())->suggest('1.10.1', new UnreleasedChanges($entries));

        $this->assertSame($expected, $suggestion->reason);
    }

    public static function provideReasons(): Generator
    {
        yield 'breaking' => [['Breaking changes' => ['a']], 'breaking changes recorded'];
        yield 'removed' => [['Removed' => ['a']], 'removals recorded'];
        yield 'added' => [['Added' => ['a']], 'new features'];
        yield 'changed' => [['Changed' => ['a']], 'changes to existing behaviour'];
        yield 'deprecated' => [['Deprecated' => ['a']], 'deprecations'];
        yield 'fixed' => [['Fixed' => ['a']], 'only fixes'];
    }

    public function test_breaking_and_removed_entries_are_listed_as_warnings(): void
    {
        $suggestion = (new VersionSuggester())->suggest('1.10.1', new UnreleasedChanges([
            ChangelogSection::BreakingChanges->value => ['Drop X'],
            ChangelogSection::Removed->value => ['Remove Y'],
            ChangelogSection::Added->value => ['Add Z'],
        ]));

        $this->assertSame(['Drop X', 'Remove Y'], $suggestion->warnings);
    }

    public function test_no_warnings_without_breaking_entries(): void
    {
        $suggestion = (new VersionSuggester())->suggest('1.10.1', new UnreleasedChanges(['Added' => ['a']]));

        $this->assertSame([], $suggestion->warnings);
    }

    /**
     * @dataProvider providePreReleases
     */
    public function test_pre_release_latest_has_no_default(string $latest): void
    {
        $suggestion = (new VersionSuggester())->suggest($latest, new UnreleasedChanges(['Added' => ['a']]));

        $this->assertNull($suggestion->version);
        $this->assertStringContainsString('pre-release', $suggestion->reason);
    }

    public static function providePreReleases(): Generator
    {
        yield 'RC' => ['1.0.0-RC1'];
        yield 'RC without hyphen' => ['1.0.0RC1'];
        yield 'beta' => ['1.0.0beta6'];
    }

    public function test_unparseable_latest_has_no_default(): void
    {
        $suggestion = (new VersionSuggester())->suggest('latest', new UnreleasedChanges(['Added' => ['a']]));

        $this->assertNull($suggestion->version);
        $this->assertStringContainsString('cannot parse', $suggestion->reason);
    }

    public function test_newer_source_is_suggested_even_without_entries(): void
    {
        $suggestion = (new VersionSuggester())->suggest('1.43.0', new UnreleasedChanges([]), new VersionSource('1.44.0'));

        $this->assertSame('1.44.0', $suggestion->version);
    }

    public function test_source_not_newer_means_no_release(): void
    {
        $suggestion = (new VersionSuggester())->suggest('1.44.0', new UnreleasedChanges(['Fixed' => ['a']]), new VersionSource('1.44.0'));

        $this->assertNull($suggestion->version);
    }

    public function test_newer_source_wins_over_the_changelog_rule(): void
    {
        $suggestion = (new VersionSuggester())->suggest('1.10.0', new UnreleasedChanges(['Fixed' => ['a']]), new VersionSource('1.11.0'));

        $this->assertSame('1.11.0', $suggestion->version);
    }

    public function test_source_is_ignored_when_latest_is_a_pre_release(): void
    {
        $suggestion = (new VersionSuggester())->suggest('1.0.0RC1', new UnreleasedChanges(['Added' => ['a']]), new VersionSource('1.0.0'));

        $this->assertNull($suggestion->version);
    }
}
