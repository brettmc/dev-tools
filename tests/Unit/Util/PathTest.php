<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Util;

use OpenTelemetry\DevTools\Util\Path;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Util\Path
 */
class PathTest extends TestCase
{
    public function test_relative_path_is_made_absolute_against_the_working_directory(): void
    {
        $this->assertSame(getcwd() . '/a/b', Path::absolute('a/./b/'));
    }

    public function test_working_directory_is_absolute(): void
    {
        $this->assertSame(getcwd(), Path::absolute('.'));
    }

    public function test_absolute_path_is_normalized(): void
    {
        $this->assertSame('/a/c', Path::absolute('/a/b/../c/'));
    }

    public function test_stream_wrapper_url_is_already_absolute(): void
    {
        $this->assertSame('vfs://root/b', Path::absolute('vfs://root/a/../b'));
    }

    public function test_dot_dot_past_the_root_stays_at_the_root(): void
    {
        $this->assertSame('/b', Path::absolute('/a/../../b'));
    }

    public function test_directory_defaults_to_the_working_directory(): void
    {
        $this->assertSame(getcwd(), Path::directory(null));
    }

    public function test_relative_directory_is_made_absolute(): void
    {
        $cwd = (string) getcwd();
        chdir(__DIR__);

        try {
            $this->assertSame(dirname(__DIR__), Path::directory('..'));
        } finally {
            chdir($cwd);
        }
    }

    public function test_directory_that_does_not_exist_is_null(): void
    {
        $this->assertNull(Path::directory(__DIR__ . '/missing'));
    }

    public function test_file_is_not_a_directory(): void
    {
        $this->assertNull(Path::directory(__FILE__));
    }

    public function test_contained_file_that_does_not_exist_yet_is_found_under_a_relative_root(): void
    {
        $cwd = (string) getcwd();
        chdir(dirname(__DIR__));

        try {
            $this->assertSame(__DIR__ . '/src/API/CHANGELOG.md', Path::contained('Util', 'src/API', 'CHANGELOG.md'));
            $this->assertSame(__DIR__ . '/src/API/CHANGELOG.md', Path::contained('./Util/', 'src/API', 'CHANGELOG.md'));
        } finally {
            chdir($cwd);
        }
    }

    public function test_contained_file_that_does_not_exist_yet_is_found_under_a_symlinked_root(): void
    {
        $base = sys_get_temp_dir() . '/path-' . bin2hex(random_bytes(4));
        mkdir("{$base}/real/src", 0o777, true);
        symlink("{$base}/real", "{$base}/link");

        try {
            $this->assertSame(
                realpath("{$base}/real") . '/src/CHANGELOG.md',
                Path::contained("{$base}/link", 'src', 'CHANGELOG.md')
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($base));
        }
    }

    public function test_prefix_that_climbs_out_of_the_root_is_not_contained(): void
    {
        $this->assertNull(Path::contained(__DIR__, '../Util-other', 'composer.json'));
        $this->assertNull(Path::contained('vfs://root', '../outside', 'composer.json'));
    }

    public function test_root_itself_is_not_a_file_in_it(): void
    {
        $this->assertNull(Path::contained(__DIR__, '..', 'Util'));
    }
}
