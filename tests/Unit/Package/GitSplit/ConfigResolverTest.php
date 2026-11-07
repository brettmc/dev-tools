<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\GitSplit;

use InvalidArgumentException;
use function is_array;
use function iterator_to_array;
use OpenTelemetry\DevTools\Package\GitSplit\ConfigResolver;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsTrait;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamFile;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\GitSplit\ConfigResolver
 */
class ConfigResolverTest extends TestCase
{
    use UsesVfsTrait;

    private const ROOT_DIR = 'root';
    private const GITSPLIT_FILE = '.gitsplit.yml';
    private const GITSPLIT_CONFIG_PATH = self::ROOT_DIR . '/' . self::GITSPLIT_FILE;
    private const PACKAGE_PATHS = [
        'A' => 'src/A',
        'B' => 'src/B',
        'C' => 'src/C',
    ];

    #[\Override]
    public function setUp(): void
    {
        $this->setUpVcs();
    }

    private static function iterableToArray(iterable $iterable): array
    {
        /** @noinspection PhpParamsInspection */
        return is_array($iterable) ? $iterable : iterator_to_array($iterable);
    }

    public function test_resolve(): void
    {
        $resolver = new ConfigResolver(
            $this->getConfigFile(
                $this->getConfigContent()
            )->url()
        );

        $this->assertSame(
            $this->getExpectedResolverResult(),
            self::iterableToArray($resolver->resolve()),
        );
    }

    public function test_resolve_paths_with_trailing_slash(): void
    {
        $resolver = new ConfigResolver(
            $this->getConfigFile(
                $this->getConfigContentWithTrailingSlash()
            )->url()
        );

        $this->assertSame(
            $this->getExpectedResolverResult(),
            self::iterableToArray($resolver->resolve()),
        );
    }

    public function test_resolve_paths_with_no_split_config(): void
    {
        $resolver = new ConfigResolver(
            $this->getConfigFile(
                $this->getConfigContentWithNoSplitConfig()
            )->url()
        );

        $this->assertEmpty(
            self::iterableToArray($resolver->resolve()),
        );
    }

    public function test_resolve_throws_exception_on_invalid_yaml(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ConfigResolver(
            $this->getConfigFile(
                $this->getInvalidYamlConfig()
            )->url()
        ))->resolve();
    }

    public function test_get_default_config_path(): void
    {
        $basePath = getcwd() !== false ?  getcwd() : '.';

        $this->assertSame(
            "$basePath/" . self::GITSPLIT_FILE,
            (new ConfigResolver())->getDefaultConfigPath()
        );
    }

    private function getExpectedResolverResult(): array
    {
        $result = [];

        foreach (self::PACKAGE_PATHS as $path) {
            $result[$path] = sprintf(
                '%s/composer.json',
                $path
            );
        }

        return $result;
    }

    private function getConfigFile(string $content): vfsStreamFile
    {
        return vfsStream::newFile(self::GITSPLIT_CONFIG_PATH)
            ->withContent($content)
            ->at($this->root);
    }

    private function getConfigContent(): string
    {
        $a = self::PACKAGE_PATHS['A'];
        $b = self::PACKAGE_PATHS['B'];
        $c = self::PACKAGE_PATHS['C'];

        return <<<EOF
cache_url: "/cache/gitsplit"

splits:
  - prefix: "$a"
    target: "https://example.com/example-php/A.git"
  - prefix: "$b"
    target: "https://example.com/example-php/B.git"
  - prefix: "$c"
    target: "https://example.com/example-php/C.git"

origins:
  - ^main$
  - ^v\d+\.\d+\.\d+$
  - ^\d+\.\d+\.\d+$
EOF;
    }

    private function getConfigContentWithTrailingSlash(): string
    {
        $a = self::PACKAGE_PATHS['A'];
        $b = self::PACKAGE_PATHS['B'];
        $c = self::PACKAGE_PATHS['C'];

        return <<<EOF
cache_url: "/cache/gitsplit"

splits:
  - prefix: "$a/"
    target: "https://example.com/example-php/A.git"
  - prefix: "$b/"
    target: "https://example.com/example-php/B.git"
  - prefix: "$c/"
    target: "https://example.com/example-php/C.git"

origins:
  - ^main$
  - ^v\d+\.\d+\.\d+$
  - ^\d+\.\d+\.\d+$
EOF;
    }

    private function getConfigContentWithNoSplitConfig(): string
    {
        return <<<EOF
cache_url: "/cache/gitsplit"

splits:

origins:
  - ^main$
  - ^v\d+\.\d+\.\d+$
  - ^\d+\.\d+\.\d+$
EOF;
    }

    private function getInvalidYamlConfig(): string
    {
        return <<<EOF
foo: bar
foo: baz
EOF;
    }
}
