<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Changelog;

use OpenTelemetry\DevTools\Package\Changelog\ChangelogLinks;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Changelog\ChangelogLinks
 * @covers \OpenTelemetry\DevTools\Package\Changelog\CodeFences
 */
class ChangelogLinksTest extends TestCase
{
    private const REPOSITORY = 'open-telemetry/opentelemetry-php';
    private const CHANGELOG = <<<'MD'
# Changelog

## Unreleased

### Fixed

- A bug ([#12]).

[#12]: https://github.com/open-telemetry/opentelemetry-php/pull/12
[#13]: https://github.com/open-telemetry/opentelemetry-php/issues/13
[#14]: https://github.com/open-telemetry/opentelemetry-php-contrib/pull/14
[GHSA-fc6f]: https://github.com/open-telemetry/opentelemetry-php/security/advisories/GHSA-fc6f
MD;

    private function inline(string $text, string $changelog = self::CHANGELOG): string
    {
        return (new ChangelogLinks($changelog))->inline($text, self::REPOSITORY);
    }

    public function test_pull_request_of_this_repository_becomes_an_autolink(): void
    {
        $this->assertSame('A bug (#12).', $this->inline('A bug ([#12]).'));
    }

    public function test_issue_of_this_repository_becomes_an_autolink(): void
    {
        $this->assertSame('See #13.', $this->inline('See [#13].'));
    }

    public function test_pull_request_of_another_repository_is_inlined(): void
    {
        $this->assertSame(
            'From [#14](https://github.com/open-telemetry/opentelemetry-php-contrib/pull/14).',
            $this->inline('From [#14].')
        );
    }

    public function test_pull_request_of_this_repository_under_another_label_is_inlined(): void
    {
        $changelog = self::CHANGELOG . "\n[the fix]: https://github.com/open-telemetry/opentelemetry-php/pull/12\n[#99]: https://github.com/open-telemetry/opentelemetry-php/pull/12\n";

        $this->assertSame(
            'See [the fix](https://github.com/open-telemetry/opentelemetry-php/pull/12) and [#99](https://github.com/open-telemetry/opentelemetry-php/pull/12).',
            $this->inline('See [the fix] and [#99].', $changelog)
        );
    }

    public function test_other_reference_is_inlined(): void
    {
        $this->assertSame(
            'Fixes [GHSA-fc6f](https://github.com/open-telemetry/opentelemetry-php/security/advisories/GHSA-fc6f).',
            $this->inline('Fixes [GHSA-fc6f].')
        );
    }

    public function test_labels_match_without_case(): void
    {
        $this->assertStringContainsString('[ghsa-fc6f](https://', $this->inline('Fixes [ghsa-fc6f].'));
    }

    public function test_undefined_reference_and_inline_link_are_left_alone(): void
    {
        $this->assertSame('[nope] and [#12](https://example.com)', $this->inline('[nope] and [#12](https://example.com)'));
    }

    public function test_full_reference_is_left_alone(): void
    {
        $this->assertSame('[the PR][#12]', $this->inline('[the PR][#12]'));
    }

    public function test_code_is_left_alone(): void
    {
        $this->assertSame(
            "Use `\$a[#12]` here (#12):\n```php\n\$b = [#12];\n```",
            $this->inline("Use `\$a[#12]` here ([#12]):\n```php\n\$b = [#12];\n```")
        );
    }

    public function test_escaped_bracket_is_left_alone(): void
    {
        $this->assertSame('A bug (\[#12]) and \\\\#12.', $this->inline('A bug (\[#12]) and \\\\[#12].'));
    }

    public function test_definition_inside_a_code_block_is_not_a_definition(): void
    {
        $changelog = "## Unreleased\n\n### Added\n\n- Example:\n  ```md\n[x]: https://example.com/x\n  ```\n";

        $this->assertSame('[x]', $this->inline('[x]', $changelog));
    }

    public function test_references_are_the_defined_labels_outside_code_once_each(): void
    {
        $links = new ChangelogLinks(self::CHANGELOG);

        $this->assertSame(['#14', '#12'], $links->references('[#14], `[#13]`, [nope], [#12] and [#14]'));
    }

    public function test_references_skip_an_escaped_bracket(): void
    {
        $this->assertSame(['#12'], (new ChangelogLinks(self::CHANGELOG))->references('\[#14] and \\\\[#12]'));
    }

    public function test_link_resolves_one_label(): void
    {
        $links = new ChangelogLinks(self::CHANGELOG);

        $this->assertSame('#12', $links->link('#12', self::REPOSITORY));
        $this->assertSame('[#14](https://github.com/open-telemetry/opentelemetry-php-contrib/pull/14)', $links->link('#14', self::REPOSITORY));
    }

    public function test_links_to_a_url_by_reference(): void
    {
        $links = new ChangelogLinks(self::CHANGELOG);

        $this->assertTrue($links->linksTo('A bug ([#12]).', 'https://github.com/open-telemetry/opentelemetry-php/pull/12'));
        $this->assertFalse($links->linksTo('A bug ([#13]).', 'https://github.com/open-telemetry/opentelemetry-php/pull/12'));
        $this->assertTrue((new ChangelogLinks("[PR]: https://x/1\n"))->linksTo('A bug ([pr]).', 'https://x/1'));
    }

    public function test_links_to_a_url_inline(): void
    {
        $links = new ChangelogLinks('');

        $this->assertTrue($links->linksTo('A bug ([#12](https://x/12)).', 'https://x/12'));
        $this->assertFalse($links->linksTo('A bug ([#12](https://x/123)).', 'https://x/12'));
    }

    public function test_links_to_a_url_by_full_or_collapsed_reference(): void
    {
        $links = new ChangelogLinks(self::CHANGELOG);
        $url = 'https://github.com/open-telemetry/opentelemetry-php/pull/12';

        $this->assertTrue($links->linksTo('A bug ([the PR][#12]).', $url));
        $this->assertTrue($links->linksTo('A bug ([#12][]).', $url));
        $this->assertFalse($links->linksTo('A bug ([#12][#13]).', $url));
    }

    public function test_links_to_a_url_inline_with_a_title_or_angle_brackets(): void
    {
        $links = new ChangelogLinks('');

        $this->assertTrue($links->linksTo('A bug ([#12](https://x/12 "the PR")).', 'https://x/12'));
        $this->assertTrue($links->linksTo("A bug ([#12](https://x/12 'the PR')).", 'https://x/12'));
        $this->assertTrue($links->linksTo('A bug ([#12](<https://x/12>)).', 'https://x/12'));
    }

    public function test_links_to_a_url_inline_with_parentheses_in_the_destination(): void
    {
        $this->assertTrue((new ChangelogLinks(''))->linksTo('A bug ([#12](https://x/fix_(old))).', 'https://x/fix_(old)'));
    }

    public function test_an_inline_link_elsewhere_does_not_resolve_its_label(): void
    {
        $links = new ChangelogLinks(self::CHANGELOG);
        $url = 'https://github.com/open-telemetry/opentelemetry-php/pull/12';

        $this->assertFalse($links->linksTo('A bug ([#12](https://example.com/fix_(old))).', $url));
        $this->assertFalse($links->linksTo('A bug ([#12](https://example.com/other)).', $url));
    }

    public function test_links_to_a_url_inline_with_code_in_the_label(): void
    {
        $this->assertTrue((new ChangelogLinks(''))->linksTo('Fix [`foo()`](https://x/12).', 'https://x/12'));
    }

    public function test_an_escaped_bracket_does_not_link(): void
    {
        $links = new ChangelogLinks(self::CHANGELOG);
        $url = 'https://github.com/open-telemetry/opentelemetry-php/pull/12';

        $this->assertFalse($links->linksTo('A bug (\[#12]).', $url));
        $this->assertFalse($links->linksTo('A bug (\[#12](https://github.com/open-telemetry/opentelemetry-php/pull/12)).', $url));
        $this->assertTrue($links->linksTo('A bug (\\\\[#12]).', $url));
    }

    public function test_an_undefined_reference_does_not_link(): void
    {
        $this->assertFalse((new ChangelogLinks(''))->linksTo('A bug ([#12]).', 'https://x/12'));
    }

    public function test_a_link_in_code_does_not_link(): void
    {
        $links = new ChangelogLinks(self::CHANGELOG);

        $this->assertFalse($links->linksTo('A bug (`[#12]`).', 'https://github.com/open-telemetry/opentelemetry-php/pull/12'));
        $this->assertFalse($links->linksTo('A bug (`[#12](https://x/12)`).', 'https://x/12'));
    }

    public function test_first_definition_wins(): void
    {
        $changelog = "[x]: https://example.com/1\n[x]: https://example.com/2\n";

        $this->assertSame('[x](https://example.com/1)', $this->inline('[x]', $changelog));
    }
}
