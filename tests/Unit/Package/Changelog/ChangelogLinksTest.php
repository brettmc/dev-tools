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

    public function test_link_resolves_one_label(): void
    {
        $links = new ChangelogLinks(self::CHANGELOG);

        $this->assertSame('#12', $links->link('#12', self::REPOSITORY));
        $this->assertSame('[#14](https://github.com/open-telemetry/opentelemetry-php-contrib/pull/14)', $links->link('#14', self::REPOSITORY));
    }

    public function test_first_definition_wins(): void
    {
        $changelog = "[x]: https://example.com/1\n[x]: https://example.com/2\n";

        $this->assertSame('[x](https://example.com/1)', $this->inline('[x]', $changelog));
    }
}
