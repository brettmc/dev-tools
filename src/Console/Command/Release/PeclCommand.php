<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Release;

use DOMDocument;
use Exception;
use Http\Discovery\Psr18ClientDiscovery;
use OpenTelemetry\DevTools\Console\Release\Project;
use OpenTelemetry\DevTools\Console\Release\Repository;
use SimpleXMLElement;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

class PeclCommand extends AbstractReleaseCommand
{
    private const OWNER = 'open-telemetry';
    private const REPO = 'opentelemetry-php-instrumentation';
    private const REPOSITORY = self::OWNER . '/' . self::REPO;

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName('release:pecl')
            ->setDescription('Update auto-instrumentation package.xml for PECL release')
            ->addOption('force', ['f'], InputOption::VALUE_NONE, 'force')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->getOption('force');
        $this->client = Psr18ClientDiscovery::find();
        $this->registerInputAndOutput($input, $output);
        $project = new Project(self::REPOSITORY);
        $repository = new Repository();
        $repository->downstream = $project;
        $repository->upstream = $project;
        $repository->latestRelease = $this->get_latest_release($repository);
        if ($repository->latestRelease === null) {
            $this->output->writeln("<error>No latest release found for {$repository->upstream}</error>");

            return Command::FAILURE;
        }
        $repository->commits = $this->get_downstream_unreleased_commits($repository);
        if (count($repository->commits) === 0) {
            $this->output->writeln("<info>No unreleased commits since {$repository->latestRelease->version}</info>");
            if (!$force) {
                return Command::SUCCESS;
            }
        }

        $url = sprintf('https://raw.githubusercontent.com/%s/main/ext/package.xml', self::REPOSITORY);
        $response = $this->fetch($url);
        if ($response->getStatusCode() !== 200) {
            throw new Exception("Error fetching {$url}");
        }

        $xml = new SimpleXMLElement($response->getBody()->getContents());

        $this->process($repository, $xml);

        return Command::SUCCESS;
    }

    /**
     * @psalm-suppress PossiblyNullPropertyFetch
     */
    private function process(Repository $repository, SimpleXMLElement $xml): void
    {
        $cnt = count($repository->commits);
        $this->output->writeln("<info>Last release {$repository->latestRelease->version} @ {$repository->latestRelease->timestamp}</info>");
        $this->output->writeln("<info>[{$repository->downstream}]</info> {$cnt} unreleased change(s):");
        foreach ($repository->commits as $commit) {
            $this->output->writeln("<comment>* [#{$commit->pullRequest->id}] {$commit->pullRequest->title} ({$commit->pullRequest->author})</comment>");
        }
        $prev = ($repository->latestRelease === null)
            ? '-nothing-'
            : $repository->latestRelease->version;
        $question = new Question("<question>Latest={$prev}, enter new tag (blank to skip):</question>", null);

        $helper = new QuestionHelper();
        $newVersion = $helper->ask($this->input, $this->output, $question);
        if (!$newVersion) {
            $this->output->writeln("<info>[SKIP] not going to release {$repository->downstream}</info>");

            return;
        }

        $question = new ChoiceQuestion(
            '<question>Is this a beta or stable release?</question>',
            ['stable', 'beta'],
            'stable',
        );
        $stability = $helper->ask($this->input, $this->output, $question);

        //new release data
        $release = [
            'date' => date('Y-m-d'),
            'time' => date('H:i:s'),
            'version' => [
                'release' => $newVersion,
                'api' => '1.0',
            ],
            'stability' => [
                'release' => $stability,
                'api' => 'stable',
            ],
            'notes' => $this->format_notes($newVersion),
        ];
        $this->output->writeln($this->convertPackageXml($xml, $release));
    }

    protected function convertPackageXml(SimpleXMLElement $xml, array $new): string
    {
        //add current release to changelog
        $release = $xml->changelog->addChild('release');
        $release->addChild('date', (string) $xml->date);
        $release->addChild('time', (string) $xml->time);
        $version = $release->addChild('version');
        $version->addChild('release', (string) $xml->version->release);
        $version->addChild('api', (string) $xml->version->api);
        $stability = $release->addChild('stability');
        $stability->addChild('release', (string) $xml->stability->release);
        $stability->addChild('api', (string) $xml->stability->api);
        $release->addChild('license', (string) $xml->license);
        $release->addChild('notes', (string) $xml->notes);

        //update new release details
        $xml->date = $new['date'];
        $xml->time = $new['time'];
        $xml->version->release = $new['version']['release'];
        $xml->version->api = $new['version']['api'];
        $xml->stability->release = $new['stability']['release'];
        $xml->stability->api = $new['stability']['api'];
        $xml->notes = $new['notes'];

        //prettify
        $pretty = new DOMDocument();
        $pretty->preserveWhiteSpace = false;
        $pretty->formatOutput = true;
        $pretty->loadXML($xml->saveXML());

        return $pretty->saveXML();
    }

    private function format_notes(string $version): string
    {
        return sprintf('See https://github.com/%s/%s/releases/tag/%s', self::OWNER, self::REPO, $version);
    }
}
