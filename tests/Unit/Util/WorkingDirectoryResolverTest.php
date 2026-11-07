<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Util;

use OpenTelemetry\DevTools\Util\WorkingDirectoryResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Util\WorkingDirectoryResolver
 */
class WorkingDirectoryResolverTest extends TestCase
{
    private const TEST_WORKING_DIRECTORY = __DIR__ . '/../../../';

    #[\Override]
    public function tearDown(): void
    {
        WorkingDirectoryResolver::setCwdTestResponse(null);
    }

    public function test_resolve_invalid_cwd_response(): void
    {
        WorkingDirectoryResolver::setCwdTestResponse(false);

        $this->assertSame(
            $this->getTestWorkingDirectory(),
            WorkingDirectoryResolver::create()->resolve()
        );
    }

    private function getTestWorkingDirectory(): string
    {
        return realpath(self::TEST_WORKING_DIRECTORY);
    }
}
