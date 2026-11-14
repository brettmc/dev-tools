<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Release;

use OpenTelemetry\DevTools\Console\Release\Overrides;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Console\Release\Overrides
 */
class OverridesTest extends TestCase
{
    public function test_lines_are_read_from_the_overrides_block(): void
    {
        $body = "Intro\n\n```overrides\napi=1.2.0\nsdk=skip\n```\n\nAfter\n";

        $this->assertSame(['api=1.2.0', 'sdk=skip'], Overrides::fromBody($body)->lines);
    }

    public function test_a_body_without_a_block_has_no_overrides(): void
    {
        $this->assertSame([], Overrides::fromBody("Intro\n\n```\napi=1.2.0\n```\n")->lines);
        $this->assertSame([], Overrides::fromBody('')->lines);
    }

    public function test_the_opening_fence_may_have_trailing_whitespace_only(): void
    {
        $this->assertSame(['api=1.2.0'], Overrides::fromBody("```overrides \t\napi=1.2.0\n```\n")->lines);
        $this->assertSame([], Overrides::fromBody(" ```overrides\napi=1.2.0\n```\n")->lines);
        $this->assertSame([], Overrides::fromBody("```overrides x\napi=1.2.0\n```\n")->lines);
    }

    public function test_the_block_ends_at_any_line_starting_with_a_fence(): void
    {
        $this->assertSame(['api=1.2.0'], Overrides::fromBody("```overrides\napi=1.2.0\n```text\nsdk=skip\n")->lines);
    }

    public function test_an_opening_fence_inside_the_block_does_not_close_it(): void
    {
        $this->assertSame(['api=1.2.0', 'sdk=skip'], Overrides::fromBody("```overrides\napi=1.2.0\n```overrides\nsdk=skip\n```\n")->lines);
    }

    public function test_only_the_first_block_counts(): void
    {
        $body = "```overrides\napi=1.2.0\n```\n\n```overrides\nsdk=skip\n```\n";

        $this->assertSame(['api=1.2.0'], Overrides::fromBody($body)->lines);
    }

    public function test_an_unclosed_block_runs_to_the_end(): void
    {
        $this->assertSame(['api=1.2.0', 'sdk=skip'], Overrides::fromBody("```overrides\napi=1.2.0\nsdk=skip")->lines);
    }

    public function test_carriage_returns_are_ignored(): void
    {
        $this->assertSame(['api=1.2.0'], Overrides::fromBody("```overrides\r\napi=1.2.0\r\n```\r\n")->lines);
    }

    public function test_blank_lines_are_dropped_and_whitespace_is_squeezed(): void
    {
        $body = "```overrides\n\n  api=1.2.0  \n \t \nnot  \t valid\n```\n";

        $this->assertSame(['api=1.2.0', 'not valid'], Overrides::fromBody($body)->lines);
    }

    public function test_invalid_lines_are_listed(): void
    {
        $overrides = Overrides::fromBody("```overrides\napi=1.2.0\napi=banana\nopen-telemetry/sdk=2.0.0-beta.1\nAPI=1.0.0\na/b/c=1.0.0\n\$(id)=skip\n```\n");

        $this->assertSame(['api=banana', 'API=1.0.0', 'a/b/c=1.0.0', '$(id)=skip'], $overrides->invalid());
    }

    public function test_valid_lines_have_nothing_invalid(): void
    {
        $overrides = Overrides::fromBody("```overrides\napi=1.2.0\nopen-telemetry/opentelemetry-auto-laravel=skip\nsem-conv=1.37.0-rc.1\n```\n");

        $this->assertSame([], $overrides->invalid());
    }

    public function test_overrides_with_the_same_lines_are_equal(): void
    {
        $a = Overrides::fromBody("Old\n```overrides\napi=1.2.0\n```\n");
        $b = Overrides::fromBody("New\n```overrides\n  api=1.2.0\n\n```\n");
        $c = Overrides::fromBody("```overrides\napi=1.3.0\n```\n");

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }
}
