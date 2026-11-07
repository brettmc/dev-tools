<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer\ValueObject;

interface RepositoryInterface extends ValueObjectInterface
{
    public const TYPE_ATTRIBUTE = 'type';
    public const URL_ATTRIBUTE = 'url';
    public const PACKAGES_ATTRIBUTE = 'packages';
    public const ATTRIBUTES = [
        self::TYPE_ATTRIBUTE,
        self::URL_ATTRIBUTE,
        self::PACKAGES_ATTRIBUTE,
    ];

    public function getUrl(): string;

    public function getType(): string;

    public function getPackages(): array;
}
