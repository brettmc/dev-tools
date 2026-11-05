<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Fixture\\' => dirname(__DIR__) . '/src/',
        'Nevay\\SPI\\' => __DIR__ . '/tbachert/spi/src/',
    ];
    foreach ($prefixes as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            is_file($file) && require $file;
        }
    }
});
