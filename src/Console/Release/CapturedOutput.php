<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Console\Release;

use LogicException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Keeps what a command writes to stdout, and passes what it writes to stderr on to $errors, as a
 * shell redirecting only stdout would.
 */
final class CapturedOutput extends BufferedOutput implements ConsoleOutputInterface
{
    public function __construct(private OutputInterface $errors)
    {
        parent::__construct();
    }

    #[\Override]
    public function getErrorOutput(): OutputInterface
    {
        return $this->errors;
    }

    #[\Override]
    public function setErrorOutput(OutputInterface $error): void
    {
        $this->errors = $error;
    }

    #[\Override]
    public function section(): ConsoleSectionOutput
    {
        throw new LogicException('A captured output has no sections');
    }
}
