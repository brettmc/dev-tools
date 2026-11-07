<?php

declare(strict_types=1);

namespace OpenTelemetry\DevTools\Package\Composer\ValueObject;

class GenericRepository implements MultiRepositoryInterface
{
    use RepositoryTrait;

    public function __construct(string $url, string $type, array $packages = [])
    {
        $this->url = $url;
        $this->type = $type;
        $this->packages = $packages;
    }

    public static function create(string $url, string $type, array $packages = []): self
    {
        return new self($url, $type, $packages);
    }
}
