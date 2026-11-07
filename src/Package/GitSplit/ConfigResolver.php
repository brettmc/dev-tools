<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\GitSplit;

use Composer\Factory;
use Composer\Util\Filesystem;
use InvalidArgumentException;
use OpenTelemetry\DevTools\Package\Composer\ConfigResolverInterface;
use OpenTelemetry\DevTools\Util\WorkingDirectoryResolver;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ConfigResolver implements ConfigResolverInterface
{
    private const SPLITS_KEY = 'splits';
    private const PREFIX_KEY = 'prefix';
    private const GIT_SPLIT_FILE_NAME = '.gitsplit.yml';

    private string $configFile;

    public function __construct(?string $configFile = null)
    {
        $this->configFile = $configFile ?? $this->getDefaultConfigPath();
    }

    #[\Override]
    public function resolve(): iterable
    {
        $gitSplitConfig = self::parseGitSplitFile($this->configFile);

        if (!ConfigValidator::create($gitSplitConfig)->validate()) {
            return [];
        }

        return self::resolveComposerFiles($gitSplitConfig);
    }

    public function getDefaultConfigPath(): string
    {
        return sprintf('%s/%s', WorkingDirectoryResolver::create()->resolve(), self::GIT_SPLIT_FILE_NAME);
    }

    private static function parseGitSplitFile(string $filePath): array
    {
        try {
            return Yaml::parseFile($filePath);
        } catch (ParseException $e) {
            throw new InvalidArgumentException(
                sprintf('Could not parse GitSplit file at: %s. %s', $filePath, $e->getMessage())
            );
        }
    }

    private static function resolveComposerFiles(array $gitSplitConfig): iterable
    {
        $fs = new Filesystem();
        $composerFile = Factory::getComposerFile();
        foreach ($gitSplitConfig[self::SPLITS_KEY] as $pathConfig) {
            if (isset($pathConfig[self::PREFIX_KEY]) && is_string($pathConfig[self::PREFIX_KEY])) {
                $path = $pathConfig[self::PREFIX_KEY];

                yield $fs->normalizePath($path) => $fs->normalizePath($path . '/' . $composerFile);
            }
        }
    }
}
