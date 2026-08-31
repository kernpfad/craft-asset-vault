<?php

declare(strict_types=1);

namespace kernpfad\assetvault\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int|null $originalAssetId
 * @property int $volumeId
 * @property string $volumeHandle
 * @property int|null $folderId
 * @property string $folderPath
 * @property string $filename
 * @property string $vaultPath
 * @property string|null $kind
 * @property int|null $size
 * @property int|null $width
 * @property int|null $height
 * @property string|null $title
 * @property string|null $altText
 * @property string|null $focalPoint
 * @property string|null $fieldData
 * @property int|null $deletedByUserId
 * @property string $dateDeleted
 */
class VaultItemRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%assetvault_items}}';
    }
}
