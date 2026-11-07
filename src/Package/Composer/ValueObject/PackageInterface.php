<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer\ValueObject;

interface PackageInterface extends ValueObjectInterface
{
    public const TYPE_ATTRIBUTE = 'type';
    public const NAME_ATTRIBUTE = 'name';
    public const DEPENDENCIES_ATTRIBUTE = 'require';
    public const ATTRIBUTES = [
        self::TYPE_ATTRIBUTE,
        self::NAME_ATTRIBUTE,
        self::DEPENDENCIES_ATTRIBUTE,
    ];
    public const MANDATORY_ATTRIBUTES = [
        self::TYPE_ATTRIBUTE,
        self::NAME_ATTRIBUTE,
    ];

    public function getName(): string;

    public function getType(): string;

    public function getDependencies(): ?DependencyCollection;
}
