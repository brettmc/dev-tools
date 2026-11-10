<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Command\Packages;

use OpenTelemetry\DevTools\Console\Command\Packages\ValidatePackagesCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Packages\ValidatePackagesCommand
 */
class ValidatePackagesCommandTest extends TestCase
{
    private const VALID_COMPOSER_FILE = __DIR__ . '/_files/composer.valid.json';
    private const INVALID_COMPOSER_FILE = __DIR__ . '/_files/composer.invalid.json';
    private const BROKEN_COMPOSER_FILE = __DIR__ . '/_files/composer.broken.json';

    private string $root;

    #[\Override]
    public function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/validate-packages-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        file_put_contents("{$this->root}/composer.json", '{"name":"open-telemetry/opentelemetry"}');
    }

    #[\Override]
    public function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function test_execute_valid(): void
    {
        $this->checkout(['src/A' => self::VALID_COMPOSER_FILE, 'src/B' => self::VALID_COMPOSER_FILE, 'src/C' => self::VALID_COMPOSER_FILE]);

        $commandTester = $this->execute();

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        foreach (['A', 'B', 'C'] as $package) {
            $this->assertStringContainsString("Validating: src/{$package}/composer.json", $commandTester->getDisplay());
        }
    }

    public function test_execute_from_the_working_directory(): void
    {
        $this->checkout(['src/A/' => self::VALID_COMPOSER_FILE]);
        $cwd = (string) getcwd();
        chdir($this->root);

        try {
            $commandTester = new CommandTester(new ValidatePackagesCommand());
            $commandTester->execute([]);
        } finally {
            chdir($cwd);
        }

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('Validating composer files in: ' . realpath($this->root), $commandTester->getDisplay());
        $this->assertStringContainsString('Validating: src/A/composer.json', $commandTester->getDisplay());
    }

    public function test_execute_from_a_relative_root(): void
    {
        $this->checkout(['src/A' => self::VALID_COMPOSER_FILE]);
        $cwd = (string) getcwd();
        chdir($this->root);

        try {
            $commandTester = new CommandTester(new ValidatePackagesCommand('.'));
            $commandTester->execute([]);
        } finally {
            chdir($cwd);
        }

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode(), $commandTester->getDisplay());
        $this->assertStringContainsString('Validating composer files in: ' . realpath($this->root), $commandTester->getDisplay());
    }

    public function test_execute_invalid(): void
    {
        $this->checkout(['src/A' => self::VALID_COMPOSER_FILE, 'src/B' => self::INVALID_COMPOSER_FILE, 'src/C' => self::VALID_COMPOSER_FILE]);

        $this->assertSame(Command::FAILURE, $this->execute()->getStatusCode());
    }

    public function test_execute_invalid_shows_why(): void
    {
        $this->checkout(['src/A' => self::INVALID_COMPOSER_FILE]);

        $this->assertStringContainsString('The property description is required', $this->execute()->getDisplay());
    }

    public function test_execute_invalid_formats_composer_styles(): void
    {
        $this->checkout(['src/A' => self::INVALID_COMPOSER_FILE]);

        $this->assertStringNotContainsString('<warning>', $this->execute()->getDisplay());
    }

    public function test_execute_broken(): void
    {
        $this->checkout(['src/A' => self::VALID_COMPOSER_FILE, 'src/B' => self::BROKEN_COMPOSER_FILE, 'src/C' => self::VALID_COMPOSER_FILE]);

        $this->assertSame(Command::FAILURE, $this->execute()->getStatusCode());
    }

    public function test_split_without_a_composer_file_fails(): void
    {
        $this->checkout(['src/A' => self::VALID_COMPOSER_FILE]);
        file_put_contents(
            "{$this->root}/.gitsplit.yml",
            "\n  - prefix: \"src/Gone\"\n    target: \"https://github.com/opentelemetry-php/gone.git\"\n",
            FILE_APPEND
        );

        $this->assertSame(Command::FAILURE, $this->execute()->getStatusCode());
    }

    public function test_split_without_a_target_fails_naming_it(): void
    {
        $this->checkout(['src/A' => self::VALID_COMPOSER_FILE]);
        file_put_contents("{$this->root}/.gitsplit.yml", "splits:\n  - prefix: \"src/A\"\n");

        $commandTester = $this->execute();

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('split 1 has no target', $commandTester->getDisplay());
    }

    public function test_directory_that_is_not_a_checkout_fails(): void
    {
        unlink("{$this->root}/composer.json");

        $commandTester = $this->execute();

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('is not a monorepo checkout', $commandTester->getDisplay());
    }

    /**
     * @param array<string, string> $splits fixture to copy in as each split's composer.json, by prefix
     */
    private function checkout(array $splits): void
    {
        $yaml = "splits:\n";
        foreach ($splits as $prefix => $composerFile) {
            $yaml .= "  - prefix: \"{$prefix}\"\n    target: \"https://\${GH_TOKEN}@github.com/opentelemetry-php/x.git\"\n";
            mkdir("{$this->root}/{$prefix}", 0o777, true);
            copy($composerFile, "{$this->root}/{$prefix}/composer.json");
        }
        file_put_contents("{$this->root}/.gitsplit.yml", $yaml);
    }

    private function execute(): CommandTester
    {
        $commandTester = new CommandTester(new ValidatePackagesCommand($this->root));
        $commandTester->execute([]);

        return $commandTester;
    }
}
