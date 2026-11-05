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
