<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Release;

use OpenTelemetry\DevTools\Console\Release\GitHubException;
use OpenTelemetry\DevTools\Console\Release\Overrides;
use OpenTelemetry\DevTools\Console\Release\ReleaseBuild;
use OpenTelemetry\DevTools\Console\Release\ReleaseDescription;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Checks that a release pull request may be merged: its head commit was built by release:pr on the
 * tip of main, with the overrides now in its description, and any breaking changes are approved.
 * The description is read when this runs, not taken from the event, which a re-run repeats.
 */
class ReleasePrCheckCommand extends AbstractReleaseCommand
{
    public const NAME = 'release:pr:check';

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Check that a release pull request is built on main with its overrides, and its breaking changes approved')
            ->addOption('repository', null, InputOption::VALUE_REQUIRED, 'the monorepo, ' . implode(' or ', self::AVAILABLE_REPOS))
            ->addOption('number', null, InputOption::VALUE_REQUIRED, 'the number of the pull request')
            ->addOption('head', null, InputOption::VALUE_REQUIRED, 'the sha of its head commit, as the event gives it')
            ->addOption('token', ['t'], InputOption::VALUE_REQUIRED, 'github token that can read the pull requests of the monorepo (default: GITHUB_TOKEN)')
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
        $number = $input->getOption('number');
        if (!is_string($number) || preg_match('/^[1-9][0-9]*$/', $number) !== 1) {
            return $this->fail('--number must be the number of the pull request');
        }
        $head = $input->getOption('head');
        if (!is_string($head) || preg_match('/^[0-9a-f]{40}$/', $head) !== 1) {
            return $this->fail('--head must be the full sha of the pull request\'s head commit');
        }
        if ($this->token($input) === null) {
            return $this->fail(self::NAME . ' needs a GitHub token: --token, or GITHUB_TOKEN');
        }

        try {
            $commit = $this->github->commitSummary($repository, $head);
            $build = ReleaseBuild::fromMessage($commit['message']);
            if ($build === null) {
                return $this->fail('The head commit was not built by release:pr. Rebuild the pull request.');
            }
            $body = $this->github->pullRequest($repository, (int) $number)['body'];
            $main = $this->branch_tip($repository);
        } catch (GitHubException $e) {
            return $this->fail($e->getMessage());
        }

        $problems = [];
        //a rebase or Update branch keeps the message, and so Built-On, but changes the parent
        if ($commit['parents'] !== [$main] || $build->main !== $main) {
            $problems[] = 'This pull request is not built on the tip of main. Rebuild it: tick Open a release PR on the release dashboard issue, or run the Release PR workflow.';
        }
        //a rebuild that is queued, or failed, leaves the head built with the overrides before the edit
        if (!Overrides::fromBody($body)->equals($build->overrides)) {
            $problems[] = 'The overrides in the description are not the ones this pull request was built with. Wait for the rebuild to finish, or read its run for the error.';
        }
        if ($build->breaking !== null && !ReleaseDescription::approved($body, $build->breaking)) {
            $problems[] = 'Read the breaking changes in the description, then tick the box.';
        }
        if ($problems !== []) {
            array_map($this->fail(...), $problems);

            return Command::FAILURE;
        }

        $output->writeln("#{$number} is built on the tip of main, with the overrides in its description");
        $build->breaking !== null && $output->writeln('The breaking changes are approved');

        return Command::SUCCESS;
    }
}
