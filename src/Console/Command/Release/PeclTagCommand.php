<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Release;

use Http\Discovery\Psr18ClientDiscovery;
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

    /**
     * @psalm-suppress PossiblyNullPropertyFetch
     */
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->token = $input->getOption('token');
        $branch = $input->getOption('branch');
        $this->dry_run = $input->getOption('dry-run');
        $this->client = Psr18ClientDiscovery::find();
        $this->registerInputAndOutput($input, $output);
        $project = new Project(self::REPOSITORY);
        $repository = new Repository();
        $repository->downstream = $project;
        $repository->upstream = $project;

        $sha = $this->get_sha_for_branch($repository, $branch);
        $repository->latestRelease = $this->get_latest_release($repository);
        $prev = $repository->latestRelease->version;

        $question = new Question("<question>Latest={$prev}, enter new tag (blank to skip):</question>", null);

        $helper = new QuestionHelper();
        $newVersion = $helper->ask($this->input, $this->output, $question);
        if (!$newVersion) {
            $this->output->writeln('<info>[SKIP] not going to tag</info>');

            return Command::SUCCESS;
        }
        $this->output->writeln("[INFO] Creating tag {$newVersion} from branch {$branch}");
        $this->create_tag($repository, $sha, $newVersion, "Tagging {$newVersion}");

        return Command::SUCCESS;
    }

    /**
     * Create an annotated tag
     */
    protected function create_tag(Repository $repository, string $sha, string $tag, string $message): void
    {
        $url = "https://api.github.com/repos/{$repository->upstream}/git/tags";
        $body = json_encode([
            'tag' => $tag,
            'message' => $message,
            'object' => $sha,
            'type' => 'commit',
        ], JSON_UNESCAPED_SLASHES);
        if ($this->dry_run) {
            $this->output->writeln("[DRY-RUN] POST {$url}");

            return;
        }
        $response = $this->post($url, $body);
        if ($response->getStatusCode() !== 201) {
            $this->output->writeln("<error>[ERROR] ({$response->getStatusCode()}) {$response->getBody()->getContents()}</error>");
            $this->output->writeln('[HELP] X-Accepted-GitHub-Permissions: ' . $response->getHeaderLine('X-Accepted-GitHub-Permissions'));

            return;
        }
        $json = json_decode($response->getBody()->getContents());
        $this->output->writeln("<info>[CREATED] {$repository->upstream} $tag: </info> {$json->url}");

        $this->create_reference($repository, $json->sha, $json->tag);
    }

    protected function create_reference(Repository $repository, string $sha, string $tag): void
    {
        $url = "https://api.github.com/repos/{$repository->upstream}/git/refs";
        $body = json_encode([
            'ref' => "refs/tags/{$tag}",
            'sha' => $sha,
        ], JSON_UNESCAPED_SLASHES);
        if ($this->dry_run) {
            $this->output->writeln("[DRY-RUN] POST {$url} {$body}");

            return;
        }
        $response = $this->post($url, $body);
        if ($response->getStatusCode() !== 201) {
            $this->output->writeln("<error>[ERROR] ({$response->getStatusCode()}) {$response->getBody()->getContents()}</error>");
        } else {
            $json = json_decode($response->getBody()->getContents());
            $this->output->writeln("<info>[CREATED] {$repository->upstream} $tag: </info> {$json->url}");
        }
    }
}
