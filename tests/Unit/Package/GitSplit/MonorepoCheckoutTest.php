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
            "splits:\n  - prefix: \"src/API\"\n    target: \"https://github.com/opentelemetry-php/api.git\"\n  - prefix: \"src/Gone\"\n    target: \"https://github.com/opentelemetry-php/gone.git\"\n"
        );

        $this->assertSame(['open-telemetry/api' => '1.11.x-dev'], MonorepoCheckout::read($this->root->url())->aliases);
    }

    public function test_prefixes_are_listed_in_gitsplit_order_without_slashes(): void
    {
        $this->checkout(['src/SDK' => ['open-telemetry/sdk', null]], []);
        $this->gitsplit("  - prefix: \"src/SDK/\"\n    target: \"https://\${GH_TOKEN}@github.com/opentelemetry-php/sdk.git\"\n  - prefix: \"src/API\"\n    target: \"https://\${GH_TOKEN}@github.com/opentelemetry-php/api.git\"\n");

        $this->assertSame(['src/SDK', 'src/API'], MonorepoCheckout::read($this->root->url())->prefixes);
    }

    public function test_splits_map_each_prefix_to_its_target_repository(): void
    {
        $this->checkout(['src/API' => ['open-telemetry/api', null]], []);
        $this->gitsplit("  - prefix: \"src/API/\"\n    target: \"https://\${GH_TOKEN}@github.com/opentelemetry-php/api.git\"\n  - prefix: \"src/Contrib/Otlp\"\n    target: \"https://example.com/example-php/exporter-otlp\"\n");

        $checkout = MonorepoCheckout::read($this->root->url());

        $this->assertSame(
            ['src/API' => 'opentelemetry-php/api', 'src/Contrib/Otlp' => 'example-php/exporter-otlp'],
            $checkout->splits
        );
        $this->assertSame('open-telemetry/opentelemetry', $checkout->name);
    }

    public function test_an_empty_splits_list_has_no_splits(): void
    {
        $this->checkout([], []);
        $this->gitsplit('');

        $this->assertSame([], MonorepoCheckout::read($this->root->url())->splits);
    }

    /**
     * @dataProvider malformedSplits
     */
    public function test_a_malformed_split_is_refused_naming_it(string $splits, string $message): void
    {
        $this->checkout([], []);
        file_put_contents($this->root->url() . '/.gitsplit.yml', $splits);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);

        MonorepoCheckout::read($this->root->url());
    }

    public static function malformedSplits(): array
    {
        return [
            'no prefix' => ["splits:\n  - target: \"https://github.com/opentelemetry-php/api.git\"\n", '.gitsplit.yml split 1 has no prefix'],
            'no target' => ["splits:\n  - prefix: \"src/API\"\n  - prefix: \"src/SDK\"\n", '.gitsplit.yml split 1 has no target'],
            'target with no repository' => ["splits:\n  - prefix: \"src/API\"\n    target: \"api\"\n", '.gitsplit.yml split 1 target "api" does not name a repository'],
            'split not a map' => ["splits:\n  - src/API\n", '.gitsplit.yml split 1 has no prefix'],
            'splits not a list' => ["splits: src/API\n", '.gitsplit.yml splits is not a list'],
            'not yaml' => ["splits: [\n", '.gitsplit.yml does not parse'],
        ];
    }

    public function test_a_path_that_is_not_a_monorepo_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('.gitsplit.yml');

        MonorepoCheckout::read($this->root->url());
    }

    private function gitsplit(string $splits): void
    {
        file_put_contents($this->root->url() . '/.gitsplit.yml', "splits:\n{$splits}");
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
