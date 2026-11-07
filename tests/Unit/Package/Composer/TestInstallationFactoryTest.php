<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Tests\Unit\Package\Composer;

use ArrayIterator;
use OpenTelemetry\DevTools\Package\Composer\TestConfig;
use OpenTelemetry\DevTools\Package\Composer\TestConfigFactory;
use OpenTelemetry\DevTools\Package\Composer\TestInstallationFactory;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\RepositoryCollection;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\SingleRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\DevTools\Package\Composer\TestInstallationFactory
 */
class TestInstallationFactoryTest extends TestCase
{
    private const TESTED_BRANCH = 'root';
    private const TESTED_BRANCH_VERSION = 'dev-' . self::TESTED_BRANCH;

    private TestInstallationFactory $instance;
    private TestConfigFactory&MockObject $testConfigFactory;

    #[\Override]
    protected function setUp(): void
    {
        $this->testConfigFactory = $this->createMock(TestConfigFactory::class);

        $this->instance = TestInstallationFactory::create(
            $this->testConfigFactory
        );
    }

    public function test_build(): void
    {
        $config = $this->createMock(TestConfig::class);
        $this->testConfigFactory
            ->method('build')
            ->willReturn($config);

        $testedRepository = $this->createMock(SingleRepositoryInterface::class);

        $dependencies = $this->createMock(RepositoryCollection::class);
        $dependencies->method('getIterator')
            ->willReturn(new ArrayIterator([]));

        $installation = $this->instance->build(
            $testedRepository,
            self::TESTED_BRANCH,
            $dependencies
        );

        $this->assertSame(
            $testedRepository,
            $installation->getTestedRepository()
        );

        $this->assertSame(
            $config,
            $installation->getConfig()
        );

        $this->assertSame(
            self::TESTED_BRANCH,
            $installation->getTestedBranch()
        );

        $this->assertSame(
            self::TESTED_BRANCH_VERSION,
            $installation->getTestedBranchVersion()
        );
    }

    public function test_get_config_factory(): void
    {
        $this->assertSame(
            $this->testConfigFactory,
            $this->instance->getTestConfigFactory()
        );
    }

    public function test_add_default_dependency(): void
    {
        $this->testConfigFactory
            ->expects($this->once())
            ->method('addDefaultDependency');

        $this->instance->addDefaultDependency('foo', 'bar');
    }
}
