<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Php;

use OpenTelemetry\DevTools\Package\Php\PhpReleaseStates;
use OpenTelemetry\DevTools\Package\Php\ProjectPhpVersions;
use OpenTelemetry\DevTools\Package\Php\SupportDocs;
use OpenTelemetry\DevTools\Package\Php\SupportPolicy;
use OpenTelemetry\DevTools\Package\Php\SupportReport;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesPhpStatesTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OpenTelemetry\DevTools\Package\Php\SupportDocs
 */
class SupportDocsTest extends TestCase
{
    use UsesPhpStatesTrait;

    public function test_it_writes_a_badge_per_supported_branch(): void
    {
        $blocks = (new SupportDocs())->blocks($this->report());

        $this->assertSame(
            "[![PHP 8.2](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)](docs/versioning.md#php-version-support)\n"
            . '[![PHP 8.3](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)](docs/versioning.md#php-version-support)',
            $blocks['php-support-badges'],
        );
    }

    /**
     * @dataProvider linksFromFiles
     */
    public function test_the_policy_link_is_relative_to_the_file(string $file, string $policyLink, string $expected): void
    {
        $blocks = (new SupportDocs($policyLink))->blocks($this->report(), $file);

        $this->assertStringContainsString("]({$expected})", $blocks['php-support-badges']);
        $this->assertStringContainsString("[PHP version support]({$expected})", $blocks['php-support-summary']);
    }

    public static function linksFromFiles(): iterable
    {
        yield 'a file in the root' => ['README.md', 'docs/versioning.md#php', 'docs/versioning.md#php'];
        yield 'a file in a directory' => ['docs/versioning.md', 'docs/versioning.md#php', '../docs/versioning.md#php'];
        yield 'a file in the root, with a dot' => ['./README.md', 'docs/versioning.md#php', 'docs/versioning.md#php'];
        yield 'a file in a directory, with a dot' => ['./docs/versioning.md', 'docs/versioning.md#php', '../docs/versioning.md#php'];
        yield 'a file outside the project' => ['/somewhere/else/README.md', 'docs/versioning.md#php', 'docs/versioning.md#php'];
        yield 'a file two directories down' => ['docs/php/support.md', 'docs/versioning.md#php', '../../docs/versioning.md#php'];
        yield 'a url' => ['docs/versioning.md', 'https://example.com/php', 'https://example.com/php'];
        yield 'an anchor' => ['docs/versioning.md', '#php', '#php'];
        yield 'an absolute path' => ['docs/versioning.md', '/docs/php', '/docs/php'];
        yield 'no file' => ['', 'docs/versioning.md#php', 'docs/versioning.md#php'];
    }

    public function test_it_summarizes_the_lowest_supported_branch(): void
    {
        $blocks = (new SupportDocs('docs/php.md#support'))->blocks($this->report());

        $this->assertSame(
            'The lowest supported PHP version, 8.2, is supported until 2027-12-31. See [PHP version support](docs/php.md#support) for the policy and the full schedule.',
            $blocks['php-support-summary'],
        );
    }

    public function test_it_tabulates_the_supported_branches(): void
    {
        $blocks = (new SupportDocs())->blocks($this->report());

        $this->assertSame(
            "| PHP | Security support ends | Supported until |\n"
            . "|-----|-----------------------|-----------------|\n"
            . "| 8.2 | 2026-12-31 | 2027-12-31 |\n"
            . '| 8.3 | 2027-12-31 | 2028-12-31 |',
            $blocks['php-support-table'],
        );
    }

