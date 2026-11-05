<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer;

use OpenTelemetry\DevTools\Package\Composer\ReplaceUpdater;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsTrait;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\ReplaceUpdater
 */
class ReplaceUpdaterTest extends TestCase
{
    use UsesVfsTrait;

    private const CONFIG = <<<'JSON'
        {
            "name": "open-telemetry/opentelemetry",
            "require": {
                "open-telemetry/api": "1.0.x-dev"
            },
            "replace": {
                "open-telemetry/api": "1.0.x-dev",
                "open-telemetry/sdk": "1.0.x-dev",
                "open-telemetry/sdk-contrib": "1.0.x-dev"
            }
        }
        JSON;

    #[\Override]
    public function setUp(): void
    {
        $this->setUpVcs();
    }

    public function test_stale_entries_are_rewritten_and_nothing_else_changes(): void
    {
        $path = $this->file(self::CONFIG);

        $changes = (new ReplaceUpdater())->update($path, [
            'open-telemetry/api' => '1.11.x-dev',
            'open-telemetry/sdk' => '1.0.x-dev',
        ]);

        $this->assertSame(['open-telemetry/api' => ['1.0.x-dev', '1.11.x-dev']], $changes);
        $this->assertSame(
            str_replace('"open-telemetry/api": "1.0.x-dev",', '"open-telemetry/api": "1.11.x-dev",', self::CONFIG),
            file_get_contents($path)
        );
    }

    public function test_a_package_not_in_replace_is_left_out(): void
    {
        $path = $this->file(self::CONFIG);

        $changes = (new ReplaceUpdater())->update($path, ['open-telemetry/context' => '1.5.x-dev']);

        $this->assertSame([], $changes);
        $this->assertSame(self::CONFIG, file_get_contents($path));
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $path = $this->file(self::CONFIG);

        $changes = (new ReplaceUpdater())->update($path, ['open-telemetry/api' => '1.11.x-dev'], true);

        $this->assertSame(['open-telemetry/api' => ['1.0.x-dev', '1.11.x-dev']], $changes);
        $this->assertSame(self::CONFIG, file_get_contents($path));
    }

    public function test_a_read_only_file_fails(): void
    {
        $path = $this->file(self::CONFIG);
        $this->root->getChild('composer.json')->chmod(0o444);

        $this->expectException(RuntimeException::class);

        (new ReplaceUpdater())->update($path, ['open-telemetry/api' => '1.11.x-dev']);
    }

    private function file(string $content): string
    {
        return vfsStream::newFile('composer.json')->withContent($content)->at($this->root)->url();
    }
}
