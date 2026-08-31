<?php

declare(strict_types=1);

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->parallel();
    $ecsConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __FILE__,
    ]);

    $setListClass = new \ReflectionClass(SetList::class);

    if ($setListClass->hasConstant('CRAFT_CMS_5')) {
        $ecsConfig->sets([SetList::CRAFT_CMS_5]);
    } else {
        $ecsConfig->sets([SetList::CRAFT_CMS_4]);
    }
};
