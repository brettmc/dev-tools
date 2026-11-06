<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Release;

use DOMDocument;
use Http\Discovery\Psr18ClientDiscovery;
use OpenTelemetry\DevTools\Console\Release\Project;
use OpenTelemetry\DevTools\Console\Release\Repository;
use OpenTelemetry\DevTools\Package\Git\GitRunner;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
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
    private const PACKAGE_XML = 'ext/package.xml';
    private ?ClientInterface $clientOverride;
    private GitRunner $git;

    public function __construct(?ClientInterface $client = null, ?GitRunner $git = null)
    {
        parent::__construct();
        //held aside rather than assigned to $this->client, so that discovery stays lazy: it throws
        //when no PSR-18 client is installed, which should not happen merely by listing the commands
        $this->clientOverride = $client;
        $this->git = $git ?? new GitRunner();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName('release:pecl')
            ->setDescription('Update auto-instrumentation package.xml for PECL release')
            ->addOption('path', null, InputOption::VALUE_OPTIONAL, 'path to an opentelemetry-php-instrumentation checkout (default: working directory)')
            ->addOption('force', ['f'], InputOption::VALUE_NONE, 'force')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->getOption('force');
        $path = rtrim($input->getOption('path') ?? (string) getcwd(), '/');
        $this->client = $this->clientOverride ?? Psr18ClientDiscovery::find();
        $this->registerInputAndOutput($input, $output);
        $packageXml = $path . '/' . self::PACKAGE_XML;
        if (!is_file($packageXml)) {
            $this->output->writeln(sprintf('<error>%s has no %s: pass --path to an %s checkout</error>', $path, self::PACKAGE_XML, self::REPOSITORY));

            return Command::FAILURE;
        }
        $project = new Project(self::REPOSITORY);
        $repository = new Repository();
        $repository->downstream = $project;
        $repository->upstream = $project;
        $repository->latestRelease = $this->get_latest_release($repository);
        if ($repository->latestRelease === null) {
            $this->output->writeln("<error>No latest release found for {$repository->upstream}</error>");

            return Command::FAILURE;
        }

        try {
            $log = $this->git->log($path, 'HEAD', '.', $this->since($repository->latestRelease));
        } catch (RuntimeException $e) {
            $this->output->writeln("<error>{$path} cannot be read by git: {$e->getMessage()}</error>");

            return Command::FAILURE;
        }
        $repository->commits = $this->commits($repository, $log);
        if (count($repository->commits) === 0) {
            $this->output->writeln("<info>No unreleased commits since {$repository->latestRelease->version}</info>");
            if (!$force) {
                return Command::SUCCESS;
            }
        }

        $xml = new SimpleXMLElement((string) file_get_contents($packageXml));

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
