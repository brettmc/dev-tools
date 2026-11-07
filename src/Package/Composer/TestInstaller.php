<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer;

use InvalidArgumentException;
use OpenTelemetry\DevTools\Util\RecursiveDirectoryRemover;
use RuntimeException;
use Throwable;

class TestInstaller
{
    private static ?RecursiveDirectoryRemover $directoryRemover;

    private string $rootDirectory;

    public function __construct(string $rootDirectory)
    {
        $this->setRootDirectory($rootDirectory);
    }

    public static function create(string $rootDirectory): TestInstaller
    {
        return new self($rootDirectory);
    }

    public function install(TestInstallation $installation): bool
    {
        try {
            $testDirectory = $this->getRootDirectory();

            self::createDirectory($testDirectory);

            $installation->writeComposerFile();

            return true;
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Could not install: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    public function remove(TestInstallation $installation): bool
    {
        try {
            self::removeDirectory($this->getRootDirectory());

            return true;
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Could not remove: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    public function setRootDirectory(string $rootDirectory): void
    {
        $this->rootDirectory = $rootDirectory;
    }

    public function getRootDirectory(): string
    {
        return $this->rootDirectory;
    }

    public static function setDirectoryRemover(?RecursiveDirectoryRemover $directoryRemover): void
    {
        self::$directoryRemover = $directoryRemover;
    }

    private static function createDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            self::removeDirectory($directory);
        }

        if (!mkdir($directory, 0777, true) || !is_dir($directory)) {
            throw new RuntimeException(sprintf('Directory "%s" could not be created.', $directory));
        }
    }

    private static function removeDirectory(string $directory): void
    {
        self::getDirectoryRemover()->remove($directory);
    }

    private static function getDirectoryRemover(): RecursiveDirectoryRemover
    {
        return self::$directoryRemover ?? self::$directoryRemover = RecursiveDirectoryRemover::create();
    }
}
