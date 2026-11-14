<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Release;

use OpenTelemetry\DevTools\Console\Release\GitHubException;
use OpenTelemetry\DevTools\Console\Release\ReleaseDescription;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adds a note to the description of a monorepo's release pull request once main has moved past
 * the commit it was built on. Nothing rebuilds the pull request when main moves, so without the
 * note it would release less than main holds.
 */
class ReleasePrStaleCommand extends AbstractReleaseCommand
{
    public const NAME = 'release:pr:stale';

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Mark the release pull request of a monorepo stale, once main has moved past it')
            ->addOption('repository', null, InputOption::VALUE_REQUIRED, 'the monorepo, ' . implode(' or ', self::AVAILABLE_REPOS))
            ->addOption('token', ['t'], InputOption::VALUE_REQUIRED, 'github token that can write the pull requests of the monorepo (default: GITHUB_TOKEN)')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->registerInputAndOutput($input, $output);
        $repository = $input->getOption('repository');
        if (!in_array($repository, self::AVAILABLE_REPOS, true)) {
            return $this->fail('--repository must be ' . implode(' or ', self::AVAILABLE_REPOS));
        }
        if ($this->token($input) === null) {
            return $this->fail(self::NAME . ' needs a GitHub token: --token, or GITHUB_TOKEN');
        }

        try {
            $pulls = $this->github->openPullRequests($repository, ReleasePrCommand::BRANCH, ReleasePrCommand::BOT);
            if (count($pulls) > 1) {
                return $this->fail("{$repository} has more than one open release pull request");
            }
            if ($pulls === []) {
                $output->writeln("{$repository} has no open release pull request");

                return Command::SUCCESS;
            }
            [$pull] = $pulls;
            if ($this->github->parents($repository, $pull['head']) === [$this->branch_tip($repository)]) {
                $output->writeln("#{$pull['number']} is built on main");
            } elseif (ReleaseDescription::isStale($pull['body'])) {
                $output->writeln("#{$pull['number']} is already marked stale");
            } else {
                //a rebuild since the listing replaced the description, and judged the staleness itself
                //GitHub has no conditional edit, so an edit between this read and the PATCH is lost
                $current = $this->github->pullRequest($repository, $pull['number'])['body'];
                if (ReleaseDescription::unmarked($current) !== ReleaseDescription::unmarked($pull['body'])) {
                    $output->writeln("#{$pull['number']} changed while this ran, and is left to the run that changed it");

                    return Command::SUCCESS;
                }
                if (ReleaseDescription::isStale($current)) {
                    $output->writeln("#{$pull['number']} is already marked stale");

                    return Command::SUCCESS;
                }
                $this->github->editPullRequest($repository, $pull['number'], null, ReleaseDescription::markStale($current));
                $output->writeln("Marked #{$pull['number']} stale");
            }
        } catch (GitHubException $e) {
            return $this->fail($e->getMessage());
        }

        return Command::SUCCESS;
    }
}
