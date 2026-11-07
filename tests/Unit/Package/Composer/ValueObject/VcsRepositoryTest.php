<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer\ValueObject;

use OpenTelemetry\DevTools\Package\Composer\RepositoryTypes;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\VcsRepository;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\ValueObject\VcsRepository
 */
class VcsRepositoryTest extends AbstractRepositoryTest
{
    public function test_get_type(): void
    {
        $this->assertSame(
            RepositoryTypes::VCS_TYPE,
            VcsRepository::create(
                'foo',
                $this->createPackageInterfaceMock()
            )->getType()
        );
    }
}
