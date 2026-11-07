<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Changelog;

use OpenTelemetry\DevTools\Package\Changelog\ChangelogException;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogParser;
use OpenTelemetry\DevTools\Package\Git\GitRunner;
use OpenTelemetry\DevTools\Package\GitSplit\MonorepoCheckout;
use OpenTelemetry\DevTools\Util\Path;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Checks that each split package changed in a pull request has an entry in its CHANGELOG.md, and
 * that each changed CHANGELOG.md is one release:prep can read.
 */
class ChangelogCheckCommand extends Command
{
    public const NAME = 'changelog:check';
    private const CHANGELOG_FILE = 'CHANGELOG.md';
    private const BASE = 'origin/main';

    private GitRunner $git;
    private ChangelogParser $parser;

    public function __construct(?GitRunner $git = null)
    {
        parent::__construct(self::NAME);
        $this->git = $git ?? new GitRunner();
        $this->parser = new ChangelogParser();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setDescription('Check that changed packages have a changelog entry, and that changed changelogs parse')
            ->addArgument('revisions', InputArgument::IS_ARRAY, 'revisions to pass to git diff (default: everything since the merge base with ' . self::BASE . ', including uncommitted and untracked files)')
            ->addOption('path', null, InputOption::VALUE_OPTIONAL, 'path to a monorepo checkout (default: working directory)')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = Path::directory($input->getOption('path'));
        if ($path === null) {
            $output->writeln("<error>--path {$input->getOption('path')} is not a directory</error>");

            return Command::FAILURE;
        }
        /** @var list<string> $revisions */
        $revisions = $input->getArgument('revisions');
        if (count($revisions) > 2) {
            $output->writeln('<error>' . self::NAME . ' takes at most two revisions, as git diff does</error>');

            return Command::FAILURE;
        }

        try {
            $prefixes = MonorepoCheckout::read($path)->prefixes;
            if ($revisions === []) {
                $base = $this->git->mergeBase($path, self::BASE, 'HEAD');
                $output->writeln(sprintf('Changes since %s (merge base with %s), including uncommitted files:', substr($base, 0, 8), self::BASE));
                $changed = [...$this->git->changedFiles($path, [$base]), ...$this->git->untrackedFiles($path)];
            } else {
                $output->writeln(OutputFormatter::escape('Changes in git diff ' . implode(' ', $revisions) . ':'));
                $changed = $this->git->changedFiles($path, $revisions);
            }
            $changelogs = $this->changelogs($path, $prefixes, self::target($revisions));
        } catch (RuntimeException $e) {
            $output->writeln('<error>' . OutputFormatter::escape($e->getMessage()) . '</error>');

            return Command::FAILURE;
        }

        $width = max(array_map('strlen', $prefixes ?: ['']));
        $found = false;
        $missing = $deleted = false;
        foreach ($prefixes as $prefix) {
            $status = $this->status($prefix, $changed, $changelogs[$prefix] !== null);
            if ($status === null) {
                continue;
            }
            $found = true;
            $output->writeln(OutputFormatter::escape(sprintf("  %-{$width}s %s", $prefix, $status)));
            if ($status === 'changed, CHANGELOG.md not updated') {
                $missing = true;
                $this->annotate($output, "{$prefix}/" . self::CHANGELOG_FILE, "{$prefix} changed without an entry in its CHANGELOG.md");
            } elseif ($status === 'CHANGELOG.md deleted') {
                $deleted = true;
                $this->annotate($output, "{$prefix}/" . self::CHANGELOG_FILE, "{$prefix}/" . self::CHANGELOG_FILE . ' was deleted');
            }
        }
        if (!$found) {
            $output->writeln('  No package changed.');
        }
        $errors = 0;
        foreach ($prefixes as $prefix) {
            $file = "{$prefix}/" . self::CHANGELOG_FILE;
            $changelog = $changelogs[$prefix];
            if (($revisions !== [] && !in_array($file, $changed, true)) || $changelog === null) {
                continue;
            }

            try {
                $this->parser->parseUnreleased($changelog);
            } catch (ChangelogException $e) {
                $errors++;
                $output->writeln('<error>' . OutputFormatter::escape("{$file}: {$e->getMessage()}") . '</error>');
                $this->annotate($output, $file, $e->getMessage(), $e->lineNumber);
            }
        }
        if ($missing) {
            $output->writeln('');
            $output->writeln('Add an entry under Unreleased in each changelog not updated, if the change affects users.');
            $output->writeln("If it doesn't, a maintainer can add the skip-changelog label.");
        }

        return !$missing && !$deleted && $errors === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * The revision whose files git diff compares against, or null for the working tree. One
     * revision, or none, compares with the working tree. An open-ended range ends at HEAD.
     *
     * @param list<string> $revisions
     */
    private static function target(array $revisions): ?string
    {
        if (count($revisions) === 2) {
            return $revisions[1];
        }
        if (count($revisions) === 1 && preg_match('/\.\.\.?(.*)$/', $revisions[0], $matches) === 1) {
            return $matches[1] === '' ? 'HEAD' : $matches[1];
        }

        return null;
    }

    /**
     * Each prefix's CHANGELOG.md as it is in $revision, or in the working tree when $revision is
     * null. A prefix with no CHANGELOG.md there maps to null.
     *
     * @param list<string> $prefixes
     * @throws RuntimeException with git's own error, when $revision is not in the checkout
     * @return array<string, ?string>
     */
    private function changelogs(string $path, array $prefixes, ?string $revision): array
    {
        $changelogs = [];
        foreach ($prefixes as $prefix) {
            $file = "{$prefix}/" . self::CHANGELOG_FILE;
            $changelogs[$prefix] = match (true) {
                $revision !== null => $this->git->fileAt($path, $revision, $file),
                is_file("{$path}/{$file}") => (string) file_get_contents("{$path}/{$file}"),
                default => null,
            };
        }

        return $changelogs;
    }

    /**
     * @param list<string> $changed
     */
    private function status(string $prefix, array $changed, bool $hasChangelog): ?string
    {
        $source = $changelog = $docs = false;
        foreach ($changed as $file) {
            if (!str_starts_with($file, "{$prefix}/")) {
                continue;
            }
            if ($file === "{$prefix}/" . self::CHANGELOG_FILE) {
                $changelog = true;
            } elseif (str_ends_with($file, '.md')) {
                $docs = true;
            } else {
                $source = true;
            }
        }

        return match (true) {
            !$source && !$changelog && !$docs => null,
            !$hasChangelog && $changelog => 'CHANGELOG.md deleted',
            !$hasChangelog => 'changed, has no CHANGELOG.md',
            $source && $changelog => 'changed, CHANGELOG.md updated',
            $source => 'changed, CHANGELOG.md not updated',
            $changelog => 'CHANGELOG.md updated',
            default => 'Markdown only, no entry needed',
        };
    }

    /**
     * A GitHub Actions workflow command, which shows $message against $file in the pull request.
     */
    private function annotate(OutputInterface $output, string $file, string $message, ?int $line = null): void
    {
        if (getenv('GITHUB_ACTIONS') === false) {
            return;
        }
        $location = $line === null ? "file={$file}" : "file={$file},line={$line}";
        $data = strtr($message, ['%' => '%25', "\r" => '%0D', "\n" => '%0A']);

        $output->writeln("::error {$location}::{$data}", OutputInterface::OUTPUT_RAW);
    }
}
