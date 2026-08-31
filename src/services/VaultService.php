<?php

declare(strict_types=1);

namespace kernpfad\assetvault\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\Db;
use craft\helpers\Queue;
use craft\models\Volume;
use craft\models\VolumeFolder;
use DateInterval;
use DateTime;
use kernpfad\assetvault\events\ArchiveEvent;
use kernpfad\assetvault\events\RestoreEvent;
use kernpfad\assetvault\jobs\GenerateNamedTransforms;
use kernpfad\assetvault\records\AuditLogRecord;
use kernpfad\assetvault\records\VaultItemRecord;
use yii\base\Component;

class VaultService extends Component
{
    private const MISSING_ON_FS_CACHE_KEY = 'asset-vault:missing-on-fs-ids';

    /**
     * @event ArchiveEvent Fired before an asset is copied into the vault.
     * Setting $isValid = false skips vaulting it.
     */
    public const EVENT_BEFORE_ARCHIVE = 'beforeArchive';

    /**
     * @event ArchiveEvent Fired after an asset has been copied into the vault.
     */
    public const EVENT_AFTER_ARCHIVE = 'afterArchive';

    /**
     * @event RestoreEvent Fired before a vault item is restored. Setting
     * $isValid = false aborts the restore.
     */
    public const EVENT_BEFORE_RESTORE = 'beforeRestore';

    /**
     * @event RestoreEvent Fired after a vault item has been restored as a new asset.
     */
    public const EVENT_AFTER_RESTORE = 'afterRestore';

