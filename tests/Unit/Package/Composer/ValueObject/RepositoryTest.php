<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer\ValueObject;

use OpenTelemetry\DevTools\Package\Composer\ValueObject\RepositoryInterface;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\RepositoryTrait;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\ValueObject\RepositoryTrait
 */
class RepositoryTest extends AbstractRepositoryTest
{
    public function test_to_array(): void
    {
        $this->assertSame(
            self::ATTRIBUTES,
            $this->createInstance()->toArray()
        );
    }
    
    private function createInstance(): RepositoryInterface
    {
        return new class(self::ATTRIBUTES[RepositoryInterface::URL_ATTRIBUTE], self::ATTRIBUTES[RepositoryInterface::TYPE_ATTRIBUTE], [$this->createPackageInterfaceMock()], ) implements RepositoryInterface {
            use RepositoryTrait;

            public function __construct(string $url, string $type, array $packages = [])
            {
                $this->url = $url;
                $this->type = $type;
                $this->packages = $packages;
            }
        };
    }
}
