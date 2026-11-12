<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Php;

use InvalidArgumentException;
use OpenTelemetry\DevTools\Console\Release\GitHub;
use OpenTelemetry\DevTools\Package\Php\IssueTemplate;
use OpenTelemetry\DevTools\Package\Php\SupportRow;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Opens an issue to drop the minimum PHP version when its support is about to end, and to add a
 * released PHP version to CI. The issues are written as Markdown templates, `drop.md` and `add.md`,
 * with these placeholders: `{{ version }}`, `{{ released }}`, `{{ security_support_end }}` and
 * `{{ supported_until }}`.
 *
 * An issue is not opened when one with the same title and label exists, open or closed.
 */
class PhpSupportIssuesCommand extends AbstractPhpSupportCommand
{
    public const NAME = 'php:support:issues';

    /** @var array<string, list<string>> titles of the issues that exist, by label */
    private array $existing = [];

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName(self::NAME)
            ->setDescription('Open issues to drop or add PHP versions, from Markdown templates')
            ->addOption('templates', null, InputOption::VALUE_REQUIRED, 'directory with drop.md and add.md, relative to the path', '.github/php-version-support')
            ->addOption('repo', null, InputOption::VALUE_REQUIRED, 'repository to open the issues in, owner/name (default: GITHUB_REPOSITORY)')
            ->addOption('token', ['t'], InputOption::VALUE_REQUIRED, 'github token (default: GITHUB_TOKEN)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'print the issues without contacting GitHub')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->registerInputAndOutput($input, $output);
        $path = $this->projectPath($input, $output);
        if ($path === null) {
            return Command::FAILURE;
        }
        $dryRun = (bool) $input->getOption('dry-run');
        $this->existing = [];

        try {
            $repository = $input->getOption('repo') ?: (getenv('GITHUB_REPOSITORY') ?: null);
            $token = $input->getOption('token') ?: (getenv('GITHUB_TOKEN') ?: null);
            if (!$dryRun) {
                $this->requireRepository($repository);
                if ($token === null) {
                    throw new InvalidArgumentException('Opening issues needs a GitHub token: --token, or GITHUB_TOKEN');
                }
            }
            $report = $this->evaluate($input, $path);
            $actions = $report->actions();
            if ($actions === []) {
                $output->writeln("PHP versions are in line with the support policy on {$report->today->format('Y-m-d')}: nothing to open.");

                return Command::SUCCESS;
            }
            //every template is read and rendered before an issue is opened, so a broken one stops the run early
            $issues = array_map(fn (SupportRow $row): IssueTemplate => $this->issue($input, $row, $path), $actions);
            if ($dryRun) {
                foreach ($issues as $issue) {
                    $output->writeln(OutputFormatter::escape(sprintf("Would open an issue: %s [%s]\n\n%s", $issue->title, implode(', ', $issue->labels), $issue->body)));
                }

                return Command::SUCCESS;
            }
            $github = new GitHub($this->client());
            $github->authenticate($token);
            $github->trace($output);
            foreach ($issues as $issue) {
                $this->open($output, $issue, (string) $repository, $github);
            }
        } catch (InvalidArgumentException|RuntimeException $e) {
            return $this->fail($output, $e->getMessage());
        }

        return Command::SUCCESS;
    }

    private function issue(InputInterface $input, SupportRow $row, string $path): IssueTemplate
    {
        $name = $row->action === SupportRow::DROP ? 'drop.md' : 'add.md';
        $template = IssueTemplate::fromFile($this->resolve($path, $input->getOption('templates')) . "/{$name}");
        if ($template->labels === []) {
            throw new InvalidArgumentException("{$name} needs at least one label in its front matter, to find the issues that exist");
        }

        return $template->render([
            'version' => $row->branch->version,
            'released' => $row->branch->released->format('Y-m-d'),
            'security_support_end' => $row->branch->securitySupportEnd->format('Y-m-d'),
            'supported_until' => $row->supportedUntil->format('Y-m-d'),
        ]);
    }

    private function open(OutputInterface $output, IssueTemplate $issue, string $repository, GitHub $github): void
    {
        //the issues of a label are listed once, whatever the number of issues to open
        $label = $issue->labels[0];
        $this->existing[$label] ??= $github->issueTitles($repository, $label);
        if (in_array($issue->title, $this->existing[$label], true)) {
            $output->writeln('Issue already exists: ' . OutputFormatter::escape($issue->title));

            return;
        }
        $output->writeln('Opened ' . $github->createIssue($repository, $issue->title, $issue->body, $issue->labels));
        $this->existing[$label][] = $issue->title;
    }

    private function requireRepository(?string $repository): void
    {
        $parts = explode('/', (string) $repository);
        if (count($parts) !== 2 || !preg_match('#^[\w.-]+/[\w.-]+$#D', (string) $repository) || array_intersect($parts, ['.', '..']) !== []) {
            throw new InvalidArgumentException('--repo (or GITHUB_REPOSITORY) must be owner/name');
        }
    }
}
