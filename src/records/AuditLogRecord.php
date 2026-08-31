<?php

declare(strict_types=1);

namespace kernpfad\assetvault\records;

use craft\db\ActiveRecord;

/**
 * One row per lifecycle action a vault item goes through. Kept separate from
 * VaultItemRecord because the vault record is deleted on restore/purge —
 * something has to survive the row it's reporting on.
 *
 * @property int $id
 * @property string $action
 * @property int|null $originalAssetId
 * @property int|null $restoredAssetId
 * @property int|null $volumeId
 * @property string|null $volumeHandle
 * @property string $filename
 * @property int|null $userId
 * @property string $dateCreated
 */
class AuditLogRecord extends ActiveRecord
{
    public const ACTION_ARCHIVED = 'archived';
    public const ACTION_RESTORED = 'restored';
    public const ACTION_DELETED_FOREVER = 'deletedForever';
    public const ACTION_PURGED = 'purged';

    public static function tableName(): string
    {
        return '{{%assetvault_audit_log}}';
    }

    /**
     * Human-readable label for the action, for the audit log CP listing.
     */
    public function getActionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_ARCHIVED => 'Archived',
            self::ACTION_RESTORED => 'Restored',
            self::ACTION_DELETED_FOREVER => 'Deleted forever',
            self::ACTION_PURGED => 'Purged (retention expired)',
            default => $this->action,
        };
    }
}
