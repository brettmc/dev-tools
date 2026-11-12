<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Php;

use Composer\Semver\VersionParser;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the minimum PHP version from composer.json, and the tested versions from a GitHub workflow.
 *
 * The tested versions are every `php-version` list in a job's `strategy.matrix`. `include` entries
 * are not listed, so a version that is only tried as experimental is not yet a tested one.
 * A version such as `8.2.1` counts as its branch, 8.2, and a pre-release such as `8.5.0-dev` does not
 * count. Quote the versions in the workflow: YAML reads an unquoted `8.10` as the number 8.1, so a
 * number is refused.
 */
final class ProjectPhpVersionsReader
{
    public const DEFAULT_WORKFLOW = '.github/workflows/php.yml';

    public function read(string $path, string $workflow = self::DEFAULT_WORKFLOW): ProjectPhpVersions
    {
        $path = rtrim($path, '/');
        $workflowFile = str_starts_with($workflow, '/') ? $workflow : "{$path}/{$workflow}";

        return new ProjectPhpVersions($this->minimum("{$path}/composer.json"), $this->tested($workflowFile));
    }

    private function minimum(string $file): string
    {
        $composer = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $constraint = is_array($composer) ? ($composer['require']['php'] ?? null) : null;
        if (!is_string($constraint)) {
            throw new RuntimeException("{$file} has no \"php\" requirement");
        }
        $lower = (new VersionParser())->parseConstraints($constraint)->getLowerBound()->getVersion();
        if (!preg_match('/^(\d+)\.(\d+)/', $lower, $matches)) {
            throw new RuntimeException("Cannot read a PHP version from the requirement {$constraint} in {$file}");
        }

        return "{$matches[1]}.{$matches[2]}";
    }

    /**
     * @return list<string>
     */
    private function tested(string $file): array
    {
        if (!is_file($file)) {
            throw new RuntimeException("Workflow {$file} not found");
        }

        try {
            $workflow = Yaml::parseFile($file);
        } catch (ParseException $e) {
            throw new RuntimeException("Cannot parse {$file}: {$e->getMessage()}", 0, $e);
        }
        $versions = [];
        foreach (is_array($workflow) && is_array($workflow['jobs'] ?? null) ? $workflow['jobs'] : [] as $job) {
            $listed = is_array($job) ? ($job['strategy']['matrix']['php-version'] ?? []) : [];
            foreach (is_array($listed) ? $listed : [$listed] as $version) {
                if (is_int($version) || is_float($version)) {
                    throw new RuntimeException("{$file}: quote the PHP version {$version} in strategy.matrix.php-version, as YAML reads an unquoted version as a number");
                }
                //8.2.1 tests branch 8.2. A pre-release, such as 8.5.0-dev, or nightly does not
                if (is_string($version) && preg_match('/^(\d+\.\d+)(?:\.\d+)?$/', $version, $matches)) {
                    $versions[$matches[1]] = true;
                }
            }
        }
        if ($versions === []) {
            throw new RuntimeException("{$file} has no strategy.matrix.php-version list");
        }
        $versions = array_map('strval', array_keys($versions));
        usort($versions, 'version_compare');

        return $versions;
    }
}
