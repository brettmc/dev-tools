<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer;

use Generator;
use OpenTelemetry\DevTools\Package\Composer\BranchAliasStatus;
use OpenTelemetry\DevTools\Package\Composer\BranchAliasUpdater;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsConstants;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsTrait;
use org\bovigo\vfs\Quota;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\BranchAliasUpdater
 */
class BranchAliasUpdaterTest extends TestCase
{
    use UsesVfsTrait;

    private const COMPOSER_FILE_NAME = 'composer.json';
    private const BRANCH_KEY = 'dev-main';
    private const CONFIG = <<<'JSON'
        {
            "name": "open-telemetry/api",
            "require": {
                "open-telemetry/context": "^1.0"
            },
            "extra": {
                "branch-alias": {
                    "dev-main": "1.8.x-dev"
                }
            }
        }
        JSON;

    private BranchAliasUpdater $updater;

    #[\Override]
    public function setUp(): void
    {
        $this->setUpVcs();
        $this->updater = new BranchAliasUpdater();
    }

    /**
     * @dataProvider provideVersions
     */
    public function test_alias_for_version(string $version, ?string $expected): void
    {
        $this->assertSame($expected, BranchAliasUpdater::aliasForVersion($version));
    }

    public function provideVersions(): Generator
    {
        yield ['1.10.0', '1.10.x-dev'];
        yield ['v1.10.0', '1.10.x-dev'];
        yield ['0.1.3', '0.1.x-dev'];
        yield ['1.0.0beta2', '1.0.x-dev'];
        yield ['1.44.0', '1.44.x-dev'];
        yield ['1', null];
        yield ['main', null];
        yield ['', null];
    }

    public function test_update_replaces_alias(): void
    {
        $path = $this->createConfigFile(self::CONFIG);

        $update = $this->updater->update($path, self::BRANCH_KEY, '1.10.x-dev');

        $this->assertSame(BranchAliasStatus::Updated, $update->status);
        $this->assertSame('1.8.x-dev', $update->current);
        $this->assertSame(
            str_replace('1.8.x-dev', '1.10.x-dev', self::CONFIG),
            file_get_contents($path),
            'only the alias value should change'
        );
    }

    public function test_update_normalizes_major_only_alias(): void
    {
        $path = $this->createConfigFile(
            str_replace('1.8.x-dev', '1.x-dev', self::CONFIG)
        );

        $update = $this->updater->update($path, self::BRANCH_KEY, '1.44.x-dev');

        $this->assertSame(BranchAliasStatus::Updated, $update->status);
        $this->assertSame('1.x-dev', $update->current);
        $this->assertStringContainsString('"dev-main": "1.44.x-dev"', (string) file_get_contents($path));
    }

    public function test_update_is_unchanged_when_alias_matches(): void
    {
        $path = $this->createConfigFile(self::CONFIG);

        $update = $this->updater->update($path, self::BRANCH_KEY, '1.8.x-dev');

        $this->assertSame(BranchAliasStatus::Unchanged, $update->status);
        $this->assertSame('1.8.x-dev', $update->current);
        $this->assertSame(self::CONFIG, file_get_contents($path));
    }

    /**
     * sem-conv follows the upstream semconv version, so a minor-line alias would go stale on every
     * regeneration. `1.x-dev` covers 1.45.0, and is kept.
     */
    public function test_update_is_unchanged_when_alias_covers_the_version(): void
    {
        $config = str_replace('1.8.x-dev', '1.x-dev', self::CONFIG);
        $path = $this->createConfigFile($config);

        $update = $this->updater->update($path, self::BRANCH_KEY, '1.45.x-dev', version: '1.45.0');

        $this->assertSame(BranchAliasStatus::Unchanged, $update->status);
        $this->assertSame('1.x-dev', $update->current);
        $this->assertSame($config, file_get_contents($path));
    }

    public function test_update_replaces_alias_that_does_not_cover_the_version(): void
    {
        $path = $this->createConfigFile(str_replace('1.8.x-dev', '1.10.x-dev', self::CONFIG));

        $update = $this->updater->update($path, self::BRANCH_KEY, '1.11.x-dev', version: '1.11.0');

        $this->assertSame(BranchAliasStatus::Updated, $update->status);
        $this->assertStringContainsString('"dev-main": "1.11.x-dev"', (string) file_get_contents($path));
    }

    /**
     * An alias higher than any release (eg `1.0.x-dev` for a package still releasing 0.x) is
     * aspirational, and must not be lowered.
     */
    public function test_update_skips_alias_ahead_of_releases(): void
    {
        $path = $this->createConfigFile(
            str_replace('1.8.x-dev', '1.0.x-dev', self::CONFIG)
        );

        $update = $this->updater->update($path, self::BRANCH_KEY, '0.0.x-dev');

        $this->assertSame(BranchAliasStatus::Ahead, $update->status);
        $this->assertSame('1.0.x-dev', $update->current);
        $this->assertStringContainsString('"dev-main": "1.0.x-dev"', (string) file_get_contents($path));
    }

