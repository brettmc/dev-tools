<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Command\Php;

use Nyholm\Psr7\Response;
use OpenTelemetry\DevTools\Console\Command\Php\PhpSupportCommand;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\FakeHttpClient;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesPhpStatesTrait;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsConstants;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsTrait;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Php\PhpSupportCommand
 * @covers \OpenTelemetry\DevTools\Console\Command\Php\AbstractPhpSupportCommand
 */
class PhpSupportCommandTest extends TestCase
{
    use UsesPhpStatesTrait;
    use UsesVfsTrait;

    private const BLOCKS = "<!-- php-support-badges:start -->\n%s\n<!-- php-support-badges:end -->\n\n<!-- php-support-table:start -->\n%s\n<!-- php-support-table:end -->\n";

    private string $path;

    #[\Override]
    public function setUp(): void
    {
        $this->setUpVcs();
        $this->path = vfsStream::url(UsesVfsConstants::ROOT_DIR);
        file_put_contents("{$this->path}/composer.json", '{"require": {"php": "^8.2"}}');
        mkdir("{$this->path}/.github/workflows", 0777, true);
        file_put_contents("{$this->path}/.github/workflows/php.yml", "jobs:\n  php:\n    strategy:\n      matrix:\n        php-version: ['8.2', '8.3', '8.4', '8.5']\n");
    }

    public function test_it_shows_the_supported_versions(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--path' => $this->path, '--date' => '2026-11-05']));
        $display = $tester->getDisplay();
        $this->assertMatchesRegularExpression('/\| 8\.2\s+\| 2022-12-08\s+\| 2026-12-31\s+\| 2027-12-31\s+\| yes\s+\|\s+\|/', $display);
        $this->assertStringContainsString('8.5', $display);
        $this->assertStringNotContainsString('8.6', $display);
        $this->assertStringNotContainsString('8.1', $display);
    }

    public function test_it_shows_what_is_to_be_done(): void
    {
        $tester = $this->tester();

        $tester->execute(['--path' => $this->path, '--date' => '2027-11-15']);

        $this->assertMatchesRegularExpression('/\| 8\.2 .*\| yes\s+\| drop\s+\|/', $tester->getDisplay());
        $this->assertMatchesRegularExpression('/\| 8\.6 .*\| no\s+\| add to CI\s+\|/', $tester->getDisplay());
    }

