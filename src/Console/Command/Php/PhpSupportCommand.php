<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Php;

use InvalidArgumentException;
use OpenTelemetry\DevTools\Package\Php\SupportDocs;
use OpenTelemetry\DevTools\Package\Php\SupportReport;
use OpenTelemetry\DevTools\Package\Php\SupportRow;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shows which PHP versions a project supports, and writes that to its docs.
 *
 * The PHP badges, the summary and the table of supported versions go between the
 * `<!-- php-support-*:start -->` and `<!-- php-support-*:end -->` markers of the --file files.
 */
class PhpSupportCommand extends AbstractPhpSupportCommand
{
    public const NAME = 'php:support';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName(self::NAME)
            ->setDescription('Show the supported PHP versions, and write them to the docs')
            ->addOption('file', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'file with php-support markers, relative to the path (default: README.md)')
            ->addOption('policy-link', null, InputOption::VALUE_REQUIRED, 'where the badges and the summary link to', SupportDocs::DEFAULT_POLICY_LINK)
            ->addOption('write', null, InputOption::VALUE_NONE, 'write the text between the markers of the files')
            ->addOption('check', null, InputOption::VALUE_NONE, 'fail if the text between the markers is out of date')
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
        $write = (bool) $input->getOption('write');
        $check = (bool) $input->getOption('check');
        if ($write && $check) {
            return $this->fail($output, '--write and --check cannot be used together');
        }

        try {
            $report = $this->evaluate($input, $path);
            $this->table($report, $output);
            if (!$write && !$check) {
                return Command::SUCCESS;
            }

            return $this->docs($input, $output, $report, $write, $path);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return $this->fail($output, $e->getMessage());
        }
    }

    private function table(SupportReport $report, OutputInterface $output): void
    {
        $table = new Table($output);
        $table->setHeaders(['PHP', 'Released', 'Security support ends', 'Supported until', 'Tested', 'Action']);
        foreach ($report->rows as $row) {
            $table->addRow([
                $row->branch->version,
                $row->branch->released->format('Y-m-d'),
                $row->branch->securitySupportEnd->format('Y-m-d'),
                $row->supportedUntil->format('Y-m-d'),
                $row->tested ? 'yes' : 'no',
                match ($row->action) {
                    SupportRow::DROP => 'drop',
                    SupportRow::ADD => 'add to CI',
                    default => '',
                },
            ]);
        }
        $table->render();
    }

    /**
     * The file as seen from the project: the link to the policy is made relative to it. A file outside the project
     * keeps its absolute path.
     */
    private function relative(string $path, string $file): string
    {
        return str_starts_with($file, $path . '/') ? substr($file, strlen($path) + 1) : $file;
    }

    private function docs(InputInterface $input, OutputInterface $output, SupportReport $report, bool $write, string $path): int
    {
        $docs = new SupportDocs($input->getOption('policy-link'));
        $updates = [];
        foreach ($input->getOption('file') ?: ['README.md'] as $name) {
            $file = $this->resolve($path, $name);
            $text = is_file($file) ? file_get_contents($file) : false;
            if ($text === false) {
                throw new RuntimeException("{$file} not found");
            }

            try {
                $updated = $docs->apply($text, $docs->blocks($report, $this->relative($path, $file)));
            } catch (RuntimeException $e) {
                throw new RuntimeException("{$name}: {$e->getMessage()}", 0, $e);
            }
            if ($updated !== $text) {
                $updates[$name] = [$file, $updated];
            }
        }
        //nothing is written until every file has been read and updated, so a file that cannot be read or has
        //bad markers leaves the rest alone
        foreach ($updates as $name => [$file, $updated]) {
            if (!$write) {
                $output->writeln('<error>' . OutputFormatter::escape($name) . ' is out of date</error>');

                continue;
            }
            if (@file_put_contents($file, $updated) === false) {
                throw new RuntimeException("Could not write {$file}");
            }
            $output->writeln("Updated {$name}");
        }
        if ($updates !== [] && !$write) {
            $output->writeln('Run <info>otel ' . self::NAME . ' --write</info> to update it.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