    /**
     * A version chosen for release outranks an alias ahead of it: sdk-configuration, aliased
     * `1.0.x-dev`, is being released as 0.10.0.
     */
    public function test_update_lowers_an_alias_when_allowed(): void
    {
        $path = $this->createConfigFile(
            str_replace('1.8.x-dev', '1.0.x-dev', self::CONFIG)
        );

        $update = $this->updater->update($path, self::BRANCH_KEY, '0.10.x-dev', allowLower: true);

        $this->assertSame(BranchAliasStatus::Lowered, $update->status);
        $this->assertSame('1.0.x-dev', $update->current);
        $this->assertStringContainsString('"dev-main": "0.10.x-dev"', (string) file_get_contents($path));
    }

    /**
     * @dataProvider provideCoveredVersions
     */
    public function test_covers(string $alias, string $version, bool $expected): void
    {
        $this->assertSame($expected, BranchAliasUpdater::covers($alias, $version));
    }

    public function provideCoveredVersions(): Generator
    {
        yield 'same minor' => ['1.11.x-dev', '1.11.0', true];
        yield 'same minor, patch' => ['1.11.x-dev', '1.11.3', true];
        yield 'older minor' => ['1.10.x-dev', '1.11.0', false];
        yield 'newer minor' => ['1.12.x-dev', '1.11.0', false];
        yield 'major alias' => ['1.x-dev', '1.11.1', true];
        yield 'major alias, other major' => ['1.x-dev', '2.0.0', false];
        yield 'pre-1' => ['0.10.x-dev', '0.10.0', true];
        yield 'v prefix' => ['1.11.x-dev', 'v1.11.0', true];
        yield 'not an alias' => ['dev-main', '1.11.0', false];
    }

    public function test_update_does_not_write_on_dry_run(): void
    {
        $path = $this->createConfigFile(self::CONFIG);

        $update = $this->updater->update($path, self::BRANCH_KEY, '1.10.x-dev', true);

        $this->assertSame(BranchAliasStatus::Updated, $update->status);
        $this->assertSame(self::CONFIG, file_get_contents($path));
    }

    /**
     * @dataProvider provideConfigsWithoutAlias
     */
    public function test_update_reports_missing_alias(string $config): void
    {
        $path = $this->createConfigFile($config);

        $update = $this->updater->update($path, self::BRANCH_KEY, '1.10.x-dev');

        $this->assertSame(BranchAliasStatus::Missing, $update->status);
        $this->assertNull($update->current);
        $this->assertSame($config, file_get_contents($path));
    }

    public function provideConfigsWithoutAlias(): Generator
    {
        yield 'no extra' => ['{"name":"open-telemetry/contrib-aws"}'];
        yield 'no branch-alias' => ['{"extra":{"bamarni-bin":{"bin-links":false}}}'];
        yield 'other branch' => ['{"extra":{"branch-alias":{"dev-1.x":"1.8.x-dev"}}}'];
    }

    public function test_update_reports_missing_file(): void
    {
        $update = $this->updater->update(
            vfsStream::url(UsesVfsConstants::ROOT_DIR . '/nope/composer.json'),
            self::BRANCH_KEY,
            '1.10.x-dev'
        );

        $this->assertSame(BranchAliasStatus::NotFound, $update->status);
        $this->assertNull($update->current);
    }

    public function test_update_reports_ambiguous_alias(): void
    {
        $config = '{"require":{"dev-main":"1.8.x-dev"},"extra":{"branch-alias":{"dev-main":"1.8.x-dev"}}}';
        $path = $this->createConfigFile($config);

        $update = $this->updater->update($path, self::BRANCH_KEY, '1.10.x-dev');

        $this->assertSame(BranchAliasStatus::Ambiguous, $update->status);
        $this->assertSame('1.8.x-dev', $update->current);
        $this->assertSame($config, file_get_contents($path));
    }

    public function test_update_reports_write_failed_for_a_read_only_file(): void
    {
        $path = $this->createConfigFile(self::CONFIG);
        $this->root->getChild(self::COMPOSER_FILE_NAME)->chmod(0o444);

        $update = $this->updater->update($path, self::BRANCH_KEY, '1.10.x-dev');

        $this->assertSame(BranchAliasStatus::WriteFailed, $update->status);
        $this->assertSame('1.8.x-dev', $update->current);
        $this->assertSame(self::CONFIG, file_get_contents($path));
    }

    /**
     * A full disk, where the write is accepted but truncated: the damage is real, so it must not be
     * reported as an update.
     */
    public function test_update_reports_write_failed_for_a_partial_write(): void
    {
        $path = $this->createConfigFile(self::CONFIG);
        vfsStream::setQuota(strlen(self::CONFIG) - 20);

        $update = $this->updater->update($path, self::BRANCH_KEY, '1.10.x-dev');

        $this->assertSame(BranchAliasStatus::WriteFailed, $update->status);
        $this->assertNotSame(self::CONFIG, file_get_contents($path));
    }

    #[\Override]
    public function tearDown(): void
    {
        vfsStream::setQuota(Quota::UNLIMITED);
    }

    private function createConfigFile(string $content): string
    {
        return vfsStream::newFile(self::COMPOSER_FILE_NAME)
            ->withContent($content)
            ->at($this->root)
            ->url();
    }
}
