<?php

declare(strict_types=1);

namespace kernpfad\assetvault\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Queue;
use kernpfad\assetvault\AssetVault;
use kernpfad\assetvault\jobs\ArchiveAssets;

/**
 * Bulk-archives selected assets into the vault without deleting them.
 * Work runs as a queue job so the CP can show progress for large selections.
 */
class ArchiveAssetsAction extends ElementAction
{
    public static function displayName(): string
    {
        return Craft::t('asset-vault', 'Archive to Vault');
    }

    public function getTriggerLabel(): string
    {
        return Craft::t('asset-vault', 'Archive to Vault');
    }

    public function getConfirmationMessage(): ?string
    {
        return Craft::t(
            'asset-vault',
            'Archive the selected assets to the vault? The originals stay in place; a recoverable copy is stored.'
        );
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $plugin = AssetVault::getInstance();

        if ($plugin === null) {
            $this->setMessage(Craft::t('asset-vault', 'Asset Vault is not available.'));

            return false;
        }

        if (!Craft::$app->getUser()->checkPermission('assetVault:manage')) {
            $this->setMessage(Craft::t('asset-vault', 'You don’t have permission to archive assets to the vault.'));

            return false;
        }

        $elements = Craft::$app->getElements();
        $user = Craft::$app->getUser()->getIdentity();

        /** @var int[] $assetIds */
        $assetIds = [];

        /** @var Asset $asset */
        foreach ($query->all() as $asset) {
            if ($asset->id === null) {
                continue;
            }

            if ($plugin->isExcluded($asset)) {
                continue;
            }

            if ($user !== null && !$elements->canSave($asset, $user)) {
                continue;
            }

            $assetIds[] = (int)$asset->id;
        }

        $assetIds = array_values(array_unique($assetIds));

        if ($assetIds === []) {
            $this->setMessage(Craft::t('asset-vault', 'No assets were eligible to archive.'));

            return false;
        }

        Queue::push(new ArchiveAssets([
            'assetIds' => $assetIds,
        ]));

        $this->setMessage(Craft::t(
            'asset-vault',
            'Archiving {count, plural, =1{# asset} other{# assets}} to the vault…',
            ['count' => count($assetIds)]
        ));

        return true;
    }
}
