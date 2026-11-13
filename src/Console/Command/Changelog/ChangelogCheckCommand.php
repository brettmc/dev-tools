<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Changelog;

use OpenTelemetry\DevTools\Console\Command\BaseCommand;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogException;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogLinks;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogParser;
use OpenTelemetry\DevTools\Package\Changelog\ChangelogReleases;
use OpenTelemetry\DevTools\Package\Git\GitRunner;
use OpenTelemetry\DevTools\Package\GitSplit\MonorepoCheckout;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Checks that each split package changed in a pull request has a new entry under Unreleased in its
 * CHANGELOG.md, that no released section changed, and that each changed CHANGELOG.md is one
 * release:prep can read. Given the pull request's number, it also checks each new entry links to it.
 */
class ChangelogCheckCommand extends BaseCommand
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
            ->setDescription('Check that changed packages have an Unreleased entry, released sections are unchanged, and changed changelogs parse')
            ->addArgument('revisions', InputArgument::IS_ARRAY, 'revisions to pass to git diff (default: everything since the merge base with ' . self::BASE . ', including uncommitted and untracked files)')
            ->addOption('path', null, InputOption::VALUE_OPTIONAL, 'path to a monorepo checkout (default: working directory)')
            ->addOption('pr', null, InputOption::VALUE_REQUIRED, 'number of the pull request being checked, which each new Unreleased entry must link to')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->registerInputAndOutput($input, $output);
        $path = $this->checkoutPath($input->getOption('path'));
        if ($path === null) {
            return Command::FAILURE;
        }
        /** @var list<string> $revisions */
        $revisions = $input->getArgument('revisions');
        if (count($revisions) > 2) {
            $output->writeln('<error>' . self::NAME . ' takes at most two revisions, as git diff does</error>');

            return Command::FAILURE;
        }
        /** @var ?string $pr */
        $pr = $input->getOption('pr');
        if ($pr !== null && preg_match('/^[1-9]\d*$/', $pr) !== 1) {
            $output->writeln('<error>' . OutputFormatter::escape(sprintf('--pr must be a pull request number, not "%s"', $pr)) . '</error>');

            return Command::FAILURE;
        }

        try {
            $checkout = MonorepoCheckout::read($path);
            $prefixes = $checkout->prefixes;
            if ($pr !== null && $checkout->repository === null) {
                throw new RuntimeException('--pr needs project_url in .gitsplit.yml, to know the repository');
            }
            $url = $pr === null ? null : "https://github.com/{$checkout->repository}/pull/{$pr}";
            if ($revisions === []) {
                $base = $this->git->mergeBase($path, self::BASE, 'HEAD');
                $output->writeln(sprintf('Changes since %s (merge base with %s), including uncommitted files:', substr($base, 0, 8), self::BASE));
                $changed = [...$this->git->changedFiles($path, [$base]), ...$this->git->untrackedFiles($path)];
            } else {
                $base = $this->base($path, $revisions);
                $output->writeln(OutputFormatter::escape('Changes in git diff ' . implode(' ', $revisions) . ':'));
                $changed = $this->git->changedFiles($path, $revisions);
            }
            $changelogs = $this->changelogs($path, $prefixes, self::target($revisions));
            $changedChangelogs = array_values(array_filter(
                $prefixes,
                static fn (string $prefix): bool => in_array("{$prefix}/" . self::CHANGELOG_FILE, $changed, true)
            ));
            $baseChangelogs = $this->changelogs($path, $changedChangelogs, $base);
        } catch (RuntimeException $e) {
            $output->writeln('<error>' . OutputFormatter::escape($e->getMessage()) . '</error>');

            return Command::FAILURE;
        }

        $width = max(array_map('strlen', $prefixes ?: ['']));
        $found = false;
        $missing = $deleted = $noChangelog = false;
        foreach ($prefixes as $prefix) {
            $status = $this->status($prefix, $changed, $changelogs[$prefix] !== null);
            if ($status === null) {
                continue;
            }
            if ($status === 'changed, CHANGELOG.md updated'
                && !$this->unreleasedChanged($baseChangelogs[$prefix] ?? null, $changelogs[$prefix])) {
                $status = 'changed, Unreleased not updated';
            }
            $found = true;
            $output->writeln(OutputFormatter::escape(sprintf("  %-{$width}s %s", $prefix, $status)));
            if ($status === 'changed, CHANGELOG.md not updated') {
                $missing = true;
                $this->annotate($output, "{$prefix}/" . self::CHANGELOG_FILE, "{$prefix} changed without an entry in its CHANGELOG.md");
            } elseif ($status === 'changed, Unreleased not updated') {
                $missing = true;
                $this->annotate($output, "{$prefix}/" . self::CHANGELOG_FILE, "{$prefix} changed without an entry under Unreleased in its CHANGELOG.md");
            } elseif ($status === 'changed, has no CHANGELOG.md') {
                $noChangelog = true;
                $this->annotate($output, "{$prefix}/" . self::CHANGELOG_FILE, "{$prefix} has no CHANGELOG.md");
            } elseif ($status === 'CHANGELOG.md deleted') {
                $deleted = true;
                $this->annotate($output, "{$prefix}/" . self::CHANGELOG_FILE, "{$prefix}/" . self::CHANGELOG_FILE . ' was deleted');
            }
        }
        if (!$found) {
            $output->writeln('  No package changed.');
        }
        $errors = 0;
        $released = $unlinked = false;
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
                $this->error($output, $file, $e->getMessage(), $e->lineNumber);
            }
            if (!array_key_exists($prefix, $baseChangelogs)) {
                continue;
            }
            foreach ($this->releasedChanges($baseChangelogs[$prefix], $changelog) as $problem) {
                $released = true;
                $this->error($output, $file, $problem);
            }
            if ($url === null) {
                continue;
            }
            foreach ($this->unlinkedEntries($baseChangelogs[$prefix], $changelog, $url) as $entry) {
                $unlinked = true;
                $this->error($output, $file, "entry doesn't link to #{$pr}: " . explode("\n", $entry)[0]);
            }
        }
        if ($noChangelog) {
            $output->writeln('');
            $output->writeln('Add a CHANGELOG.md to each package without one, following the existing ones.');
        }
        if ($missing) {
            $output->writeln('');
            $output->writeln('Add an entry under Unreleased in each changelog not updated, if the change affects users.');
            $output->writeln("If it doesn't, a maintainer can add the skip-changelog label.");
        }
        if ($released) {
            $output->writeln('');
            $output->writeln('Move new entries from a released section to Unreleased.');
            $output->writeln('To correct a released section on purpose, a maintainer can add the skip-changelog label.');
        }
        if ($unlinked) {
            $output->writeln('');
            $output->writeln("End each new entry with ([#{$pr}]), and add this line at the end of the file:");
            $output->writeln(OutputFormatter::escape("[#{$pr}]: {$url}"));
        }

        return !$missing && !$deleted && !$noChangelog && !$released && !$unlinked && $errors === 0 ? Command::SUCCESS : Command::FAILURE;
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
     * The revision git diff compares from. One revision is compared from itself, as is the start of
     * a range. `A...B` compares from the merge base of A and B, as git diff does.
     *
     * @param non-empty-list<string> $revisions
     * @throws RuntimeException with git's own error, when a revision of `A...B` is not in the checkout
     */
    private function base(string $path, array $revisions): string
    {
        if (count($revisions) === 1 && preg_match('/^(.*?)(\.\.\.?)(.*)$/', $revisions[0], $matches) === 1) {
            $from = $matches[1] === '' ? 'HEAD' : $matches[1];
            if ($matches[2] === '...') {
                return $this->git->mergeBase($path, $from, $matches[3] === '' ? 'HEAD' : $matches[3]);
            }

            return $from;
        }

        return $revisions[0];
    }

    /**
     * Whether the Unreleased entries differ between two versions of a changelog. Entries are
     * compared as parsed, so re-wrapping one is not a change. A base that is missing or doesn't
     * parse has no entries, so the pull request isn't blamed for it. A target that doesn't parse
     * counts as changed, as the parse error is reported on its own.
     */
    private function unreleasedChanged(?string $base, ?string $target): bool
    {
        $after = $this->unreleased($target);

        return $after === null || ($this->unreleased($base) ?? []) !== $after;
    }

    /**
     * A problem for each dated section of $base that is gone or different in $target. Sections are
     * compared by their release notes, so a link definition added for an Unreleased entry is not a
     * change. A new dated section, as release:prep adds, is not a problem.
     *
     * @return list<string>
     */
    private function releasedChanges(?string $base, string $target): array
    {
        if ($base === null) {
            return [];
        }

        try {
            $before = new ChangelogReleases($base);
            $after = new ChangelogReleases($target);
        } catch (ChangelogException) {
            return [];
        }
        $problems = [];
        foreach ($before->versions() as $version) {
            if (!in_array($version, $after->versions(), true)) {
                $problems[] = "released section {$version} removed";
            } elseif ($before->notes($version) !== $after->notes($version)) {
                $problems[] = "released section {$version} changed";
            }
        }

        return $problems;
    }

    /**
     * The Unreleased entries of $target that are not in $base and don't link to $url. A reworded
     * entry is new. Every entry of a base that is missing or doesn't parse is new.
     *
     * @return list<string>
     */
    private function unlinkedEntries(?string $base, string $target, string $url): array
    {
        $before = array_merge(...array_values($this->unreleased($base) ?? []));
        $after = array_merge(...array_values($this->unreleased($target) ?? []));
        $links = new ChangelogLinks($target);

        return array_values(array_filter(
            $after,
            static fn (string $entry): bool => !in_array($entry, $before, true) && !$links->linksTo($entry, $url)
        ));
    }

    /**
     * @return ?array<string, list<string>> null when $changelog is missing or doesn't parse
     */
    private function unreleased(?string $changelog): ?array
    {
        if ($changelog === null) {
            return null;
        }

        try {
            return $this->parser->parseUnreleased($changelog)->all();
        } catch (ChangelogException) {
            return null;
        }
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
     * $message about $file, also shown against the file in the pull request under GitHub Actions.
     */
    private function error(OutputInterface $output, string $file, string $message, ?int $line = null): void
    {
        $output->writeln('<error>' . OutputFormatter::escape("{$file}: {$message}") . '</error>');
        $this->annotate($output, $file, $message, $line);
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
