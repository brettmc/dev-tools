<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Changelog;

use InvalidArgumentException;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogRewriter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Changelog\ChangelogRewriter
 * @covers \OpenTelemetry\DevTools\Package\Changelog\ChangelogException
 * @covers \OpenTelemetry\DevTools\Package\Changelog\CodeFences
 */
class ChangelogRewriterTest extends TestCase
{
    private const HEADER = "# Changelog\n\nNotable changes to `open-telemetry/sdk`.\n\n";
    private const BODY = "### Added\n\n- A new thing ([#1]).\n\n### Fixed\n\n- A bug.\n\n";
    private const TAIL = "## 1.5.0 and earlier\n\nSee the releases page.\n\n[#1]: https://example.com/1\n";

    private ChangelogRewriter $rewriter;

    #[\Override]
    public function setUp(): void
    {
        $this->rewriter = new ChangelogRewriter();
    }

    public function test_unreleased_becomes_the_dated_release_with_a_new_empty_unreleased_above(): void
    {
        $result = $this->rewriter->release(
            self::HEADER . "## Unreleased\n\n" . self::BODY . self::TAIL,
            '1.11.0',
            '2026-11-03'
        );

        $this->assertSame(
            self::HEADER . "## Unreleased\n\n## 1.11.0 - 2026-11-03\n\n" . self::BODY . self::TAIL,
            $result
        );
    }

    public function test_everything_but_the_heading_is_byte_identical(): void
    {
        $original = self::HEADER . "## Unreleased\n\n" . self::BODY . self::TAIL;

        $result = $this->rewriter->release($original, '1.11.0', '2026-11-03');

        $this->assertSame(
            $original,
            str_replace("## Unreleased\n\n## 1.11.0 - 2026-11-03\n", "## Unreleased\n", $result)
        );
    }

    public function test_crlf_line_endings_are_kept(): void
    {
        $original = str_replace("\n", "\r\n", self::HEADER . "## Unreleased\n\n" . self::BODY . self::TAIL);

        $result = $this->rewriter->release($original, '1.11.0', '2026-11-03');

        $this->assertSame(0, preg_match('/(?<!\r)\n/', $result));
        $this->assertStringContainsString("## Unreleased\r\n\r\n## 1.11.0 - 2026-11-03\r\n", $result);
    }

    public function test_trailing_whitespace_on_the_heading_is_accepted(): void
    {
        $result = $this->rewriter->release("## Unreleased  \n\n- a\n", '1.0.1', '2026-11-03');

        $this->assertSame("## Unreleased\n\n## 1.0.1 - 2026-11-03\n\n- a\n", $result);
    }

    public function test_only_the_first_unreleased_heading_is_changed(): void
    {
        $result = $this->rewriter->release("## Unreleased\n\n- a\n\n## Unreleased\n", '1.0.1', '2026-11-03');

        $this->assertSame("## Unreleased\n\n## 1.0.1 - 2026-11-03\n\n- a\n\n## Unreleased\n", $result);
    }

    public function test_unreleased_as_the_last_line_is_released(): void
    {
        $result = $this->rewriter->release(self::HEADER . '## Unreleased', '1.0.1', '2026-11-03');

        $this->assertSame(self::HEADER . "## Unreleased\n\n## 1.0.1 - 2026-11-03", $result);
    }

    public function test_heading_in_a_code_block_is_not_changed(): void
    {
        $code = "Write entries like this:\n\n```md\n## Unreleased\n\n- An entry.\n```\n\n";

        $result = $this->rewriter->release($code . "## Unreleased\n\n" . self::BODY, '1.0.1', '2026-11-03');

        $this->assertSame($code . "## Unreleased\n\n## 1.0.1 - 2026-11-03\n\n" . self::BODY, $result);
    }

    public function test_missing_unreleased_heading_is_an_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unreleased');

        $this->rewriter->release("# Changelog\n\n## 1.0.0 - 2026-01-01\n", '1.0.1', '2026-11-03');
    }
}
