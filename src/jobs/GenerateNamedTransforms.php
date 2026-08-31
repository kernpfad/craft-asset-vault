<?php

declare(strict_types=1);

namespace kernpfad\assetvault\jobs;

use Craft;
use craft\elements\Asset;
use craft\queue\BaseJob;
use kernpfad\assetvault\AssetVault;

/**
 * Eager-generates every named image transform for a restored asset.
 */
class GenerateNamedTransforms extends BaseJob
{
    public int $assetId = 0;

    public function execute($queue): void
    {
        $plugin = AssetVault::getInstance();

        if ($plugin === null || $this->assetId <= 0) {
            return;
        }

        $asset = Asset::find()->id($this->assetId)->status(null)->one();

        if (!$asset instanceof Asset) {
            return;
        }

        $this->setProgress($queue, 0.1, Craft::t('asset-vault', 'Warming image transforms…'));
        $plugin->vault->warmNamedTransforms($asset);
        $this->setProgress($queue, 1);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('asset-vault', 'Generating image transforms for restored asset');
    }
}
