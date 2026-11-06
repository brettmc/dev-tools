<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Integration\Package\Git;

use OpenTelemetry\DevTools\Package\Git\GitRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * @covers \OpenTelemetry\DevTools\Package\Git\GitRunner
 */
class GitRunnerTest extends TestCase
{
    private string $dir;

    #[\Override]
    public function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/git-runner-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    #[\Override]
    public function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->mustRun();
    }

    public function test_head_is_the_checked_out_commit(): void
    {
        $sha = $this->commit();

        $this->assertSame($sha, (new GitRunner())->head($this->dir));
    }

    public function test_head_fails_outside_a_git_checkout(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a git repository');

        (new GitRunner())->head($this->dir);
    }

    public function test_a_clean_checkout_has_no_changes(): void
    {
        $this->commit();

        $this->assertSame([], (new GitRunner())->changes($this->dir));
    }

    public function test_changes_to_tracked_files_are_listed(): void
    {
        $this->commit();
        file_put_contents($this->dir . '/composer.json', '{"name":"changed"}');

        $this->assertSame([' M composer.json'], (new GitRunner())->changes($this->dir));
    }

    public function test_untracked_files_are_not_changes(): void
    {
        $this->commit();
        mkdir($this->dir . '/vendor');
        file_put_contents($this->dir . '/vendor/autoload.php', '<?php');

        $this->assertSame([], (new GitRunner())->changes($this->dir));
    }

    public function test_a_commit_in_the_repository_exists(): void
    {
        $sha = $this->commit();

        $this->assertTrue((new GitRunner())->hasCommit($this->dir, $sha));
    }

    public function test_a_commit_not_in_the_repository_does_not_exist(): void
    {
        $this->commit();

        $this->assertFalse((new GitRunner())->hasCommit($this->dir, str_repeat('a', 40)));
    }

    public function test_has_commit_fails_outside_a_git_checkout(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a git repository');

        (new GitRunner())->hasCommit($this->dir, str_repeat('a', 40));
    }

    public function test_an_earlier_commit_is_an_ancestor_of_head(): void
    {
        $first = $this->commit();
        $this->commit('Second');

        $this->assertTrue((new GitRunner())->isAncestor($this->dir, $first, 'HEAD'));
    }

    public function test_head_is_an_ancestor_of_itself(): void
    {
        $sha = $this->commit();

        $this->assertTrue((new GitRunner())->isAncestor($this->dir, $sha, 'HEAD'));
    }

    public function test_a_commit_on_another_branch_is_not_an_ancestor_of_head(): void
    {
        $this->commit();
        $this->git('checkout', '--quiet', '-b', 'other');
        $other = $this->commit('Other');
        $this->git('checkout', '--quiet', '-');

        $this->assertFalse((new GitRunner())->isAncestor($this->dir, $other, 'HEAD'));
    }

    public function test_is_ancestor_fails_for_a_commit_not_in_the_repository(): void
    {
        $this->commit();

        $this->expectException(RuntimeException::class);

        (new GitRunner())->isAncestor($this->dir, str_repeat('a', 40), 'HEAD');
    }

    public function test_tree_is_the_hash_of_a_directory_at_a_commit(): void
    {
        $sha = $this->commit();
        mkdir($this->dir . '/src/API', 0o777, true);
        file_put_contents($this->dir . '/src/API/composer.json', '{"name":"open-telemetry/api"}');
        $this->git('add', 'src');
        $this->git('-c', 'user.name=test', '-c', 'user.email=test@example.com', 'commit', '--quiet', '-m', 'API');
        $expected = trim($this->git('rev-parse', 'HEAD:src/API'));

        $this->assertSame($expected, (new GitRunner())->tree($this->dir, 'HEAD', 'src/API'));
        $this->assertNull((new GitRunner())->tree($this->dir, $sha, 'src/API'));
    }

    public function test_tree_fails_for_a_commit_not_in_the_repository(): void
    {
        $this->commit();

        $this->expectException(RuntimeException::class);

        (new GitRunner())->tree($this->dir, str_repeat('a', 40), 'src/API');
    }

    private function commit(string $message = 'Initial'): string
    {
        is_dir($this->dir . '/.git') || $this->git('init', '--quiet');
        file_put_contents($this->dir . '/composer.json', json_encode(['message' => $message]));
        $this->git('add', 'composer.json');
        $this->git('-c', 'user.name=test', '-c', 'user.email=test@example.com', 'commit', '--quiet', '-m', $message);

        return trim($this->git('rev-parse', 'HEAD'));
    }

    private function git(string ...$args): string
    {
        return (new Process(['git', ...$args], $this->dir))->mustRun()->getOutput();
    }
}
