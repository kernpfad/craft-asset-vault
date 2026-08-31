<?php

/**
 * PHPUnit bootstrap.
 *
 * Normally this just uses the plugin's own `vendor/autoload.php`, as the
 * sibling plugins in this repo do. It also accepts a second arrangement:
 * if the plugin hasn't been `composer install`ed on its own but
 * CRAFT_TEST_SITE_PATH points at a Craft install that has it linked in via
 * a Composer path repository, that install's autoloader is used instead.
 *
 * The integration tests boot Craft from CRAFT_TEST_SITE_PATH regardless,
 * so in that arrangement everything resolves from one place.
 */

$pluginAutoload = __DIR__ . '/../vendor/autoload.php';
$sitePath = getenv('CRAFT_TEST_SITE_PATH');

if (file_exists($pluginAutoload)) {
    require $pluginAutoload;
} elseif ($sitePath && file_exists($sitePath . '/vendor/autoload.php')) {
    require $sitePath . '/vendor/autoload.php';
} else {
    fwrite(STDERR, "No autoloader found. Run `composer install` in the plugin directory, or set CRAFT_TEST_SITE_PATH to a Craft install that has this plugin linked in.\n");
    exit(1);
}

// The tests' own namespace lives outside whichever autoloader we picked up.
spl_autoload_register(static function(string $class): void {
    $prefix = 'kernpfad\\assetvault\\tests\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $relative . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
