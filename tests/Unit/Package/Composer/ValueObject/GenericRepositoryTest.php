<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer\ValueObject;

use OpenTelemetry\DevTools\Package\Composer\ValueObject\GenericRepository;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\ValueObject\GenericRepository
 */
class GenericRepositoryTest extends AbstractRepositoryTest
{
    public function test_create(): void
    {
        $package = $this->createPackageInterfaceMock();

        $repository = GenericRepository::create(
            self::REPOSITORY_URL,
            self::REPOSITORY_TYPE,
            [$package],
        );

        $this->assertSame(
            self::REPOSITORY_URL,
            $repository->getUrl()
        );

        $this->assertSame(
            self::REPOSITORY_TYPE,
            $repository->getType()
        );

        $this->assertSame(
            $package,
            $repository->getPackages()[0]
        );
    }
}
