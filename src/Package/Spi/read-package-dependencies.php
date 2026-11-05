<?php

declare(strict_types=1);

/*
 * Prints, as JSON, the #[PackageDependency] attributes on the service providers that a checkout's
 * root composer.json registers in extra.spi.
 *
 * Run by PackageDependencyReader under the checkout's own PHP and autoloader, not dev-tools': both
 * vendor trees carry composer and symfony, and loading them into one process can clash.
 *
 * Usage: php read-package-dependencies.php <checkout>
 */

const PACKAGE_DEPENDENCY = 'Nevay\SPI\ServiceProviderDependency\PackageDependency';

$root = $argv[1] ?? null;
if (!is_string($root) || !is_file("{$root}/composer.json")) {
    fwrite(STDERR, 'usage: ' . basename(__FILE__) . " <checkout with a composer.json>\n");
    exit(2);
}
$composer = json_decode((string) file_get_contents("{$root}/composer.json"), true);
$spi = is_array($composer) ? ($composer['extra']['spi'] ?? []) : [];

//the autoloader runs the checkout's autoload.files, any of which may print
ob_start();
require "{$root}/vendor/autoload.php";
ob_end_clean();

$base = rtrim((string) realpath($root), '/') . '/';
$dependencies = [];
$missing = [];
foreach ((array) $spi as $providers) {
    foreach ((array) $providers as $provider) {
        try {
            $reflection = new ReflectionClass($provider);
        } catch (Throwable) {
            $missing[] = $provider;

            continue;
        }
        $file = (string) $reflection->getFileName();
        //arguments rather than newInstance(): the attribute keeps them in private properties, which
        //are not part of tbachert/spi's API
        foreach ($reflection->getAttributes(PACKAGE_DEPENDENCY) as $attribute) {
            $arguments = $attribute->getArguments();
            $dependencies[] = [
                'provider' => $provider,
                'package' => $arguments['package'] ?? $arguments[0] ?? null,
                'version' => $arguments['version'] ?? $arguments[1] ?? null,
                'file' => str_starts_with($file, $base) ? substr($file, strlen($base)) : null,
            ];
        }
    }
}

echo json_encode(['dependencies' => $dependencies, 'missing' => $missing], JSON_UNESCAPED_SLASHES);
