<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Console\Command\Packages;

use OpenTelemetry\DevTools\Console\Command\Packages\SpiCheckCommand;
use OpenTelemetry\DevTools\Package\Spi\OutdatedDependencyRunner;
use OpenTelemetry\DevTools\Package\Spi\PackageDependencyReader;
use OpenTelemetry\DevTools\Package\Spi\ProviderDependencies;
use OpenTelemetry\DevTools\Package\Spi\ProviderDependency;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OpenTelemetry\DevTools\Console\Command\Packages\SpiCheckCommand
 */
class SpiCheckCommandTest extends TestCase
{
    use UsesVfsTrait;

    private const OTLP = 'open-telemetry/exporter-otlp';
    private const PROVIDER = 'OpenTelemetry\Config\SDK\ComponentProvider\Trace\SpanExporterOtlpHttp';

    /** @var PackageDependencyReader&MockObject */
    private PackageDependencyReader $reader;
    /** @var OutdatedDependencyRunner&MockObject */
    private OutdatedDependencyRunner $runner;
    private CommandTester $tester;

    #[\Override]
    public function setUp(): void
    {
        $this->setUpVcs();
        $this->reader = $this->createMock(PackageDependencyReader::class);
        $this->runner = $this->createMock(OutdatedDependencyRunner::class);
        $this->tester = new CommandTester(new SpiCheckCommand($this->reader, $this->runner));
    }

    public function test_a_dependency_the_in_tree_alias_satisfies_passes(): void
    {
        $this->checkout('1.5.x-dev', '1.5.x-dev');
        $this->dependencies(new ProviderDependency(self::PROVIDER, self::OTLP, '^1.5'));

        $this->assertSame(Command::SUCCESS, $this->run_check());
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('SpanExporterOtlpHttp', $display);
        $this->assertStringContainsString('1.5.x-dev', $display);
        $this->assertStringContainsString('ok', $display);
    }

    public function test_a_dependency_the_alias_does_not_satisfy_fails_and_names_the_provider(): void
    {
        $this->checkout('1.4.x-dev', '1.5.x-dev');
        $this->dependencies(new ProviderDependency(self::PROVIDER, self::OTLP, '^1.5'));

        $this->assertSame(Command::FAILURE, $this->run_check());
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('SpanExporterOtlpHttp', $display);
        $this->assertStringContainsString('dev-main alias 1.4.x-dev does not satisfy ^1.5', $display);
    }

    public function test_a_root_replace_that_does_not_satisfy_a_constraint_fails(): void
    {
        $this->checkout('1.5.x-dev', '1.0.x-dev');
        $this->dependencies(new ProviderDependency(self::PROVIDER, self::OTLP, '^1.5'));

        $this->assertSame(Command::FAILURE, $this->run_check());
        $this->assertStringContainsString('root replace 1.0.x-dev does not satisfy ^1.5', $this->tester->getDisplay());
    }

    public function test_other_packages_go_to_tbachert_spi_with_the_split_packages_ignored(): void
    {
        $this->checkout('1.5.x-dev', '1.5.x-dev');
        $this->dependencies(
            new ProviderDependency(self::PROVIDER, self::OTLP, '^1.5'),
            new ProviderDependency('Dotenv', 'symfony/dotenv', '^7.0'),
        );
        $this->runner->expects($this->once())
            ->method('run')
            ->with($this->root->url(), [self::OTLP], $this->anything())
            ->willReturn(0);

        $this->assertSame(Command::SUCCESS, $this->run_check());
        $this->assertStringContainsString('symfony/dotenv: ok', $this->tester->getDisplay());
    }

    public function test_a_failure_from_tbachert_spi_fails_the_check(): void
    {
        $this->checkout('1.5.x-dev', '1.5.x-dev');
        $this->dependencies(new ProviderDependency('Dotenv', 'symfony/dotenv', '^7.0'));
        $this->runner->method('run')->willReturn(1);

        $this->assertSame(Command::FAILURE, $this->run_check());
        $this->assertStringNotContainsString(': ok', $this->tester->getDisplay());
    }

    public function test_tbachert_spi_is_not_run_without_other_packages(): void
    {
        $this->checkout('1.5.x-dev', '1.5.x-dev');
        $this->dependencies(new ProviderDependency(self::PROVIDER, self::OTLP, '^1.5'));
        $this->runner->expects($this->never())->method('run');

        $this->assertSame(Command::SUCCESS, $this->run_check());
    }

    public function test_a_provider_that_does_not_load_is_warned_about(): void
    {
        $this->checkout('1.5.x-dev', '1.5.x-dev');
        $this->reader->method('read')->willReturn(new ProviderDependencies([], ['Gone\Provider']));

        $this->assertSame(Command::SUCCESS, $this->run_check());
        $this->assertStringContainsString('Gone\Provider', $this->tester->getDisplay());
    }

    public function test_a_checkout_without_vendor_fails(): void
    {
        $this->checkout('1.5.x-dev', '1.5.x-dev');
        $this->reader->method('read')->willThrowException(new RuntimeException('run `composer install` first'));

        $this->assertSame(Command::FAILURE, $this->run_check());
        $this->assertStringContainsString('composer install', $this->tester->getDisplay());
    }

    public function test_a_path_that_is_not_a_monorepo_fails(): void
    {
        $this->assertSame(Command::FAILURE, $this->run_check());
        $this->assertStringContainsString('.gitsplit.yml', $this->tester->getDisplay());
    }

    public function test_a_path_that_does_not_exist_fails_naming_it(): void
    {
        $this->assertSame(Command::FAILURE, $this->tester->execute(['--path' => '/nowhere/monorepo']));
        $this->assertStringContainsString('--path /nowhere/monorepo is not a directory', $this->tester->getDisplay());
    }

    private function checkout(string $alias, string $replace): void
    {
        $url = $this->root->url();
        mkdir("{$url}/src/Contrib/Otlp", 0o777, true);
        file_put_contents(
            "{$url}/src/Contrib/Otlp/composer.json",
            json_encode(['name' => self::OTLP, 'extra' => ['branch-alias' => ['dev-main' => $alias]]])
        );
        file_put_contents(
            "{$url}/.gitsplit.yml",
            "splits:\n  - prefix: \"src/Contrib/Otlp\"\n    target: \"https://\${GH_TOKEN}@github.com/opentelemetry-php/exporter-otlp.git\"\n"
        );
        file_put_contents(
            "{$url}/composer.json",
            json_encode(['name' => 'open-telemetry/opentelemetry', 'replace' => [self::OTLP => $replace]])
        );
    }

    private function dependencies(ProviderDependency ...$dependencies): void
    {
        $this->reader->method('read')->willReturn(new ProviderDependencies(array_values($dependencies)));
    }

    private function run_check(): int
    {
        return $this->tester->execute(['--path' => $this->root->url()]);
    }
}
