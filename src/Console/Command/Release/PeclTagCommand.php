<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Release;

use OpenTelemetry\DevTools\Console\Release\GitHubException;
use OpenTelemetry\DevTools\Console\Release\Project;
use OpenTelemetry\DevTools\Console\Release\Repository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class PeclTagCommand extends AbstractReleaseCommand
{
    private const REPOSITORY = 'open-telemetry/opentelemetry-php-instrumentation';

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName('tag:pecl')
            ->setDescription('Create a new tag in the auto-instrumentation repository')
            ->addOption('branch', ['b'], InputOption::VALUE_OPTIONAL, 'branch to tag from', 'main')
            ->addOption('token', ['t'], InputOption::VALUE_OPTIONAL, 'github token')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'dry-run')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->token = $this->token($input);
        if ($this->token === null) {
            $output->writeln('<error>No github token provided (via --token or GITHUB_TOKEN env)</error>');

            return Command::FAILURE;
        }
        $this->source_branch = $input->getOption('branch');
        $this->dry_run = $input->getOption('dry-run');
        $this->registerInputAndOutput($input, $output);
        $project = new Project(self::REPOSITORY);
        $repository = new Repository();
        $repository->downstream = $project;
        $repository->upstream = $project;

        try {
            $sha = $this->branch_tip(self::REPOSITORY);
            $repository->latestRelease = $this->get_latest_release($repository);
        } catch (GitHubException $e) {
            $this->output->writeln("<error>{$e->getMessage()}</error>");

            return Command::FAILURE;
        }
        $prev = $repository->latestRelease->version ?? '-nothing-';

        $question = new Question("<question>Latest={$prev}, enter new tag (blank to skip):</question>", null);

        $helper = new QuestionHelper();
        $newVersion = $helper->ask($this->input, $this->output, $question);
        if (!$newVersion) {
            $this->output->writeln('<info>[SKIP] not going to tag</info>');

            return Command::SUCCESS;
        }
        $this->output->writeln("[INFO] Creating tag {$newVersion} from branch {$this->source_branch}");

        return $this->create_tag($sha, $newVersion, "Tagging {$newVersion}") ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Create an annotated tag: the tag object, then the ref that makes it a tag of the repository.
     *
     * @return bool false when either failed, having said why
     */
    private function create_tag(string $sha, string $tag, string $message): bool
    {
        $api = 'https://api.github.com/repos/' . self::REPOSITORY;
        if ($this->dry_run) {
            $this->output->writeln("[DRY-RUN] POST {$api}/git/tags");
            $this->output->writeln("[DRY-RUN] POST {$api}/git/refs refs/tags/{$tag}");

            return true;
        }

        try {
            $object = $this->github->createTag(self::REPOSITORY, $tag, $message, $sha);
            $this->output->writeln('<info>[CREATED] ' . self::REPOSITORY . " tag object {$tag}: </info> {$object['url']}");
            $ref = $this->github->createRef(self::REPOSITORY, "refs/tags/{$tag}", $object['sha']);
        } catch (GitHubException $e) {
            $this->output->writeln("<error>[ERROR] {$e->getMessage()}</error>");

            return false;
        }
        $this->output->writeln('<info>[CREATED] ' . self::REPOSITORY . " {$tag}: </info> {$ref}");

        return true;
    }
}
