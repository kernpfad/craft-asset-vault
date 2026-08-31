<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests/unit',
    ]);
    // Load the set file directly — craft\rector\SetList still implements a
    // Rector 1 interface that was removed in Rector 2.
    $rectorConfig->sets([
        __DIR__ . '/vendor/craftcms/rector/sets/craft-cms-50.php',
    ]);
};
