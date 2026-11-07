<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

class BaseCommand extends Command
{
    protected InputInterface $input;
    protected OutputInterface $output;
    protected SymfonyStyle $style;

    public function getInput(): InputInterface
    {
        return $this->input;
    }

    public function getOutput(): OutputInterface
    {
        return $this->output;
    }

    /**
     * @psalm-suppress RedundantPropertyInitializationCheck
     */
    public function getStyle(): SymfonyStyle
    {
        return $this->style ?? $this->style = new SymfonyStyle(
            $this->getInput(),
            $this->getOutput()
        );
    }

    protected function registerInput(InputInterface $input): void
    {
        $this->input = $input;
    }

    protected function registerOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    protected function registerInputAndOutput(InputInterface $input, OutputInterface $output): void
    {
        $this->registerInput($input);
        $this->registerOutput($output);
    }

    protected function writeTitle(): void
    {
        $this->getStyle()->title($this->getName() ?? __CLASS__);
    }

    protected function writeSection(string $message): void
    {
        $this->getStyle()->section($message);
    }

    protected function writeLine(string $message): void
    {
        $this->getStyle()->writeln($message);
    }

    protected function writeBlankLine(int $count = 1): void
    {
        $this->getStyle()->newLine($count);
    }

    protected function writeListing(array $elements): void
    {
        $this->getStyle()->listing($elements);
    }

    protected function writeSuccess(?string $message = null): void
    {
        $this->getStyle()->block((string) $message, 'OK', 'fg=black;bg=green', ' ', false);
    }

    protected function writeError(?string $message = null): void
    {
        $this->getStyle()->error((string) $message);
    }

    protected function writeOk(): void
    {
        $this->writeSuccess();
    }

    /**
     * @suppress PhanTypeMismatchArgumentNullable
     */
    protected function writeThrowable(Throwable $throwable): void
    {
        $this->writeBlankLine();
        $this->writeError('- ' . $throwable->getMessage());
        $this->writeBlankLine();
        $this->writeLine('- TRACE:');
        $this->writeLine('- ' . $throwable->getTraceAsString());

        if ($throwable->getPrevious() instanceof Throwable) {
            $this->writeBlankLine();
            $this->writeLine('- PREVIOUS TRACE: ');
            $this->writeThrowable($throwable->getPrevious());
        }
        $this->writeBlankLine();
    }
}