    public function test_check_passes_when_the_docs_are_up_to_date(): void
    {
        $this->readme(sprintf(self::BLOCKS, $this->badges(), $this->table()));
        $tester = $this->tester();

        $status = $tester->execute(['--path' => $this->path, '--date' => '2026-11-05', '--check' => true]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringNotContainsString('out of date', $tester->getDisplay());
    }

    public function test_check_fails_when_the_docs_are_out_of_date(): void
    {
        $before = sprintf(self::BLOCKS, 'old', 'old');
        $this->readme($before);
        $tester = $this->tester();

        $status = $tester->execute(['--path' => $this->path, '--date' => '2026-11-05', '--check' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('README.md is out of date', $tester->getDisplay());
        $this->assertStringContainsString('otel php:support --write', $tester->getDisplay());
        $this->assertSame($before, file_get_contents("{$this->path}/README.md"), 'check does not write');
    }

    public function test_write_updates_the_files_between_the_markers(): void
    {
        $this->readme("# Project\n\n" . sprintf(self::BLOCKS, 'old', 'old') . "\nThe end\n");
        $tester = $this->tester();

        $status = $tester->execute(['--path' => $this->path, '--date' => '2026-11-05', '--write' => true]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('Updated README.md', $tester->getDisplay());
        $this->assertSame(
            "# Project\n\n" . sprintf(self::BLOCKS, $this->badges(), $this->table()) . "\nThe end\n",
            file_get_contents("{$this->path}/README.md"),
        );
    }

    public function test_write_leaves_an_up_to_date_file_alone(): void
    {
        $this->readme(sprintf(self::BLOCKS, $this->badges(), $this->table()));
        $tester = $this->tester();

        $status = $tester->execute(['--path' => $this->path, '--date' => '2026-11-05', '--write' => true]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringNotContainsString('Updated', $tester->getDisplay());
    }

    public function test_it_updates_several_files_and_links_the_policy(): void
    {
        $this->readme("<!-- php-support-badges:start -->\n<!-- php-support-badges:end -->\n");
        file_put_contents("{$this->path}/docs.md", "<!-- php-support-table:start -->\n<!-- php-support-table:end -->\n");
        $tester = $this->tester();

        $tester->execute(['--path' => $this->path, '--date' => '2026-11-05', '--write' => true, '--file' => ['README.md', 'docs.md'], '--policy-link' => 'POLICY.md']);

        $this->assertStringContainsString('(POLICY.md)', (string) file_get_contents("{$this->path}/README.md"));
        $this->assertStringContainsString('| 8.3 | 2027-12-31 | 2028-12-31 |', (string) file_get_contents("{$this->path}/docs.md"));
    }

    public function test_the_policy_link_climbs_out_of_a_directory(): void
    {
        mkdir("{$this->path}/docs");
        file_put_contents("{$this->path}/docs/versioning.md", "<!-- php-support-badges:start -->\n<!-- php-support-badges:end -->\n");
        $tester = $this->tester();

        $tester->execute(['--path' => $this->path, '--date' => '2026-11-05', '--write' => true, '--file' => ['docs/versioning.md']]);

        $this->assertStringContainsString('](../docs/versioning.md#php-version-support)', (string) file_get_contents("{$this->path}/docs/versioning.md"));
    }

    public function test_write_changes_no_file_when_one_of_them_cannot_be_updated(): void
    {
        $readme = "<!-- php-support-badges:start -->\nold\n<!-- php-support-badges:end -->\n";
        $this->readme($readme);
        file_put_contents("{$this->path}/docs.md", "<!-- php-support-table:start -->\nno end marker\n");
        $tester = $this->tester();

        $status = $tester->execute(['--path' => $this->path, '--date' => '2026-11-05', '--write' => true, '--file' => ['README.md', 'docs.md']]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('docs.md: The php-support-table block needs its markers', $tester->getDisplay());
        $this->assertSame($readme, file_get_contents("{$this->path}/README.md"));
    }

    public function test_it_fails_on_a_file_without_markers(): void
    {
        $this->readme('No markers');
        $tester = $this->tester();

        $status = $tester->execute(['--path' => $this->path, '--date' => '2026-11-05', '--write' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('README.md: No PHP support block found', $tester->getDisplay());
    }

    public function test_it_fails_on_a_file_that_does_not_exist(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['--path' => $this->path, '--date' => '2026-11-05', '--write' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('README.md not found', $tester->getDisplay());
    }

    public function test_it_fails_when_a_file_cannot_be_written(): void
    {
        $this->readme(sprintf(self::BLOCKS, 'old', 'old'));
        chmod("{$this->path}/README.md", 0444);
        $tester = $this->tester();

        $status = $tester->execute(['--path' => $this->path, '--date' => '2026-11-05', '--write' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Could not write', $tester->getDisplay());
        $this->assertStringNotContainsString('Updated', $tester->getDisplay());
    }

    public function test_it_takes_an_absolute_file_as_it_is(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'otel-php-support');
        file_put_contents($file, "<!-- php-support-table:start -->\n<!-- php-support-table:end -->\n");

        try {
            $status = $this->tester()->execute(['--path' => $this->path, '--date' => '2026-11-05', '--write' => true, '--file' => [$file]]);

            $this->assertSame(Command::SUCCESS, $status);
            $this->assertStringContainsString('| 8.3 | 2027-12-31 | 2028-12-31 |', (string) file_get_contents($file));
        } finally {
            unlink($file);
        }
    }

    public function test_it_fails_cleanly_when_the_working_directory_is_gone(): void
    {
        $gone = sys_get_temp_dir() . '/otel-php-support-gone-' . uniqid();
        mkdir($gone);
        $before = (string) getcwd();
        chdir($gone);
        rmdir($gone);

        $tester = $this->tester();

        try {
            $status = $tester->execute([]);
        } finally {
            chdir($before);
        }

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('The working directory cannot be read', $tester->getDisplay());
    }

    public function test_it_fails_when_the_path_is_not_a_directory(): void
    {
        $client = new FakeHttpClient([]);
        $tester = new CommandTester(new PhpSupportCommand($client));

        $status = $tester->execute(['--path' => "{$this->path}/missing"]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString("--path {$this->path}/missing is not a directory", $tester->getDisplay());
        $this->assertCount(0, $client->requests, 'php.net was not asked');
    }

    public function test_write_and_check_cannot_be_combined(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['--path' => $this->path, '--write' => true, '--check' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('--write and --check cannot be used together', $tester->getDisplay());
    }

    /**
     * @dataProvider invalidOptions
     */
    public function test_it_rejects_an_invalid_option(array $options, string $message): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['--path' => $this->path] + $options);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString($message, $tester->getDisplay());
    }

    public static function invalidOptions(): iterable
    {
        yield 'date' => [['--date' => '31-12-2026'], '--date must be a date like 2026-12-31'];
        yield 'impossible date' => [['--date' => '2026-02-30'], '--date must be a date'];
        yield 'months' => [['--months' => '0'], '--months must be a whole number from 1 to 1200'];
        yield 'too many months' => [['--months' => '99999999999999999999'], '--months must be a whole number from 1 to 1200'];
        yield 'notice' => [['--notice-days' => 'many'], '--notice-days must be a whole number from 1 to 36500'];
        yield 'too many days' => [['--notice-days' => '36501'], '--notice-days must be a whole number from 1 to 36500'];
    }

    public function test_it_reports_php_net_being_down(): void
    {
        $tester = new CommandTester(new PhpSupportCommand(new FakeHttpClient([new Response(500)])));

        $status = $tester->execute(['--path' => $this->path]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('HTTP 500', $tester->getDisplay());
    }

    public function test_it_reads_the_workflow_given_by_the_option(): void
    {
        file_put_contents("{$this->path}/ci.yml", "jobs:\n  a:\n    strategy:\n      matrix:\n        php-version: ['8.2']\n");
        $tester = $this->tester();

        $tester->execute(['--path' => $this->path, '--date' => '2026-11-05', '--workflow' => 'ci.yml']);

        $this->assertMatchesRegularExpression('/\| 8\.3 .*\| no\s+\| add to CI\s+\|/', $tester->getDisplay());
    }

    public function test_it_is_named_after_its_purpose(): void
    {
        $this->assertSame('php:support', (new PhpSupportCommand())->getName());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new PhpSupportCommand(new FakeHttpClient([new Response(200, [], $this->phpStatesJson())])));
    }

    private function readme(string $contents): void
    {
        file_put_contents("{$this->path}/README.md", $contents);
    }

    private function badges(): string
    {
        $badge = static fn (string $v): string => "[![PHP {$v}](https://img.shields.io/badge/PHP-{$v}-777BB4?logo=php&logoColor=white)](docs/versioning.md#php-version-support)";

        return implode("\n", array_map($badge, ['8.2', '8.3', '8.4', '8.5']));
    }

    private function table(): string
    {
        return "| PHP | Security support ends | Supported until |\n|-----|-----------------------|-----------------|\n"
            . "| 8.2 | 2026-12-31 | 2027-12-31 |\n| 8.3 | 2027-12-31 | 2028-12-31 |\n| 8.4 | 2028-12-31 | 2029-12-31 |\n| 8.5 | 2029-12-31 | 2030-12-31 |";
    }
}
