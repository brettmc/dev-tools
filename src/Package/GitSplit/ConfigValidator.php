<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\GitSplit;

class ConfigValidator
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function create(array $config): self
    {
        return new self($config);
    }

    public function validate(): bool
    {
        if (!isset($this->config[ConfigAttributes::SPLITS_ATTRIBUTE])
            || !is_array($this->config[ConfigAttributes::SPLITS_ATTRIBUTE])) {
            return false;
        }

        foreach ($this->config[ConfigAttributes::SPLITS_ATTRIBUTE] as $config) {
            if (!self::validateSplitAttributes($config)) {
                return false;
            }
        }

        return true;
    }

    private static function validateSplitAttributes($config): bool
    {
        if (!is_array($config)) {
            return false;
        }

        foreach (ConfigAttributes::SPLITS_ATTRIBUTES as $attribute) {
            if (!array_key_exists($attribute, $config)) {
                return false;
            }
        }

        return true;
    }
}
