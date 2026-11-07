<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Release;

use DOMDocument;
use OpenTelemetry\DevTools\Console\Release\GitHub;
use OpenTelemetry\DevTools\Console\Release\GitHubException;
use OpenTelemetry\DevTools\Console\Release\Project;
use OpenTelemetry\DevTools\Console\Release\Release;
use OpenTelemetry\DevTools\Console\Release\Repository;
use OpenTelemetry\DevTools\Package\Git\GitRunner;
use RuntimeException;
use SimpleXMLElement;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
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
    private GitRunner $git;

    public function __construct(?GitHub $github = null, ?GitRunner $git = null)
    {
        parent::__construct($github);
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
        $this->registerInputAndOutput($input, $output);
        $path = $this->checkoutPath($input->getOption('path'));
        if ($path === null) {
            return Command::FAILURE;
        }
        $packageXml = $path . '/' . self::PACKAGE_XML;
        if (!is_file($packageXml)) {
            $this->output->writeln(sprintf('<error>%s has no %s: pass --path to an %s checkout</error>', $path, self::PACKAGE_XML, self::REPOSITORY));

            return Command::FAILURE;
        }

        try {
            $head = $this->git->head($path);
            //git log stops without a word at the shallow boundary, so too few commits would be listed
            if ($this->git->isShallow($path)) {
                $this->output->writeln("<error>{$path} is a shallow clone, so its history may not reach the latest release. Clone it in full.</error>");

                return Command::FAILURE;
            }
        } catch (RuntimeException $e) {
            $this->output->writeln("<error>{$path} cannot be read by git: {$e->getMessage()}</error>");

            return Command::FAILURE;
        }
        $project = new Project(self::REPOSITORY);
        $repository = new Repository();
        $repository->downstream = $project;
        $repository->upstream = $project;

        try {
            $tip = $this->branch_tip(self::REPOSITORY);
            $repository->latestRelease = $this->get_latest_release($repository);
        } catch (GitHubException $e) {
            $this->output->writeln('<error>' . OutputFormatter::escape($e->getMessage()) . '</error>');

            return Command::FAILURE;
        }
        if ($repository->latestRelease === null) {
            $this->output->writeln("<error>No latest release found for {$repository->upstream}</error>");

            return Command::FAILURE;
        }

        if ($head !== $tip) {
            $this->output->writeln(sprintf(
                '<error>%s is at %s, but %s %s is at %s. Check out and pull %s before releasing.</error>',
                $path,
                $head,
                self::REPOSITORY,
                $this->source_branch,
                $tip,
                $this->source_branch,
            ));

            return Command::FAILURE;
        }

        try {
            $log = $this->git->log($path, 'HEAD', '.', $this->since($repository->latestRelease));
        } catch (RuntimeException $e) {
            $this->output->writeln("<error>{$path} cannot be read by git: {$e->getMessage()}</error>");

            return Command::FAILURE;
        }
        $repository->commits = $this->commits($log);
        if (count($repository->commits) === 0) {
            $this->output->writeln("<info>No unreleased commits since {$repository->latestRelease->version}</info>");
            if (!$force) {
                return Command::SUCCESS;
            }
        }

        $xml = new SimpleXMLElement((string) file_get_contents($packageXml));

        $this->process($repository, $repository->latestRelease, $xml);

        return Command::SUCCESS;
    }

    private function process(Repository $repository, Release $latest, SimpleXMLElement $xml): void
    {
        $cnt = count($repository->commits);
        $this->output->writeln("<info>Last release {$latest->version} @ {$latest->timestamp}</info>");
        $this->output->writeln("<info>[{$repository->downstream}]</info> {$cnt} unreleased change(s):");
        foreach ($repository->commits as $commit) {
            $this->output->writeln("<comment>* {$commit->subject} ({$commit->author})</comment>");
        }
        $question = new Question("<question>Latest={$latest->version}, enter new tag (blank to skip):</question>", null);

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
        $release->addChild('date', self::text($xml->date));
        $release->addChild('time', self::text($xml->time));
        $version = $release->addChild('version');
        $version->addChild('release', self::text($xml->version->release));
        $version->addChild('api', self::text($xml->version->api));
        $stability = $release->addChild('stability');
        $stability->addChild('release', self::text($xml->stability->release));
        $stability->addChild('api', self::text($xml->stability->api));
        $release->addChild('license', self::text($xml->license));
        $release->addChild('notes', self::text($xml->notes));

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

    /**
     * addChild() takes its value as XML, not text, so an ampersand in it is an unterminated entity.
     */
    private static function text(?SimpleXMLElement $element): string
    {
        return htmlspecialchars((string) $element, ENT_XML1);
    }

    private function format_notes(string $version): string
    {
        return sprintf('See https://github.com/%s/%s/releases/tag/%s', self::OWNER, self::REPO, $version);
    }
}
