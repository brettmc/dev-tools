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

    public function test_log_lists_the_commits_under_a_prefix_newest_first(): void
    {
        $this->commit();
        $first = $this->commitFile('src/API/a.php', "Add a\n\nWith a body.", '2026-01-01T00:00:00Z', 'Ann');
        $this->commitFile('src/SDK/b.php', 'Add b', '2026-01-02T00:00:00Z');
        $third = $this->commitFile('src/API/c.php', 'Add c (#12)', '2026-01-03T00:00:00Z', 'Bob');

        $commits = (new GitRunner())->log($this->dir, 'HEAD', 'src/API', null);

        $this->assertSame([$third, $first], array_column($commits, 'sha'));
        $this->assertSame(['Add c (#12)', "Add a\n\nWith a body."], array_column($commits, 'message'));
        $this->assertSame(['Bob', 'Ann'], array_column($commits, 'author'));
    }

    public function test_log_since_excludes_older_commits(): void
    {
        $this->commit();
        $this->commitFile('src/API/a.php', 'Add a', '2026-01-01T00:00:00Z');
        $second = $this->commitFile('src/API/b.php', 'Add b', '2026-01-02T00:00:00Z');

        $commits = (new GitRunner())->log($this->dir, 'HEAD', 'src/API', '2026-01-01T00:00:01Z');

        $this->assertSame([$second], array_column($commits, 'sha'));
    }

    public function test_log_reads_back_from_the_given_commit(): void
    {
        $this->commit();
        $first = $this->commitFile('src/API/a.php', 'Add a', '2026-01-01T00:00:00Z');
        $this->commitFile('src/API/b.php', 'Add b', '2026-01-02T00:00:00Z');

        $commits = (new GitRunner())->log($this->dir, $first, 'src/API', null);

        $this->assertSame([$first], array_column($commits, 'sha'));
    }

    public function test_log_accepts_a_prefix_with_a_trailing_slash(): void
    {
        $this->commit();
        $sha = $this->commitFile('src/API/a.php', 'Add a', '2026-01-01T00:00:00Z');

        $this->assertSame([$sha], array_column((new GitRunner())->log($this->dir, 'HEAD', 'src/API/', null), 'sha'));
    }

    public function test_changed_files_are_listed_for_a_range(): void
    {
        $first = $this->commit();
        $this->commitFile('src/API/a b.php', 'Add a', '2026-01-01T00:00:00Z');
        $this->commitFile('src/SDK/b.php', 'Add b', '2026-01-02T00:00:00Z');

        $this->assertSame(['src/API/a b.php', 'src/SDK/b.php'], (new GitRunner())->changedFiles($this->dir, [$first, 'HEAD']));
        $this->assertSame(['src/SDK/b.php'], (new GitRunner())->changedFiles($this->dir, ['HEAD^1', 'HEAD']));
    }

    public function test_changed_files_since_one_commit_include_uncommitted_changes(): void
    {
        $this->commit();
        file_put_contents($this->dir . '/composer.json', '{"name":"changed"}');

        $this->assertSame(['composer.json'], (new GitRunner())->changedFiles($this->dir, ['HEAD']));
    }

    public function test_changed_files_take_revisions_not_options_or_paths(): void
    {
        $this->commit();
        $this->commit('Second');

        $this->expectException(RuntimeException::class);

        (new GitRunner())->changedFiles($this->dir, ['--output=' . $this->dir . '/written']);
    }

    public function test_untracked_files_are_listed_unquoted_without_ignored_ones(): void
    {
        $this->commit();
        file_put_contents($this->dir . '/.gitignore', "vendor/\n");
        mkdir($this->dir . '/vendor');
        file_put_contents($this->dir . '/vendor/autoload.php', '<?php');
        mkdir($this->dir . '/src/API', 0o777, true);
        file_put_contents($this->dir . '/src/API/a "b".php', '<?php');

        $this->assertSame(['.gitignore', 'src/API/a "b".php'], (new GitRunner())->untrackedFiles($this->dir));
    }

    public function test_merge_base_is_the_common_ancestor(): void
    {
        $base = $this->commit();
        $this->git('checkout', '--quiet', '-b', 'other');
        $this->commit('Other');
        $this->git('checkout', '--quiet', '-');
        $this->commit('Second');

        $this->assertSame($base, (new GitRunner())->mergeBase($this->dir, 'other', 'HEAD'));
    }

    public function test_merge_base_fails_for_an_unknown_ref(): void
    {
        $this->commit();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('origin/main');

        (new GitRunner())->mergeBase($this->dir, 'origin/main', 'HEAD');
    }

    public function test_log_fails_for_a_commit_not_in_the_repository(): void
    {
        $this->commit();

        $this->expectException(RuntimeException::class);

        (new GitRunner())->log($this->dir, str_repeat('a', 40), 'src/API', null);
    }

    /**
     * @dataProvider shaTakers
     */
    public function test_sha_is_a_revision_not_an_option(callable $call): void
    {
        $this->commit();
        $this->commitFile('src/API/a.php', 'Add a', '2026-01-01T00:00:00Z');
        $written = $this->dir . '/written';

        try {
            $call(new GitRunner(), $this->dir, "--output={$written}");
            $this->fail('No RuntimeException');
        } catch (RuntimeException) {
        }
        $this->assertFileDoesNotExist($written);
    }

    public static function shaTakers(): iterable
    {
        yield 'log' => [static fn (GitRunner $git, string $dir, string $sha) => $git->log($dir, $sha, 'src/API', null)];
        yield 'tree' => [static fn (GitRunner $git, string $dir, string $sha) => $git->tree($dir, $sha, 'src/API')];
        yield 'isAncestor' => [static fn (GitRunner $git, string $dir, string $sha) => $git->isAncestor($dir, $sha, 'HEAD')];
        yield 'fileAt' => [static fn (GitRunner $git, string $dir, string $sha) => $git->fileAt($dir, $sha, 'src/API/a.php')];
    }

    public function test_has_commit_takes_a_revision_not_an_option(): void
    {
        $this->commit();

        $this->assertFalse((new GitRunner())->hasCommit($this->dir, '--all'));
    }

    public function test_file_at_a_revision_is_its_content_then(): void
    {
        $this->commit();
        $this->commitFile('src/API/a b.php', 'Add a', '2026-01-01T00:00:00Z');
        file_put_contents($this->dir . '/src/API/a b.php', '<?php // uncommitted');

        $this->assertSame('<?php', (new GitRunner())->fileAt($this->dir, 'HEAD', 'src/API/a b.php'));
    }

    public function test_file_not_at_a_revision_is_null(): void
    {
        $this->commit();
        $this->commitFile('src/API/a.php', 'Add a', '2026-01-01T00:00:00Z');

        $this->assertNull((new GitRunner())->fileAt($this->dir, 'HEAD^1', 'src/API/a.php'));
        $this->assertNull((new GitRunner())->fileAt($this->dir, 'HEAD', 'src/API/b.php'));
        $this->assertNull((new GitRunner())->fileAt($this->dir, 'HEAD', 'src/API'));
    }

    public function test_file_at_fails_for_a_commit_not_in_the_repository(): void
    {
        $this->commit();

        $this->expectException(RuntimeException::class);

        (new GitRunner())->fileAt($this->dir, str_repeat('a', 40), 'composer.json');
    }

    public function test_untracked_files_under_a_prefix_are_listed(): void
    {
        $this->commit();
        mkdir($this->dir . '/src/API', 0o777, true);
        file_put_contents($this->dir . '/src/API/CHANGELOG.md', '# Changelog');
        file_put_contents($this->dir . '/notes.txt', 'outside every prefix');

        $this->assertSame(['?? src/API/CHANGELOG.md'], (new GitRunner())->untracked($this->dir, ['src/API/', 'src/SDK']));
    }

    public function test_ignored_files_under_a_prefix_are_not_untracked(): void
    {
        $this->commit();
        mkdir($this->dir . '/src/API/vendor', 0o777, true);
        file_put_contents($this->dir . '/src/API/vendor/autoload.php', '<?php');
        file_put_contents($this->dir . '/.git/info/exclude', "vendor/\n");

        $this->assertSame([], (new GitRunner())->untracked($this->dir, ['src/API']));
    }

    public function test_no_prefixes_have_no_untracked_files(): void
    {
        $this->commit();
        file_put_contents($this->dir . '/notes.txt', 'anything');

        $this->assertSame([], (new GitRunner())->untracked($this->dir, []));
    }

    public function test_a_full_clone_is_not_shallow(): void
    {
        $this->commit();

        $this->assertFalse((new GitRunner())->isShallow($this->dir));
    }

    public function test_a_depth_one_clone_is_shallow(): void
    {
        $this->commit();
        $this->commit('Second');
        $clone = $this->dir . '/clone';
        $this->git('clone', '--quiet', '--depth=1', 'file://' . $this->dir, $clone);

        $this->assertTrue((new GitRunner())->isShallow($clone));
    }

    public function test_commit_all_commits_tracked_changes_as_the_given_author(): void
    {
        $parent = $this->commit();
        file_put_contents($this->dir . '/composer.json', '{"name":"changed"}');
        file_put_contents($this->dir . '/untracked.txt', 'not committed');

        $sha = (new GitRunner())->commitAll($this->dir, "Release 2026-11-14\n\nopen-telemetry/api 1.2.0\n", 'bot[bot]', 'bot@example.com');

        $this->assertSame($sha, trim($this->git('rev-parse', 'HEAD')));
        $this->assertSame($parent, trim($this->git('rev-parse', 'HEAD^')));
        $this->assertSame("bot[bot] <bot@example.com>\nRelease 2026-11-14\n\nopen-telemetry/api 1.2.0", rtrim($this->git('log', '-1', '--format=%an <%ae>%n%B'), "\n"));
        $this->assertSame("?? untracked.txt\n", $this->git('status', '--porcelain'));
    }

    public function test_commit_all_fails_with_nothing_to_commit(): void
    {
        $this->commit();

        $this->expectException(RuntimeException::class);

        (new GitRunner())->commitAll($this->dir, 'Empty', 'bot', 'bot@example.com');
    }

    public function test_fetch_answers_the_sha_of_the_branch(): void
    {
        [$remote, $work] = $this->remote();
        $sha = $this->pushBranch($remote, 'otelbot/release');

        $this->assertSame($sha, (new GitRunner())->fetch($work, 'file://' . $remote, 'otelbot/release'));
        $this->assertSame($sha, trim($this->git('-C', $work, 'rev-parse', 'FETCH_HEAD')));
    }

    public function test_fetch_of_a_branch_that_does_not_exist_answers_null(): void
    {
        [$remote, $work] = $this->remote();

        $this->assertNull((new GitRunner())->fetch($work, 'file://' . $remote, 'otelbot/release'));
    }

    public function test_fetch_from_a_remote_that_cannot_be_read_fails(): void
    {
        [, $work] = $this->remote();

        $this->expectException(RuntimeException::class);

        (new GitRunner())->fetch($work, 'file://' . $this->dir . '/nowhere.git', 'otelbot/release');
    }

    public function test_fetch_into_a_shallow_clone_brings_the_parent(): void
    {
        [$remote] = $this->remote();
        $sha = $this->pushBranch($remote, 'otelbot/release');
        $shallow = $this->dir . '/shallow';
        $this->git('clone', '--quiet', '--depth=1', 'file://' . $remote, $shallow);

        $git = new GitRunner();
        $this->assertSame($sha, $git->fetch($shallow, 'file://' . $remote, 'otelbot/release'));
        $this->assertSame(trim($this->git('-C', $remote, 'rev-parse', 'main')), $git->parent($shallow, $sha));
    }

    public function test_parent_of_a_root_commit_is_null(): void
    {
        $sha = $this->commit();

        $this->assertNull((new GitRunner())->parent($this->dir, $sha));
    }

    public function test_same_tree_compares_content_not_commits(): void
    {
        $first = $this->commit('Same');
        $this->git('-c', 'user.name=test', '-c', 'user.email=test@example.com', 'commit', '--quiet', '--allow-empty', '-m', 'Empty');
        $empty = trim($this->git('rev-parse', 'HEAD'));
        $changed = $this->commit('Changed');

        $git = new GitRunner();
        $this->assertTrue($git->sameTree($this->dir, $first, $empty));
        $this->assertFalse($git->sameTree($this->dir, $first, $changed));
    }

    public function test_push_creates_a_branch_that_must_not_exist(): void
    {
        [$remote, $work] = $this->remote();
        $head = trim($this->git('-C', $work, 'rev-parse', 'HEAD'));

        (new GitRunner())->push($work, 'file://' . $remote, 'otelbot/release', '', 'secret');

        $this->assertSame($head, trim($this->git('-C', $remote, 'rev-parse', 'otelbot/release')));
    }

    public function test_push_with_an_empty_lease_fails_when_the_branch_exists(): void
    {
        [$remote, $work] = $this->remote();
        $this->pushBranch($remote, 'otelbot/release');

        $this->expectException(RuntimeException::class);

        (new GitRunner())->push($work, 'file://' . $remote, 'otelbot/release', '', null);
    }

    public function test_push_replaces_the_branch_at_the_lease(): void
    {
        [$remote, $work] = $this->remote();
        $lease = $this->pushBranch($remote, 'otelbot/release');
        $head = trim($this->git('-C', $work, 'rev-parse', 'HEAD'));

        (new GitRunner())->push($work, 'file://' . $remote, 'otelbot/release', $lease, null);

        $this->assertSame($head, trim($this->git('-C', $remote, 'rev-parse', 'otelbot/release')));
    }

    public function test_push_fails_when_the_branch_moved_from_the_lease(): void
    {
        [$remote, $work] = $this->remote();
        $lease = $this->pushBranch($remote, 'otelbot/release');
        $moved = $this->pushBranch($remote, 'otelbot/release', 'Moved');

        try {
            (new GitRunner())->push($work, 'file://' . $remote, 'otelbot/release', $lease, 'secret');
            $this->fail('no exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('stale info', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
        $this->assertSame($moved, trim($this->git('-C', $remote, 'rev-parse', 'otelbot/release')));
    }

    public function test_a_failed_push_does_not_show_the_token(): void
    {
        [, $work] = $this->remote();

        try {
            //nothing listens there, so git fails before sending anything
            (new GitRunner())->push($work, 'https://127.0.0.1:1/o/r.git', 'otelbot/release', '', 'secret');
            $this->fail('no exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('127.0.0.1', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    /**
     * A bare repository with one commit on main, and a clone of it with a second commit on top.
     *
     * @return array{0: string, 1: string} the bare repository and the clone
     */
    private function remote(): array
    {
        $this->commit();
        $remote = $this->dir . '/remote.git';
        $work = $this->dir . '/work';
        $this->git('branch', '--quiet', '-M', 'main');
        $this->git('clone', '--quiet', '--bare', $this->dir, $remote);
        $this->git('clone', '--quiet', 'file://' . $remote, $work);
        file_put_contents($work . '/composer.json', '{"name":"work"}');
        $this->git('-C', $work, '-c', 'user.name=test', '-c', 'user.email=test@example.com', 'commit', '--quiet', '--all', '-m', 'Work');

        return [$remote, $work];
    }

    /**
     * Push a new commit on top of main to $branch of $remote, as another run would.
     */
    private function pushBranch(string $remote, string $branch, string $message = 'Other run'): string
    {
        $other = $this->dir . '/other-' . bin2hex(random_bytes(2));
        $this->git('clone', '--quiet', 'file://' . $remote, $other);
        file_put_contents($other . '/composer.json', json_encode(['message' => $message]));
        $this->git('-C', $other, '-c', 'user.name=test', '-c', 'user.email=test@example.com', 'commit', '--quiet', '--all', '-m', $message);
        $this->git('-C', $other, 'push', '--quiet', '--force', 'origin', "HEAD:refs/heads/{$branch}");

        return trim($this->git('-C', $other, 'rev-parse', 'HEAD'));
    }

    private function commit(string $message = 'Initial'): string
    {
        is_dir($this->dir . '/.git') || $this->git('init', '--quiet');
        file_put_contents($this->dir . '/composer.json', json_encode(['message' => $message]));
        $this->git('add', 'composer.json');
        $this->git('-c', 'user.name=test', '-c', 'user.email=test@example.com', 'commit', '--quiet', '-m', $message);

        return trim($this->git('rev-parse', 'HEAD'));
    }

    /**
     * Commit one new file, with both the author and committer dates set to $date, as `--since`
     * filters on the committer date.
     */
    private function commitFile(string $path, string $message, string $date, string $author = 'test'): string
    {
        is_dir(dirname($this->dir . '/' . $path)) || mkdir(dirname($this->dir . '/' . $path), 0o777, true);
        file_put_contents($this->dir . '/' . $path, '<?php');
        $this->git('add', $path);
        (new Process(
            ['git', '-c', "user.name={$author}", '-c', 'user.email=test@example.com', 'commit', '--quiet', '-m', $message],
            $this->dir,
            ['GIT_AUTHOR_DATE' => $date, 'GIT_COMMITTER_DATE' => $date],
        ))->mustRun();

        return trim($this->git('rev-parse', 'HEAD'));
    }

    private function git(string ...$args): string
    {
        return (new Process(['git', ...$args], $this->dir))->mustRun()->getOutput();
    }
}
