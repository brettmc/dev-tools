<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Git;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Reads the state of a local git checkout.
 */
class GitRunner
{
    public function __construct(private readonly string $git = 'git')
    {
    }

    /**
     * @throws RuntimeException with git's own error, when $path is not a readable checkout
     */
    public function head(string $path): string
    {
        return trim($this->run($path, ['rev-parse', '--verify', 'HEAD']));
    }

    /**
     * Tracked files with uncommitted changes, as `git status --porcelain` lines. Untracked files,
     * such as vendor/, are not changes to what is tagged.
     *
     * @throws RuntimeException with git's own error, when $path is not a readable checkout
     * @return list<string>
     */
    public function changes(string $path): array
    {
        $status = $this->run($path, ['status', '--porcelain', '--untracked-files=no']);

        return array_values(array_filter(explode("\n", $status), static fn (string $line): bool => $line !== ''));
    }

    /**
     * Untracked files under $prefixes, as `git status --porcelain` lines. Ignored files are not
     * listed. Files outside every prefix are not either: they are not in any package.
     *
     * @param list<string> $prefixes
     * @throws RuntimeException with git's own error, when $path is not a readable checkout
     * @return list<string>
     */
    public function untracked(string $path, array $prefixes): array
    {
        if ($prefixes === []) {
            return [];
        }
        $pathspecs = array_map(static fn (string $prefix): string => trim($prefix, '/'), $prefixes);
        $status = $this->run($path, ['status', '--porcelain', '--untracked-files=all', '--', ...$pathspecs]);

        return array_values(array_filter(
            explode("\n", $status),
            static fn (string $line): bool => str_starts_with($line, '?? '),
        ));
    }

    /**
     * Whether $path is a shallow clone, whose history stops short of the first commit.
     *
     * @throws RuntimeException with git's own error, when $path is not a readable checkout
     */
    public function isShallow(string $path): bool
    {
        return trim($this->run($path, ['rev-parse', '--is-shallow-repository'])) === 'true';
    }

    /**
     * @throws RuntimeException with git's own error, when $path is not a readable checkout
     */
    public function hasCommit(string $path, string $sha): bool
    {
        return $this->test($path, ['rev-parse', '--verify', '--quiet', "{$sha}^{commit}"]);
    }

    /**
     * Whether $ancestor is $descendant or one of its ancestors.
     *
     * @throws RuntimeException with git's own error, when $path is not a readable checkout or either
     *         commit is not in it
     */
    public function isAncestor(string $path, string $ancestor, string $descendant): bool
    {
        return $this->test($path, ['merge-base', '--is-ancestor', $ancestor, $descendant]);
    }

    /**
     * The hash of the tree at $prefix in $sha, or null when $sha has nothing there. Two commits
     * with the same tree have the same content, which is how a split commit is matched to the
     * monorepo commit it was split from.
     *
     * @throws RuntimeException with git's own error, when $path is not a readable checkout or $sha
     *         is not in it
     */
    public function tree(string $path, string $sha, string $prefix): ?string
    {
        //a trailing slash would list the directory's entries, not the directory
        $entry = trim($this->run($path, ['ls-tree', $sha, '--', trim($prefix, '/')]));
        if (preg_match('/^\d+ tree ([0-9a-f]+)\t/', $entry, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * The commits of $sha and its history that touch $prefix, newest first. $since is a committer
     * date, as GitHub's commits API `since` is.
     *
     * The message has the trailing newline git adds stripped, so it reads the same as GitHub's.
     *
     * @throws RuntimeException with git's own error, when $path is not a readable checkout or $sha
     *         is not in it
     * @return list<array{sha: string, author: string, message: string}>
     */
    public function log(string $path, string $sha, string $prefix, ?string $since): array
    {
        $args = ['log', '--format=%H%x00%an%x00%B%x00%x1e'];
        if ($since !== null) {
            $args[] = "--since={$since}";
        }
        array_push($args, $sha, '--', trim($prefix, '/'));

        $commits = [];
        foreach (explode("\x1e", $this->run($path, $args)) as $record) {
            $record = ltrim($record, "\n");
            if ($record === '') {
                continue;
            }
            [$commitSha, $author, $message] = explode("\0", $record, 4);
            $commits[] = ['sha' => $commitSha, 'author' => $author, 'message' => rtrim($message, "\n")];
        }

        return $commits;
    }

    /**
     * The files that differ between $revisions, as `git diff --name-only` lists them. One revision
     * compares it with the working tree, so uncommitted changes to tracked files are included.
     *
     * @param list<string> $revisions
     * @throws RuntimeException with git's own error, when $path is not a readable checkout or a
     *         revision is not in it
     * @return list<string>
     */
    public function changedFiles(string $path, array $revisions): array
    {
        //revisions come from the command line, so --end-of-options stops one being read as an option
        $diff = $this->run($path, ['diff', '--name-only', '-z', '--no-renames', '--end-of-options', ...$revisions, '--']);

        return array_values(array_filter(explode("\0", $diff), static fn (string $file): bool => $file !== ''));
    }

    /**
     * Every untracked file that is not ignored.
     *
     * @throws RuntimeException with git's own error, when $path is not a readable checkout
     * @return list<string>
     */
    public function untrackedFiles(string $path): array
    {
        $files = $this->run($path, ['ls-files', '-z', '--others', '--exclude-standard']);

        return array_values(array_filter(explode("\0", $files), static fn (string $file): bool => $file !== ''));
    }

    /**
     * @throws RuntimeException with git's own error, when $path is not a readable checkout or either
     *         ref is not in it
     */
    public function mergeBase(string $path, string $a, string $b): string
    {
        return trim($this->run($path, ['merge-base', '--end-of-options', $a, $b]));
    }

    /**
     * Run a git command that answers yes or no by exiting 0 or 1. Any other exit is an error.
     *
     * @param list<string> $args
     */
    private function test(string $path, array $args): bool
    {
        $process = new Process([$this->git, ...$args], $path);
        $process->run();
        $code = $process->getExitCode();
        if ($code !== 0 && $code !== 1) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: "git {$args[0]} failed in {$path}");
        }

        return $code === 0;
    }

    /**
     * @param list<string> $args
     */
    private function run(string $path, array $args): string
    {
        $process = new Process([$this->git, ...$args], $path);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: "git {$args[0]} failed in {$path}");
        }

        return $process->getOutput();
    }
}
