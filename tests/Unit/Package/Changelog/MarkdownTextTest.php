<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Changelog;

use OpenTelemetry\DevTools\Package\Changelog\MarkdownText;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Changelog\MarkdownText
 * @covers \OpenTelemetry\DevTools\Package\Changelog\CodeFences
 */
class MarkdownTextTest extends TestCase
{
    public function test_outside_code_skips_spans_and_blocks(): void
    {
        $this->assertSame(
            "A `a` A ``b`` A\n```\na\n```\nA ` A",
            MarkdownText::outsideCode("a `a` a ``b`` a\n```\na\n```\na ` a", static fn (string $run): string => str_replace('a', 'A', $run))
        );
    }

    /**
     * @dataProvider sentences
     */
    public function test_first_sentence(string $text, string $first): void
    {
        $this->assertSame($first, MarkdownText::firstSentence($text));
    }

    public static function sentences(): iterable
    {
        yield 'two sentences' => ['One. Two.', 'One.'];
        yield 'one sentence' => ['Only one.', 'Only one.'];
        yield 'wrapped' => ["One\nwrapped. Two.", "One\nwrapped."];
        yield 'dot in code' => ['Call `a. b` first. Then.', 'Call `a. b` first.'];
        yield 'dot in a version' => ['Requires 1.17. Then.', 'Requires 1.17.'];
        yield 'question' => ['Why? Because.', 'Why?'];
        yield 'no end' => ["No end\nhere", 'No end'];
        yield 'code block' => ["Example:\n```php\n\$a = 1; \$b. 2;\n```", 'Example:'];
    }
}
