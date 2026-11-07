<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Util;

use OpenTelemetry\DevTools\Util\RecursiveDirectoryRemover;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;

class RecursiveDirectoryRemoverTest extends TestCase
{
    private const ROOT_DIR = 'root';
    private const TEST_DIR = 'test';
    private const FILES_DIR = self::TEST_DIR . DIRECTORY_SEPARATOR . 'files';
    private const DIRS_DIR = self::TEST_DIR . DIRECTORY_SEPARATOR . '_dirs';
    private const PATHS = [
        '.foo/foo',
        '.foo/.foo',
        '.foo/.foo/.foo',
        'foo/.foo',
        '.foo/bar/foo',
        '.foo/bar/.foo',
        'foo/bar/.foo',
        '_baz/.foo/foo',
        '_baz/.foo/.foo',
        '_baz/foo/.foo',
        '_baz/.foo/bar/foo',
        '_baz/.foo/bar/.foo',
        '_baz/foo/bar/.foo',
    ];

    private RecursiveDirectoryRemover $remover;
    private vfsStreamDirectory $root;

    #[\Override]
    public function setUp(): void
    {
        $this->remover = RecursiveDirectoryRemover::create();
        $this->root = vfsStream::setup(self::ROOT_DIR);
    }

    public function test_delete_files(): void
    {
        $testDirectory = $this->setUpFiles()->url();

        $this->assertTrue(
            $this->remover->remove($testDirectory)
        );

        $this->assertDirectoryDoesNotExist($testDirectory);
    }

    public function test_delete_directories(): void
    {
        $testDirectory = $this->setUpDirectories()->url();

        $this->assertTrue(
            $this->remover->remove($testDirectory)
        );

        $this->assertDirectoryDoesNotExist($testDirectory);
    }

    public function test_delete_invalid_directory(): void
    {
        $this->assertFalse(
            $this->remover->remove('foo')
        );
    }

    private function setUpFiles(): vfsStreamDirectory
    {
        $testDirectory = vfsStream::newDirectory(self::FILES_DIR, 0777)
            ->at($this->root);

        foreach (self::PATHS as $path) {
            vfsStream::newDirectory(dirname(self::FILES_DIR . DIRECTORY_SEPARATOR . $path), 0777)
                ->at($this->root);

            vfsStream::newFile(self::FILES_DIR . DIRECTORY_SEPARATOR . $path)
                ->withContent('foo')
                ->at($this->root);
        }

        return $testDirectory;
    }

    private function setUpDirectories(): vfsStreamDirectory
    {
        $testDirectory = vfsStream::newDirectory(self::DIRS_DIR, 0777)
            ->at($this->root);

        foreach (self::PATHS as $path) {
            vfsStream::newDirectory(dirname(self::FILES_DIR . DIRECTORY_SEPARATOR . $path), 0777)
                ->at($this->root);

            vfsStream::newDirectory(self::FILES_DIR . DIRECTORY_SEPARATOR . $path)
                ->at($this->root);
        }

        return $testDirectory;
    }
}
