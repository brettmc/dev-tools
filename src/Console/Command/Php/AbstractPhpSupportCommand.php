<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command\Php;

use DateTimeImmutable;
use DateTimeZone;
use Http\Discovery\Psr18ClientDiscovery;
use InvalidArgumentException;
use OpenTelemetry\DevTools\Console\Command\BaseCommand;
use OpenTelemetry\DevTools\Package\Php\PhpReleaseStates;
use OpenTelemetry\DevTools\Package\Php\ProjectPhpVersionsReader;
use OpenTelemetry\DevTools\Package\Php\SupportPolicy;
use OpenTelemetry\DevTools\Package\Php\SupportReport;
use OpenTelemetry\DevTools\Util\Path;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Compares the PHP versions of a project with the support policy: a PHP version is supported until
 * a number of months after its security support ends, according to php.net.
 */
abstract class AbstractPhpSupportCommand extends BaseCommand
{
    private const MAX_MONTHS = 1200;
    private const MAX_NOTICE_DAYS = 36500;

    protected ?ClientInterface $client;
    protected ProjectPhpVersionsReader $reader;

    /**
     * @param ClientInterface|null $client a PSR-18 client. Kept aside rather than discovered here,
     *                                     as discovery throws when none is installed, which should not
     *                                     happen merely by listing the commands
     */
    public function __construct(?ClientInterface $client = null, ?ProjectPhpVersionsReader $reader = null)
    {
        parent::__construct();
        $this->client = $client;
        $this->reader = $reader ?? new ProjectPhpVersionsReader();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'path to the project (default: working directory)')
            ->addOption('workflow', null, InputOption::VALUE_REQUIRED, 'workflow with the PHP versions CI tests, relative to the path', ProjectPhpVersionsReader::DEFAULT_WORKFLOW)
            ->addOption('months', null, InputOption::VALUE_REQUIRED, 'months a PHP version is supported after its security support ends', (string) SupportPolicy::DEFAULT_MONTHS)
            ->addOption('notice-days', null, InputOption::VALUE_REQUIRED, 'days before the end of support to ask for dropping the minimum version', (string) SupportPolicy::DEFAULT_NOTICE_DAYS)
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'the date to evaluate, YYYY-MM-DD (default: today)')
        ;
    }

    /**
     * Prints an error. The message is escaped, as it holds text of the project, which may look like a style tag.
     */
    protected function fail(OutputInterface $output, string $message): int
    {
        $output->writeln('<error>' . OutputFormatter::escape($message) . '</error>');

        return Command::FAILURE;
    }

    /**
     * The checkout --path names, or null, having said why not.
     */
    protected function projectPath(InputInterface $input, OutputInterface $output): ?string
    {
        try {
            return $this->checkoutPath($input->getOption('path'));
        } catch (RuntimeException $e) {
            $this->fail($output, $e->getMessage());

            return null;
        }
    }

    protected function client(): ClientInterface
    {
        return $this->client ??= Psr18ClientDiscovery::find();
    }

    /**
     * A file the way the options give it: absolute, or relative to the path.
     */
    protected function resolve(string $path, string $file): string
    {
        return Path::absolute(str_starts_with($file, '/') ? $file : "{$path}/{$file}");
    }

    /**
     * @throws InvalidArgumentException for a malformed option
     */
    protected function evaluate(InputInterface $input, string $path): SupportReport
    {
        $today = $this->date($input->getOption('date'));
        $months = $this->positiveInt($input->getOption('months'), 'months', self::MAX_MONTHS);
        $noticeDays = $this->positiveInt($input->getOption('notice-days'), 'notice-days', self::MAX_NOTICE_DAYS);

        return (new SupportPolicy($months, $noticeDays))->evaluate(
            (new PhpReleaseStates($this->client()))->fetch(),
            $this->reader->read($path, $input->getOption('workflow')),
            $today,
        );
    }

    private function date(?string $date): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        if ($date === null) {
            return new DateTimeImmutable('today', $utc);
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $utc);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("--date must be a date like 2026-12-31, got {$date}");
        }

        return $parsed;
    }

    private function positiveInt(mixed $value, string $option, int $max): int
    {
        if (!is_string($value) || !ctype_digit($value) || strlen($value) > 6 || (int) $value < 1 || (int) $value > $max) {
            throw new InvalidArgumentException("--{$option} must be a whole number from 1 to {$max}");
        }

        return (int) $value;
    }
}
