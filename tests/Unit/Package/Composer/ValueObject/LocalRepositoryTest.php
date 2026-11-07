<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer\ValueObject;

use OpenTelemetry\DevTools\Package\Composer\RepositoryTypes;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\LocalRepository;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\ValueObject\LocalRepository
 */
class LocalRepositoryTest extends AbstractRepositoryTest
{
    public function test_get_type(): void
    {
        $this->assertSame(
            RepositoryTypes::PATH_TYPE,
            LocalRepository::create(
                'foo',
                $this->createPackageInterfaceMock()
            )->getType()
        );
    }
}