    public function test_it_fails_when_no_branch_is_supported(): void
    {
        $report = new SupportReport([], $this->date('2026-11-05'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No PHP version is supported');

        (new SupportDocs())->blocks($report);
    }

    public function test_it_replaces_what_is_between_the_markers(): void
    {
        $docs = new SupportDocs();
        $text = "# Title\n\n<!-- php-support-table:start -->\nold\nlines\n<!-- php-support-table:end -->\n\nAfter\n";

        $updated = $docs->apply($text, ['php-support-table' => 'new']);

        $this->assertSame("# Title\n\n<!-- php-support-table:start -->\nnew\n<!-- php-support-table:end -->\n\nAfter\n", $updated);
    }

    public function test_it_fills_empty_markers(): void
    {
        $text = "<!-- php-support-table:start -->\n<!-- php-support-table:end -->\n";

        $this->assertSame("<!-- php-support-table:start -->\nnew\n<!-- php-support-table:end -->\n", (new SupportDocs())->apply($text, ['php-support-table' => 'new']));
    }

    public function test_it_keeps_dollar_signs_and_backslashes_of_the_new_text(): void
    {
        $text = "<!-- php-support-table:start -->\n<!-- php-support-table:end -->";

        $updated = (new SupportDocs())->apply($text, ['php-support-table' => 'costs $1 \\0']);

        $this->assertStringContainsString('costs $1 \\0', $updated);
    }

    public function test_it_leaves_the_blocks_a_file_does_not_have(): void
    {
        $text = "<!-- php-support-table:start -->\n<!-- php-support-table:end -->\n";
        $blocks = ['php-support-badges' => 'badges', 'php-support-table' => 'table'];

        $this->assertSame("<!-- php-support-table:start -->\ntable\n<!-- php-support-table:end -->\n", (new SupportDocs())->apply($text, $blocks));
    }

    public function test_it_keeps_the_line_endings_of_the_file(): void
    {
        $text = "# Title\r\n<!-- php-support-table:start -->\r\nold\r\n<!-- php-support-table:end -->\r\nAfter\r\n";

        $updated = (new SupportDocs())->apply($text, ['php-support-table' => "line 1\nline 2"]);

        $this->assertSame("# Title\r\n<!-- php-support-table:start -->\r\nline 1\r\nline 2\r\n<!-- php-support-table:end -->\r\nAfter\r\n", $updated);
    }

    public function test_it_is_idempotent(): void
    {
        $docs = new SupportDocs();
        $blocks = ['php-support-table' => 'table'];
        $once = $docs->apply("<!-- php-support-table:start -->\n<!-- php-support-table:end -->", $blocks);

        $this->assertSame($once, $docs->apply($once, $blocks));
    }

    public function test_it_fails_rather_than_empty_the_text_when_the_replacement_fails(): void
    {
        $text = "<!-- php-support-table:start -->\n" . str_repeat('x', 5000) . "\n<!-- php-support-table:end -->";
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '10');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Could not replace the php-support-table block');

            (new SupportDocs())->apply($text, ['php-support-table' => 'table']);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }
    }

    public function test_it_fails_when_the_text_has_no_block(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No PHP support block found');

        (new SupportDocs())->apply('Nothing here', ['php-support-table' => 'table']);
    }

    /**
     * @dataProvider brokenMarkers
     */
    public function test_it_fails_on_a_block_whose_markers_are_not_a_pair(string $text): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exactly once, in that order');

        (new SupportDocs())->apply($text, ['php-support-table' => 'table']);
    }

    public static function brokenMarkers(): iterable
    {
        yield 'only the start' => ["<!-- php-support-table:start -->\n"];
        yield 'only the end' => ["<!-- php-support-table:end -->\n"];
        yield 'end before start' => ["<!-- php-support-table:end -->\ntext\n<!-- php-support-table:start -->\n"];
        yield 'start mentioned before the block' => ["Use `<!-- php-support-table:start -->`.\n<!-- php-support-table:start -->\n<!-- php-support-table:end -->\n"];
        yield 'two blocks' => ["<!-- php-support-table:start -->\n<!-- php-support-table:end -->\n<!-- php-support-table:start -->\n<!-- php-support-table:end -->\n"];
    }

    private function report(): SupportReport
    {
        return (new SupportPolicy())->evaluate(
            PhpReleaseStates::parse($this->phpStatesJson()),
            new ProjectPhpVersions('8.2', ['8.2', '8.3']),
            $this->date('2026-11-05'),
        );
    }
}
