<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\GitSplit;

use OpenTelemetry\DevTools\Package\GitSplit\MonorepoCheckout;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsTrait;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\GitSplit\MonorepoCheckout
 */
class MonorepoCheckoutTest extends TestCase
{
    use UsesVfsTrait;

    #[\Override]
    public function setUp(): void
    {
        $this->setUpVcs();
    }

    public function test_aliases_are_read_from_each_split(): void
    {
        $this->checkout(
            ['src/API' => ['open-telemetry/api', '1.11.x-dev'], 'src/Context' => ['open-telemetry/context', null]],
            ['open-telemetry/api' => '1.0.x-dev']
        );

        $checkout = MonorepoCheckout::read($this->root->url());

        $this->assertSame(
            ['open-telemetry/api' => '1.11.x-dev', 'open-telemetry/context' => null],
            $checkout->aliases
        );
        $this->assertSame(['open-telemetry/api' => '1.0.x-dev'], $checkout->replace);
    }

    public function test_a_split_without_a_composer_json_is_left_out(): void
    {
        $this->checkout(['src/API' => ['open-telemetry/api', '1.11.x-dev']], []);
        file_put_contents(
            $this->root->url() . '/.gitsplit.yml',
            "splits:\n  - prefix: \"src/API\"\n    target: \"x\"\n  - prefix: \"src/Gone\"\n    target: \"y\"\n"
        );

        $this->assertSame(['open-telemetry/api' => '1.11.x-dev'], MonorepoCheckout::read($this->root->url())->aliases);
    }

    public function test_prefixes_are_listed_in_gitsplit_order_without_slashes(): void
    {
        $this->checkout(['src/SDK' => ['open-telemetry/sdk', null]], []);
        file_put_contents(
            $this->root->url() . '/.gitsplit.yml',
            "splits:\n  - prefix: \"src/SDK/\"\n    target: \"x\"\n  - prefix: \"src/API\"\n    target: \"y\"\n  - target: \"z\"\n"
        );

        $this->assertSame(['src/SDK', 'src/API'], MonorepoCheckout::read($this->root->url())->prefixes);
    }

    public function test_a_path_that_is_not_a_monorepo_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('.gitsplit.yml');

        MonorepoCheckout::read($this->root->url());
    }

    /**
     * @param array<string, array{0: string, 1: ?string}> $splits name and alias by prefix
     * @param array<string, string> $replace
     */
    private function checkout(array $splits, array $replace): void
    {
        $yaml = "splits:\n";
        foreach ($splits as $prefix => [$name, $alias]) {
            $yaml .= "  - prefix: \"{$prefix}\"\n    target: \"https://\${GH_TOKEN}@github.com/opentelemetry-php/x.git\"\n";
            mkdir($this->root->url() . "/{$prefix}", 0o777, true);
            $composer = ['name' => $name];
            if ($alias !== null) {
                $composer['extra'] = ['branch-alias' => ['dev-main' => $alias]];
            }
            file_put_contents($this->root->url() . "/{$prefix}/composer.json", json_encode($composer));
        }
        file_put_contents($this->root->url() . '/.gitsplit.yml', $yaml);
        file_put_contents(
            $this->root->url() . '/composer.json',
            json_encode(['name' => 'open-telemetry/opentelemetry', 'replace' => (object) $replace])
        );
    }
}
