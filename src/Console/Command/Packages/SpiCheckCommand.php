<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Packages;

use OpenTelemetry\DevTools\Console\Command\BaseCommand;
use OpenTelemetry\DevTools\Package\GitSplit\MonorepoCheckout;
use OpenTelemetry\DevTools\Package\Spi\OutdatedDependencyRunner;
use OpenTelemetry\DevTools\Package\Spi\PackageDependencyReader;
use OpenTelemetry\DevTools\Package\Spi\SplitDependencyChecker;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A monorepo replacement for `composer spi:show-outdated-package-dependencies`.
 *
 * That check compares each `#[PackageDependency]` with the latest release, so a provider in the
 * monorepo cannot depend on a version of another split package that is only about to be released.
 * Here a dependency on a split package is checked against the tree instead: the package's
 * `dev-main` alias, and the root `replace` that satisfies it at runtime. Other packages are left to
 * tbachert/spi.
 */
class SpiCheckCommand extends BaseCommand
{
    public const NAME = 'packages:spi:check';

    private PackageDependencyReader $reader;
    private OutdatedDependencyRunner $runner;
    private SplitDependencyChecker $checker;

    public function __construct(?PackageDependencyReader $reader = null, ?OutdatedDependencyRunner $runner = null)
    {
        parent::__construct(self::NAME);
        $this->reader = $reader ?? new PackageDependencyReader();
        $this->runner = $runner ?? new OutdatedDependencyRunner();
        $this->checker = new SplitDependencyChecker();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setDescription('Check #[PackageDependency] on split packages against the monorepo tree')
            ->addOption('path', null, InputOption::VALUE_OPTIONAL, 'path to a monorepo checkout (default: working directory)')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->registerInputAndOutput($input, $output);
        $path = rtrim($input->getOption('path') ?? (string) getcwd(), '/');

        try {
            $checkout = MonorepoCheckout::read($path);
            $found = $this->reader->read($path);
        } catch (RuntimeException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");

            return Command::FAILURE;
        }
        foreach ($found->missing as $provider) {
            $output->writeln("<comment>{$provider} is registered in extra.spi but does not load</comment>");
        }
        $report = $this->checker->check($found->dependencies, $checkout->aliases, $checkout->replace);

        if ($report->rows !== []) {
            $table = new Table($output);
            $table->setHeaders(['Provider', 'Package', 'Constraint', 'Alias', 'Replace', 'Result']);
            foreach ($report->rows as $row) {
                $table->addRow([
                    $row->dependency->provider,
                    $row->dependency->package,
                    $row->dependency->constraint,
                    $row->alias ?? 'none',
                    $row->replace ?? 'none',
                    $row->problems === [] ? 'ok' : implode("\n", $row->problems),
                ]);
            }
            $table->render();
        }
        $failed = $report->failed();

        if ($report->external !== []) {
            $output->writeln('');
            $output->writeln('Checking ' . implode(', ', $report->external) . ' against their latest releases:');
            if ($this->runner->run($path, array_keys($checkout->aliases), $output) === 0) {
                //tbachert/spi prints nothing for an up-to-date dependency unless asked to be verbose
                foreach ($report->external as $package) {
                    $output->writeln("{$package}: ok");
                }
            } else {
                $failed = true;
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