    public function __construct(
        private readonly PathResolver $paths,
        private readonly FieldDataNormalizer $fieldData,
        $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * @return VaultItemRecord[]
     */
    public function getAllItems(): array
    {
        /** @var VaultItemRecord[] $items */
        $items = VaultItemRecord::find()
            ->orderBy(['dateDeleted' => SORT_DESC])
            ->all();

        return $items;
    }

    public function getItem(int $vaultItemId): ?VaultItemRecord
    {
        return VaultItemRecord::findOne($vaultItemId);
    }

    /**
     * @param int $limit Most recent entries first; capped so the CP listing
     *                    can't be made to load an unbounded audit history.
     * @return AuditLogRecord[]
     */
    public function getAuditLog(int $limit = 200): array
    {
        /** @var AuditLogRecord[] $entries */
        $entries = AuditLogRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();

        return $entries;
    }

    /**
     * Asset IDs that currently have a vault copy (live bulk-archives and
     * soft-deleted assets that were vaulted on delete). Used by the
     * "Vaulted" Asset Index source.
     *
     * @return int[]
     */
    public function getVaultedAssetIds(): array
    {
        /** @var list<int|string|null> $ids */
        $ids = VaultItemRecord::find()
            ->select(['originalAssetId'])
            ->where(['not', ['originalAssetId' => null]])
            ->column();

        return array_values(array_unique(array_map('intval', array_filter($ids, static fn($id) => $id !== null && $id !== ''))));
    }

    /**
     * Live assets whose file is missing from their volume. Used by the
     * "Missing on filesystem" Asset Index source. Results are cached briefly
     * because opening the Assets index re-registers sources on every load,
     * and scanning remote volumes asset-by-asset is not free.
     *
     * @return int[]
     */
    public function findMissingOnFsAssetIds(): array
    {
        $cache = Craft::$app->getCache();

        if ($cache === null) {
            return $this->scanMissingOnFsAssetIds();
        }

        $cacheKey = self::MISSING_ON_FS_CACHE_KEY;
        $cached = $cache->get($cacheKey);

        if (is_array($cached)) {
            /** @var int[] $cached */
            return $cached;
        }

        $missing = $this->scanMissingOnFsAssetIds();
        $cache->set($cacheKey, $missing, 60);

        return $missing;
    }

    /**
     * Drops the short-lived missing-on-filesystem ID cache so the next
     * Assets Index query against that source re-scans.
     */
    public function clearMissingOnFsCache(): void
    {
        Craft::$app->getCache()?->delete(self::MISSING_ON_FS_CACHE_KEY);
    }

    /**
     * @return int[]
     */
    private function scanMissingOnFsAssetIds(): array
    {
        $missing = [];

        /** @var Asset $asset */
        foreach (Asset::find()->each() as $asset) {
            try {
                $volume = $asset->getVolume();
                $path = $asset->getPath();
            } catch (\Throwable) {
                $missing[] = (int)$asset->id;
                continue;
            }

            try {
                if (!$volume->fileExists($path)) {
                    $missing[] = (int)$asset->id;
                }
            } catch (\Throwable) {
                // A volume that can't answer fileExists() is treated as
                // unknown rather than missing — don't flood the source.
            }
        }

        return $missing;
    }

    /**
     * Copies an asset's file into the vault and records everything needed
     * to recreate it later. Used both just before Craft removes a deleted
     * file, and by the bulk "Archive" action which leaves the live asset
     * in place.
     *
     * Re-archiving the same asset ID replaces any previous vault copy so
     * bulk-archive-then-delete does not leave duplicate vault rows.
     */
    public function trashAsset(Asset $asset): bool
    {
        if ($this->hasEventHandlers(self::EVENT_BEFORE_ARCHIVE)) {
            $event = new ArchiveEvent(['asset' => $asset]);
            $this->trigger(self::EVENT_BEFORE_ARCHIVE, $event);

            if (!$event->isValid) {
                return false;
            }
        }

        $volume = $asset->getVolume();
        $volumeId = $volume->id;

        if ($volumeId === null) {
            Craft::warning("Asset Vault can't vault asset #{$asset->id}: its volume has no ID.", __METHOD__);

            return false;
        }

        if ($asset->id !== null) {
            $this->discardExistingVaultItemForAsset((int)$asset->id);
        }

        $sourcePath = $asset->getPath();
        $vaultPath = $this->paths->vaultPath($volumeId, (string)$asset->uid, $asset->getFilename());

        try {
            $volume->copyFile($sourcePath, $vaultPath);
        } catch (\Throwable $e) {
            Craft::warning(
                "Asset Vault couldn't copy \"{$sourcePath}\" to the vault, so it won't be recoverable: {$e->getMessage()}",
                __METHOD__
            );

            return false;
        }

        $record = new VaultItemRecord();
        $record->originalAssetId = $asset->id;
        $record->volumeId = $volumeId;
        $record->volumeHandle = (string)$volume->handle;
        $record->folderId = $asset->folderId;
        $record->folderPath = $asset->getFolder()->path ?? '';
        $record->filename = $asset->getFilename();
        $record->vaultPath = $vaultPath;
        $record->kind = $asset->kind;
        $record->size = $asset->size;
        $record->width = $asset->getWidth();
        $record->height = $asset->getHeight();
        $record->title = $asset->title;
        $record->altText = $asset->alt;
        $focalPoint = $asset->getFocalPoint();
        // json_encode() returns false on failure; storing that would put a
        // literal "" in a column the restore path later json_decode()s.
        $record->focalPoint = $focalPoint ? (json_encode($focalPoint) ?: null) : null;
        $record->fieldData = json_encode($asset->getSerializedFieldValues()) ?: null;
        $userId = $this->currentUserId();
        $record->deletedByUserId = $userId;
        $record->dateDeleted = (string)Db::prepareDateForDb(new DateTime());

        if (!$record->save()) {
            try {
                $volume->deleteFile($vaultPath);
            } catch (\Throwable $e) {
                Craft::warning(
                    "Asset Vault couldn't roll back \"{$vaultPath}\" after failing to save the vault record: {$e->getMessage()}",
                    __METHOD__
                );
            }

            return false;
        }

        $this->logAudit(
            AuditLogRecord::ACTION_ARCHIVED,
            $record->filename,
            originalAssetId: $asset->id,
            volumeId: $volumeId,
            volumeHandle: $record->volumeHandle,
            userId: $userId,
        );

        if ($this->hasEventHandlers(self::EVENT_AFTER_ARCHIVE)) {
            $this->trigger(self::EVENT_AFTER_ARCHIVE, new ArchiveEvent(['asset' => $asset]));
        }

        $this->clearMissingOnFsCache();

        return true;
    }

    /**
     * Computes where a restore would land — which volume, which folder, the
     * final (possibly renamed) path — without touching the filesystem or
     * database. Used both as a dry-run preview shown to the user before they
     * confirm a restore, and internally by restore() itself so the two can
     * never disagree about the target.
     *
     * @return array{volume: Volume, folder: VolumeFolder, targetPath: string, hasConflict: bool}|null
     */
    public function previewRestore(int $vaultItemId): ?array
    {
        $record = VaultItemRecord::findOne($vaultItemId);

        if ($record === null) {
            return null;
        }

        return $this->resolveRestoreTarget($vaultItemId, $record);
    }

    /**
     * @return array{volume: Volume, folder: VolumeFolder, targetPath: string, hasConflict: bool}|null
     */
    private function resolveRestoreTarget(int $vaultItemId, VaultItemRecord $record): ?array
    {
        $volume = Craft::$app->getVolumes()->getVolumeById($record->volumeId);

        if ($volume === null) {
            Craft::warning("Can't restore vault item #{$vaultItemId}: its volume no longer exists.", __METHOD__);

            return null;
        }

        $folder = $this->resolveTargetFolder($volume, $record->folderId, $record->folderPath);

        if ($folder === null) {
            Craft::warning("Can't restore vault item #{$vaultItemId}: no target folder could be resolved.", __METHOD__);

            return null;
        }

        $desiredPath = $this->paths->buildPath($folder->path ?? '', $record->filename);
        $targetPath = $this->paths->resolveConflict(
            $folder->path ?? '',
            $record->filename,
            fn(string $path) => $volume->fileExists($path)
        );

        return [
            'volume' => $volume,
            'folder' => $folder,
            'targetPath' => $targetPath,
            'hasConflict' => $targetPath !== $desiredPath,
        ];
    }

    /**
     * Restores a vaulted file as a brand-new asset and removes it from the vault.
     */
    public function restore(int $vaultItemId): ?Asset
    {
        $record = VaultItemRecord::findOne($vaultItemId);

        if ($record === null) {
            return null;
        }

        if ($this->hasEventHandlers(self::EVENT_BEFORE_RESTORE)) {
            $event = new RestoreEvent(['vaultItemId' => $vaultItemId]);
            $this->trigger(self::EVENT_BEFORE_RESTORE, $event);

            if (!$event->isValid) {
                return null;
            }
        }

        $preview = $this->resolveRestoreTarget($vaultItemId, $record);

        if ($preview === null) {
            return null;
        }

        $volume = $preview['volume'];
        $restorePath = $preview['targetPath'];

        try {
            $volume->copyFile($record->vaultPath, $restorePath);
        } catch (\Throwable $e) {
            Craft::warning("Asset Vault couldn't restore \"{$record->vaultPath}\": {$e->getMessage()}", __METHOD__);

            return null;
        }

        // The file already exists at $restorePath inside the volume (we just
        // copied it there), so we go through Craft's indexer rather than a
        // normal upload — that's the supported way to turn an existing file
        // in a volume into an Asset element without re-transferring it.
        $indexer = Craft::$app->getAssetIndexer();
        $session = $indexer->createIndexingSession([$volume], false, true, false);

        if ($session->id === null) {
            Craft::warning("Asset Vault couldn't start an indexing session to restore \"{$restorePath}\".", __METHOD__);

            return null;
        }

        try {
            $asset = $indexer->indexFile($volume, $restorePath, $session->id, false, true);
        } catch (\Throwable $e) {
            Craft::warning("Asset Vault couldn't index the restored file at \"{$restorePath}\": {$e->getMessage()}", __METHOD__);
            $indexer->stopIndexingSession($session);

            return null;
        }

        $indexer->stopIndexingSession($session);

        $asset->title = $record->title;
        $asset->alt = $record->altText;

        if ($record->focalPoint) {
            $focalPoint = json_decode($record->focalPoint, true);

            if (is_array($focalPoint)) {
                $asset->setFocalPoint($focalPoint);
            }
        }

        if ($record->fieldData) {
            $decoded = json_decode($record->fieldData, true);

            if (is_array($decoded) && $decoded !== []) {
                // Matrix maps are keyed by the original nested-entry IDs;
                // remap them (and drop dangling relation IDs) before applying
                // to this brand-new asset.
                $asset->setFieldValues($this->fieldData->prepareForNewOwner($decoded));
            }
        }

        if (!Craft::$app->getElements()->saveElement($asset, false)) {
            Craft::warning(
                "Asset Vault couldn't save the restored asset at \"{$restorePath}\", leaving the vault entry intact.",
                __METHOD__
            );
            Craft::$app->getElements()->deleteElement($asset, true);

            return null;
        }

        $volume->deleteFile($record->vaultPath);
        $record->delete();

        $this->logAudit(
            AuditLogRecord::ACTION_RESTORED,
            $record->filename,
            originalAssetId: $record->originalAssetId,
            restoredAssetId: $asset->id,
            volumeId: $record->volumeId,
            volumeHandle: $record->volumeHandle,
            userId: $this->currentUserId(),
        );

        if ($this->hasEventHandlers(self::EVENT_AFTER_RESTORE)) {
            $this->trigger(self::EVENT_AFTER_RESTORE, new RestoreEvent([
                'vaultItemId' => $vaultItemId,
                'asset' => $asset,
            ]));
        }

        // Named transforms for the restored image are warmed in a queue job
        // so the restore request itself stays fast. Non-images are a no-op
        // inside the job.
        if ($asset->id !== null && $asset->kind === Asset::KIND_IMAGE) {
            Queue::push(new GenerateNamedTransforms([
                'assetId' => (int)$asset->id,
            ]));
        }

        $this->clearMissingOnFsCache();

        return $asset;
    }

    /**
     * Forces generation of every named image transform for an asset by
     * requesting its URL. Returns how many transforms were successfully
     * warmed. Non-images and assets without named transforms return 0.
     */
    public function warmNamedTransforms(Asset $asset): int
    {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            return 0;
        }

        $transforms = Craft::$app->getImageTransforms()->getAllTransforms();

        if ($transforms === []) {
            return 0;
        }

        $warmed = 0;

        foreach ($transforms as $transform) {
            try {
                // getUrl() generates the transform on demand when missing.
                if ($asset->getUrl($transform) !== null) {
                    $warmed++;
                }
            } catch (\Throwable $e) {
                Craft::warning(
                    "Asset Vault couldn't warm transform \"{$transform->handle}\" for asset #{$asset->id}: {$e->getMessage()}",
                    __METHOD__
                );
            }
        }

        return $warmed;
    }

