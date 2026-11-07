<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer;

use OpenTelemetry\DevTools\Package\Composer\ValueObject\RepositoryCollection;
use OpenTelemetry\DevTools\Package\Composer\ValueObject\SingleRepositoryInterface;
use RuntimeException;
use Throwable;

class TestInstallation
{
    public const COMPOSER_FILE_NAME = 'composer.json';
    public const DEFAULT_BRANCH = 'main';
    public const BRANCH_VERSION_PREFIX = 'dev-';

    private SingleRepositoryInterface $testedRepository;
    private TestConfig $config;
    private RepositoryCollection $dependencies;
    private string $testedBranch;

    public function __construct(
        SingleRepositoryInterface $testedRepository,
        ?TestConfig $config = null,
        ?RepositoryCollection $dependencies = null,
        ?string $testedBranch = null
    ) {
        $this->init($testedRepository, $config, $dependencies, $testedBranch);
    }

    public static function create(
        SingleRepositoryInterface $testedRepository,
        ?TestConfig $config = null,
        ?RepositoryCollection $dependencies = null,
        ?string $testedBranch = null
    ): TestInstallation {
        return new self($testedRepository, $config, $dependencies, $testedBranch);
    }

    public function getConfig(): TestConfig
    {
        return $this->config;
    }

    public function getTestedRepository(): SingleRepositoryInterface
    {
        return $this->testedRepository;
    }

    public function getDependencies(): RepositoryCollection
    {
        return $this->dependencies;
    }

    public function getTestedBranch(): string
    {
        return $this->testedBranch;
    }

    public function getTestedBranchVersion(): string
    {
        return self::normalizeBranchVersion(
            $this->testedBranch
        );
    }

    private function init(
        SingleRepositoryInterface $testedRepository,
        ?TestConfig $config = null,
        ?RepositoryCollection $dependencies = null,
        ?string $testedBranch = null
    ): void {
        $this->setTestedRepository($testedRepository);
        $this->setConfig($config);
        $this->setDependencies($dependencies);
        $this->setTestedBranch($testedBranch);

        $this->requireTestedRepository();
        $this->requireDependencies();
    }

    public function writeComposerFile(): void
    {
        try {
            file_put_contents(
                $this->getTestedRepository()->getComposerFilePath(),
                $this->toJson()
            );
        } catch (Throwable $t) {
            throw new RuntimeException(
                sprintf('Could not write config to %s', $this->getTestedRepository()->getComposerFilePath()),
                (int) $t->getCode(),
                $t
            );
        }
    }

    public function toJson(): string
    {
        try {
            return json_encode($this->config->toArray(), JSON_THROW_ON_ERROR + JSON_PRETTY_PRINT);
        } catch (Throwable $t) {
            throw new RuntimeException(
                sprintf('Could not serialize "%s"', __CLASS__),
                (int) $t->getCode(),
                $t
            );
        }
    }

    public function __toString()
    {
        return $this->toJson();
    }

    private function setTestedRepository(SingleRepositoryInterface $package): void
    {
        $this->testedRepository = $package;
    }

    private function setConfig(?TestConfig $config): void
    {
        $this->config = $config ?? TestConfigFactory::create()->build();
    }

    private function setTestedBranch(?string $testedBranch = null): void
    {
        $this->testedBranch = $testedBranch ?? self::DEFAULT_BRANCH;
    }

    private function setDependencies(?RepositoryCollection $dependencies = null): void
    {
        $this->dependencies = $dependencies ?? RepositoryCollection::create();
    }

    private function requireTestedRepository(): void
    {
        $this->getConfig()->addRequire(
            $this->testedRepository->getPackageName(),
            $this->getTestedBranchVersion()
        );
    }

    private function requireDependencies(): void
    {
        /** @var SingleRepositoryInterface $repository */
        foreach ($this->getDependencies() as $repository) {
            $this->getConfig()->addRepository(
                $repository->getType(),
                $repository->getUrl()
            );
        }
    }

    private static function normalizeBranchVersion(string $branchVersion): string
    {
        return self::BRANCH_VERSION_PREFIX . preg_replace(
            sprintf('/%s/', self::BRANCH_VERSION_PREFIX),
            '',
            $branchVersion
        );
    }
}
