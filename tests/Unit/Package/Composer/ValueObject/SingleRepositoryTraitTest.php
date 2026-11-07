<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer\ValueObject;

use OpenTelemetry\DevTools\Package\Composer\ValueObject\RepositoryInterface;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\SingleRepositoryInterface;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\SingleRepositoryTrait;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\ValueObject\SingleRepositoryTrait
 */
class SingleRepositoryTraitTest extends AbstractRepositoryTest
{
    public const REPOSITORY_TYPE = 'path';
    private const COMPOSER_FILE_PATH = self::ATTRIBUTES[RepositoryInterface::URL_ATTRIBUTE]
        . DIRECTORY_SEPARATOR . SingleRepositoryInterface::COMPOSER_FILE_NAME;

    private SingleRepositoryInterface $repository;

    #[\Override]
    public function setUp(): void
    {
        $this->repository = $this->createInstance();
    }

    public function test_get_composer_file_path(): void
    {
        $this->assertSame(
            self::COMPOSER_FILE_PATH,
            $this->repository->getComposerFilePath()
        );
    }

    public function test_get_package(): void
    {
        $this->assertEquals(
            $this->createPackageInterfaceMock(),
            $this->repository->getPackage()
        );
    }

    public function test_get_package_name(): void
    {
        $this->assertEquals(
            PackageAttributes::NAME,
            $this->repository->getPackageName()
        );
    }

    public function test_get_package_type(): void
    {
        $this->assertEquals(
            PackageAttributes::TYPE,
            $this->repository->getPackageType()
        );
    }

    public function test_get_root_directory(): void
    {
        $this->assertEquals(
            self::ROOT_DIRECTORY,
            $this->repository->getRootDirectory()
        );
    }

    private function createInstance(): SingleRepositoryInterface
    {
        return SingleRepositoryTraitImplementation::create(
            self::ATTRIBUTES[RepositoryInterface::URL_ATTRIBUTE],
            $this->createPackageInterfaceMock()
        );
    }
}

class SingleRepositoryTraitImplementation implements SingleRepositoryInterface
{
    use SingleRepositoryTrait;

    #[\Override]
    protected function setType(): void
    {
        $this->type = SingleRepositoryTraitTest::REPOSITORY_TYPE;
    }
}
