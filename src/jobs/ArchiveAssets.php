<?php

declare(strict_types=1);

namespace kernpfad\assetvault\jobs;

use Craft;
use craft\elements\Asset;
use craft\queue\BaseJob;
use kernpfad\assetvault\AssetVault;

/**
 * Archives a list of assets into the vault without deleting the live elements.
 */
class ArchiveAssets extends BaseJob
{
    /** @var int[] */
    public array $assetIds = [];

    public function execute($queue): void
    {
        $plugin = AssetVault::getInstance();

        if ($plugin === null) {
            return;
        }

        $total = count($this->assetIds);

        if ($total === 0) {
            return;
        }

        foreach ($this->assetIds as $i => $assetId) {
            $this->setProgress(
                $queue,
                ($i + 1) / $total,
                Craft::t('asset-vault', 'Archiving asset {current} of {total}', [
                    'current' => $i + 1,
                    'total' => $total,
                ])
            );

            $asset = Asset::find()->id($assetId)->status(null)->one();

            if (!$asset instanceof Asset) {
                continue;
            }

            if ($plugin->isExcluded($asset)) {
                continue;
            }

            $plugin->vault->trashAsset($asset);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('asset-vault', 'Archiving assets to the vault');
    }
}
