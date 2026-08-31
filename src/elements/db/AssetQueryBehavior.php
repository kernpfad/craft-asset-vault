<?php

declare(strict_types=1);

namespace kernpfad\assetvault\elements\db;

use yii\base\Behavior;

/**
 * Custom AssetQuery criteria used by the Asset Vault index sources.
 *
 * Kept as query flags (instead of baking asset IDs into the source at
 * registration time) so the expensive missing-on-filesystem scan only runs
 * when that source is actually queried.
 *
 * @property bool|null $assetVaultVaulted
 * @property bool|null $assetVaultMissingOnFs
 */
class AssetQueryBehavior extends Behavior
{
    public ?bool $assetVaultVaulted = null;

    public ?bool $assetVaultMissingOnFs = null;
}