    /**
     * Permanently deletes a vaulted file and its record, with no restore possible afterwards.
     */
    public function deleteForever(int $vaultItemId): bool
    {
        $record = VaultItemRecord::findOne($vaultItemId);

        if ($record === null) {
            return false;
        }

        return $this->purgeRecord($record, AuditLogRecord::ACTION_DELETED_FOREVER, $this->currentUserId());
    }

    /**
     * Empties the entire vault, deleting every file and record in it.
     */
    public function emptyVault(): int
    {
        $count = 0;

        /** @var VaultItemRecord $record */
        foreach (VaultItemRecord::find()->each() as $record) {
            if ($this->deleteForever($record->id)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Called from Craft's garbage collection cycle. Deletes vault items
     * older than $retentionDays. A retention of 0 disables auto-purging.
     */
    public function purgeExpired(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->sub(new DateInterval("P{$retentionDays}D"));
        $count = 0;

        $expired = VaultItemRecord::find()
            ->where(['<', 'dateDeleted', Db::prepareDateForDb($cutoff)])
            ->each();

        /** @var VaultItemRecord $record */
        foreach ($expired as $record) {
            // Purges are attributed to no user, not the current one — this
            // runs from Craft's GC cycle, which can fire from a console
            // request or a queue job with no user attached, and even when it
            // does run from a web request that user didn't choose to delete
            // anything just now, so crediting them would misrepresent the
            // audit trail.
            if ($this->purgeRecord($record, AuditLogRecord::ACTION_PURGED, null)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Drops any prior vault row/file for this asset ID without writing an
     * audit entry — the caller is about to archive a fresh copy and that
     * will be the action that shows up in the log.
     */
    private function discardExistingVaultItemForAsset(int $assetId): void
    {
        /** @var VaultItemRecord|null $existing */
        $existing = VaultItemRecord::findOne(['originalAssetId' => $assetId]);

        if ($existing === null) {
            return;
        }

        $volume = Craft::$app->getVolumes()->getVolumeById($existing->volumeId);

        if ($volume !== null) {
            try {
                $volume->deleteFile($existing->vaultPath);
            } catch (\Throwable $e) {
                Craft::warning(
                    "Asset Vault couldn't remove the previous vault file \"{$existing->vaultPath}\" before re-archiving: {$e->getMessage()}",
                    __METHOD__
                );
            }
        }

        $existing->delete();
    }

    private function resolveTargetFolder(Volume $volume, ?int $originalFolderId, string $originalPath): ?VolumeFolder
    {
        if ($originalFolderId !== null) {
            $folder = Craft::$app->getAssets()->getFolderById($originalFolderId);

            if ($folder !== null && $folder->volumeId === $volume->id) {
                return $folder;
            }
        }

        // Original folder is gone (renamed/deleted since) — fall back to the
        // volume's root folder rather than failing the restore outright.
        if ($volume->id === null) {
            return null;
        }

        return Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
    }

    /**
     * Shared by deleteForever() and purgeExpired(): removes the stored file
     * and the vault row, and logs the outcome under whichever action label
     * the caller is doing this for.
     */
    private function purgeRecord(VaultItemRecord $record, string $action, ?int $userId): bool
    {
        $volume = Craft::$app->getVolumes()->getVolumeById($record->volumeId);

        if ($volume !== null) {
            try {
                $volume->deleteFile($record->vaultPath);
            } catch (\Throwable $e) {
                Craft::warning("Asset Vault couldn't delete \"{$record->vaultPath}\" from storage: {$e->getMessage()}", __METHOD__);
            }
        }

        $filename = $record->filename;
        $originalAssetId = $record->originalAssetId;
        $volumeId = $record->volumeId;
        $volumeHandle = $record->volumeHandle;
        $deleted = (bool)$record->delete();

        if ($deleted) {
            $this->logAudit($action, $filename, originalAssetId: $originalAssetId, volumeId: $volumeId, volumeHandle: $volumeHandle, userId: $userId);
        }

        return $deleted;
    }

    private function logAudit(
        string $action,
        string $filename,
        ?int $originalAssetId = null,
        ?int $restoredAssetId = null,
        ?int $volumeId = null,
        ?string $volumeHandle = null,
        ?int $userId = null,
    ): void {
        $entry = new AuditLogRecord();
        $entry->action = $action;
        $entry->filename = $filename;
        $entry->originalAssetId = $originalAssetId;
        $entry->restoredAssetId = $restoredAssetId;
        $entry->volumeId = $volumeId;
        $entry->volumeHandle = $volumeHandle;
        $entry->userId = $userId;

        // A failed audit write shouldn't fail (or roll back) the archive/
        // restore/delete it's describing — that action already happened.
        if (!$entry->save()) {
            Craft::warning(
                "Asset Vault couldn't write an audit log entry for \"{$action}\" on \"{$filename}\": "
                . implode(', ', $entry->getErrorSummary(true)),
                __METHOD__
            );
        }
    }

    private function currentUserId(): ?int
    {
        try {
            $userId = Craft::$app->getUser()->getId();
        } catch (\Throwable) {
            // No "user" component in this context (e.g. a console request) —
            // there's no current user to attribute the action to.
            return null;
        }

        return $userId !== null ? (int)$userId : null;
    }
}
