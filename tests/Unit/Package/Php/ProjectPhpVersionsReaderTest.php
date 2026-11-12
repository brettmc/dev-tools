<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Php;

use OpenTelemetry\DevTools\Package\Php\ProjectPhpVersionsReader;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsConstants;
use OpenTelemetry\DevTools\Tests\Unit\Behavior\UsesVfsTrait;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OpenTelemetry\DevTools\Package\Php\ProjectPhpVersionsReader
 * @covers \OpenTelemetry\DevTools\Package\Php\ProjectPhpVersions
 */
class ProjectPhpVersionsReaderTest extends TestCase
{
    use UsesVfsTrait;

    private string $path;

    #[\Override]
    public function setUp(): void
    {
        $this->setUpVcs();
        $this->path = vfsStream::url(UsesVfsConstants::ROOT_DIR);
    }

    public function test_it_reads_the_minimum_and_the_tested_versions(): void
    {
        $this->project('^8.2', <<<'YAML'
            jobs:
              php:
                strategy:
                  matrix:
                    php-version: ['8.5', '8.2', '8.3']
                    include:
                      - php-version: '8.6'
            YAML);

        $versions = (new ProjectPhpVersionsReader())->read($this->path);

        $this->assertSame('8.2', $versions->minimum);
        $this->assertSame(['8.2', '8.3', '8.5'], $versions->ci);
    }

    public function test_it_reads_a_list_over_several_lines_and_jobs(): void
    {
        $this->project('>=8.1', <<<'YAML'
            jobs:
              unit:
                strategy:
                  matrix:
                    php-version:
                      - "8.1"
                      - "8.10"
              lint:
                strategy:
                  matrix:
                    php-version: ["8.10", "8.2"]
              other:
                runs-on: ubuntu-latest
            YAML);

        $versions = (new ProjectPhpVersionsReader())->read($this->path);

        $this->assertSame('8.1', $versions->minimum);
        $this->assertSame(['8.1', '8.2', '8.10'], $versions->ci);
    }

    public function test_a_patch_version_counts_as_its_branch_and_a_pre_release_does_not(): void
    {
        $this->project('^8.2', "jobs:\n  a:\n    strategy:\n      matrix:\n        php-version: ['8.2.1', '8.3', '8.4.0RC1', '8.5.0-dev', '8.5.x', 'nightly', '\${{ inputs.php }}']\n");

        $this->assertSame(['8.2', '8.3'], (new ProjectPhpVersionsReader())->read($this->path)->ci);
    }

    public function test_it_reads_the_workflow_given_relative_to_the_path(): void
    {
        $this->project('^8.2', "jobs:\n  a:\n    strategy:\n      matrix:\n        php-version: ['8.2']\n", 'ci.yml');

        $this->assertSame(['8.2'], (new ProjectPhpVersionsReader())->read($this->path, 'ci.yml')->ci);
    }

    /**
     * @dataProvider unquotedVersions
     */
    public function test_it_rejects_versions_that_yaml_reads_as_numbers(string $list): void
    {
        $this->project('^8.2', "jobs:\n  a:\n    strategy:\n      matrix:\n        php-version: {$list}\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('quote the PHP version');

        (new ProjectPhpVersionsReader())->read($this->path);
    }

    public static function unquotedVersions(): iterable
    {
        yield 'a number among strings' => ["['8.2', 8.10]"];
        yield 'a whole number' => ['[8]'];
        yield 'a single number' => ['8.3'];
    }

    public function test_it_fails_without_a_php_requirement(): void
    {
        file_put_contents("{$this->path}/composer.json", '{"require": {}}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no "php" requirement');

        (new ProjectPhpVersionsReader())->read($this->path);
    }

    public function test_it_fails_without_a_workflow(): void
    {
        file_put_contents("{$this->path}/composer.json", '{"require": {"php": "^8.2"}}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        (new ProjectPhpVersionsReader())->read($this->path);
    }

    public function test_it_fails_when_the_workflow_lists_no_php_versions(): void
    {
        $this->project('^8.2', "jobs:\n  a:\n    runs-on: ubuntu-latest\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no strategy.matrix.php-version');

        (new ProjectPhpVersionsReader())->read($this->path);
    }

    public function test_it_fails_on_a_workflow_that_is_not_yaml(): void
    {
        $this->project('^8.2', "jobs: [unclosed\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot parse');

        (new ProjectPhpVersionsReader())->read($this->path);
    }

    private function project(string $php, string $workflow, string $name = ProjectPhpVersionsReader::DEFAULT_WORKFLOW): void
    {
        file_put_contents("{$this->path}/composer.json", json_encode(['require' => ['php' => $php]]));
        $file = "{$this->path}/{$name}";
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, $workflow);
    }
}
