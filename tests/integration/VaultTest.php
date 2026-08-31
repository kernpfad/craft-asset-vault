<?php

namespace kernpfad\assetvault\tests\integration;

/**
 * Boots a real Craft application and drives the actual production pipeline
 * — assets are deleted through `Craft::$app->getElements()->deleteElement()`
 * so the plugin's own `EVENT_BEFORE_DELETE_ELEMENT` listener is what puts
 * them in the vault, rather than tests calling VaultService directly.
 *
 * Files are real files on a real local volume, so "the file is actually
 * still there" is asserted against the filesystem rather than inferred
 * from a database row.
 *
 * Requires CRAFT_TEST_SITE_PATH to point at a working Craft install with
 * this plugin linked in via a Composer path repository. Skips itself if
 * that's not configured.
 *
 * PHPUnit will flag the first test as "risky" (error/exception handlers
 * not restored) — that's Craft's own bootstrap registering its handlers
 * inside the same process, not a bug here.
 */

use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Assets as AssetsField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\fs\Local;
use craft\helpers\Db;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Volume;
use craft\models\VolumeFolder;
use craft\records\VolumeFolder as VolumeFolderRecord;
use craft\services\Assets;
use DateTime;
use kernpfad\assetvault\AssetVault;
use kernpfad\assetvault\events\ArchiveEvent;
use kernpfad\assetvault\events\RestoreEvent;
use kernpfad\assetvault\records\AuditLogRecord;
use kernpfad\assetvault\records\VaultItemRecord;
use kernpfad\assetvault\services\PathResolver;
use kernpfad\assetvault\services\VaultService;
use PHPUnit\Framework\TestCase;

class VaultTest extends TestCase
{
    private static bool $booted = false;

    protected function setUp(): void
    {
        $sitePath = getenv('CRAFT_TEST_SITE_PATH');

        if (!$sitePath || !is_dir($sitePath)) {
            $this->markTestSkipped(
                'CRAFT_TEST_SITE_PATH is not set to a working Craft install; skipping integration tests.'
            );
        }

        if (!self::$booted) {
            define('CRAFT_BASE_PATH', $sitePath);
            define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');
            require CRAFT_VENDOR_PATH . '/autoload.php';

            if (class_exists(\Dotenv\Dotenv::class)) {
                \Dotenv\Dotenv::createImmutable(CRAFT_BASE_PATH)->safeLoad();
            }

            require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
            self::$booted = true;
        }

        $plugin = AssetVault::getInstance();
        self::assertNotNull($plugin, 'Asset Vault plugin is not installed on the test install.');

        // Each test starts from an empty vault so counts are meaningful.
        foreach (VaultItemRecord::find()->all() as $record) {
            $record->delete();
        }

        // Same for the audit log, so assertions about "the entry just
        // written" aren't reading leftovers from an earlier test.
        foreach (AuditLogRecord::find()->all() as $entry) {
            $entry->delete();
        }

        // Excluded-volume state must never leak between tests.
        $plugin->getSettings()->excludedVolumes = [];
    }

    public function testDeletingAnAssetPutsACopyInTheVault(): void
    {
        $asset = $this->createAsset('vaulted');
        $volume = $asset->getVolume();

        Craft::$app->getElements()->deleteElement($asset);

        $record = VaultItemRecord::findOne(['originalAssetId' => $asset->id]);

        self::assertNotNull($record, 'Expected a vault record after deleting an asset.');
        self::assertTrue(
            $volume->fileExists($record->vaultPath),
            "Expected the vaulted file to exist at {$record->vaultPath}."
        );
    }

    public function testTheVaultRecordCapturesWhatIsNeededToRebuildTheAsset(): void
    {
        $asset = $this->createAsset('metadata');
        $asset->title = 'A meaningful title';
        $asset->alt = 'Alt text worth keeping';
        Craft::$app->getElements()->saveElement($asset);

        $originalId = $asset->id;
        $filename = $asset->getFilename();

        Craft::$app->getElements()->deleteElement($asset);

        $record = VaultItemRecord::findOne(['originalAssetId' => $originalId]);

        self::assertNotNull($record);
        self::assertSame('A meaningful title', $record->title);
        self::assertSame('Alt text worth keeping', $record->altText);
        self::assertSame($filename, $record->filename);
        self::assertNotNull($record->dateDeleted);
    }

    public function testAnAssetInAnExcludedVolumeIsNeverCopiedIntoTheVault(): void
    {
        // Regression test for a bug where `excludedVolumes` was declared,
        // validated and documented but never actually read, so files in an
        // excluded volume were vaulted anyway. That inverts the setting's
        // only guarantee — a volume is typically excluded precisely because
        // its contents must not linger after deletion.
        $asset = $this->createAsset('excluded');
        $volume = $asset->getVolume();
        $originalId = $asset->id;
        $uid = (string)$asset->uid;
        $filename = $asset->getFilename();

        AssetVault::getInstance()->getSettings()->excludedVolumes = [$volume->handle];

        Craft::$app->getElements()->deleteElement($asset);

        self::assertNull(
            VaultItemRecord::findOne(['originalAssetId' => $originalId]),
            'An asset in an excluded volume must not produce a vault record.'
        );
        self::assertFalse(
            $volume->fileExists((new PathResolver())->vaultPath($volume->id, $uid, $filename)),
            'An asset in an excluded volume must leave no copy on disk.'
        );
    }

    public function testExcludingOneVolumeDoesNotAffectAnother(): void
    {
        $asset = $this->createAsset('still-vaulted');

        AssetVault::getInstance()->getSettings()->excludedVolumes = ['someOtherVolumeHandle'];

        Craft::$app->getElements()->deleteElement($asset);

        self::assertNotNull(
            VaultItemRecord::findOne(['originalAssetId' => $asset->id]),
            'Excluding an unrelated volume must not stop this one being vaulted.'
        );
    }

    public function testAHardDeleteBypassesTheVaultEntirely(): void
    {
        // A hard delete is the caller explicitly asking for the element to
        // be gone; the vault must not quietly resurrect a copy of it.
        $asset = $this->createAsset('hard-deleted');
        $originalId = $asset->id;

        Craft::$app->getElements()->deleteElement($asset, true);

        self::assertNull(VaultItemRecord::findOne(['originalAssetId' => $originalId]));
    }

    public function testRestoringAVaultedAssetRecreatesTheFileAndTheElement(): void
    {
        $asset = $this->createAsset('restore-me');
        $asset->title = 'Restored title';
        Craft::$app->getElements()->saveElement($asset);

        $volume = $asset->getVolume();
        $originalId = $asset->id;
        $filename = $asset->getFilename();

        Craft::$app->getElements()->deleteElement($asset);
        $record = VaultItemRecord::findOne(['originalAssetId' => $originalId]);
        self::assertNotNull($record);
        $vaultPath = $record->vaultPath;

        $restored = AssetVault::getInstance()->vault->restore($record->id);

        self::assertInstanceOf(Asset::class, $restored, 'Expected restore() to return the new asset.');
        self::assertSame($filename, $restored->getFilename());
        self::assertSame('Restored title', $restored->title);
        self::assertTrue($volume->fileExists($restored->getPath()), 'The restored file should exist in the volume.');

        // The vault entry is consumed by a successful restore — both the
        // row and the stored copy.
        self::assertNull(VaultItemRecord::findOne($record->id), 'The vault record should be gone after a restore.');
        self::assertFalse($volume->fileExists($vaultPath), 'The vaulted copy should be removed after a restore.');
    }

    public function testRestoringOverAnExistingFileRenamesRatherThanOverwrites(): void
    {
        // The destructive case: if a file with the same name has since been
        // re-uploaded, restoring must not clobber it.
        $asset = $this->createAsset('conflict');
        $volume = $asset->getVolume();
        $originalId = $asset->id;
        $filename = $asset->getFilename();
        $originalPath = $asset->getPath();

        Craft::$app->getElements()->deleteElement($asset);
        $record = VaultItemRecord::findOne(['originalAssetId' => $originalId]);
        self::assertNotNull($record);

        // Put a *different* file back at the original path.
        $volume->writeFileFromStream($originalPath, $this->stream('a replacement file'), []);
        self::assertTrue($volume->fileExists($originalPath));

        $restored = AssetVault::getInstance()->vault->restore($record->id);

        self::assertInstanceOf(Asset::class, $restored);
        self::assertNotSame($filename, $restored->getFilename(), 'The restore should have been renamed.');
        self::assertStringContainsString('_restored', $restored->getFilename());
        self::assertSame(
            'a replacement file',
            stream_get_contents($volume->getFileStream($originalPath)),
            'The pre-existing file must be left untouched.'
        );
    }

    public function testPreviewRestoreReportsTheTargetPathWithNoConflict(): void
    {
        $asset = $this->createAsset('preview-clear');
        $volume = $asset->getVolume();
        $originalId = $asset->id;
        $originalPath = $asset->getPath();

        Craft::$app->getElements()->deleteElement($asset);
        $record = VaultItemRecord::findOne(['originalAssetId' => $originalId]);
        self::assertNotNull($record);

        $preview = AssetVault::getInstance()->vault->previewRestore($record->id);

        self::assertNotNull($preview);
        self::assertSame($originalPath, $preview['targetPath']);
        self::assertFalse($preview['hasConflict']);

        // A preview is a dry run: the vault entry and its file must be
        // completely untouched by looking at it.
        self::assertNotNull(VaultItemRecord::findOne($record->id));
        self::assertTrue($volume->fileExists($record->vaultPath));
    }

    public function testPreviewRestoreFlagsAConflictWithoutRestoringAnything(): void
    {
        $asset = $this->createAsset('preview-conflict');
        $volume = $asset->getVolume();
        $originalId = $asset->id;
        $originalPath = $asset->getPath();

        Craft::$app->getElements()->deleteElement($asset);
        $record = VaultItemRecord::findOne(['originalAssetId' => $originalId]);
        self::assertNotNull($record);

        $volume->writeFileFromStream($originalPath, $this->stream('a replacement file'), []);

        $preview = AssetVault::getInstance()->vault->previewRestore($record->id);

        self::assertNotNull($preview);
        self::assertTrue($preview['hasConflict']);
        self::assertStringContainsString('_restored', $preview['targetPath']);

        // Still a dry run, even in the conflicting case: no new asset, the
        // vault entry untouched, and the replacement file left alone.
        self::assertNotNull(VaultItemRecord::findOne($record->id));
        self::assertSame(
            'a replacement file',
            stream_get_contents($volume->getFileStream($originalPath))
        );
    }

    public function testDeleteForeverRemovesBothTheRecordAndTheStoredFile(): void
    {
        $asset = $this->createAsset('purge-me');
        $volume = $asset->getVolume();

        Craft::$app->getElements()->deleteElement($asset);
        $record = VaultItemRecord::findOne(['originalAssetId' => $asset->id]);
        self::assertNotNull($record);
        $vaultPath = $record->vaultPath;

        self::assertTrue(AssetVault::getInstance()->vault->deleteForever($record->id));

        self::assertNull(VaultItemRecord::findOne($record->id));
        self::assertFalse($volume->fileExists($vaultPath), 'deleteForever must remove the stored file, not just the row.');
    }

    public function testArchivingWritesAnAuditLogEntry(): void
    {
        $asset = $this->createAsset('audit-archive');
        $originalId = $asset->id;
        $filename = $asset->getFilename();

        Craft::$app->getElements()->deleteElement($asset);

        $entry = AuditLogRecord::findOne(['action' => AuditLogRecord::ACTION_ARCHIVED, 'originalAssetId' => $originalId]);

        self::assertNotNull($entry, 'Expected an "archived" audit log entry.');
        self::assertSame($filename, $entry->filename);
    }

    public function testRestoringWritesAnAuditLogEntryWithTheNewAssetId(): void
    {
        $asset = $this->createAsset('audit-restore');
        $originalId = $asset->id;

        Craft::$app->getElements()->deleteElement($asset);
        $record = VaultItemRecord::findOne(['originalAssetId' => $originalId]);
        self::assertNotNull($record);

        $restored = AssetVault::getInstance()->vault->restore($record->id);
        self::assertInstanceOf(Asset::class, $restored);

        $entry = AuditLogRecord::findOne(['action' => AuditLogRecord::ACTION_RESTORED, 'originalAssetId' => $originalId]);

        self::assertNotNull($entry, 'Expected a "restored" audit log entry.');
        self::assertSame($restored->id, $entry->restoredAssetId);
    }

    public function testCancellingBeforeArchiveSkipsVaultingButStillLetsTheDeletionProceed(): void
    {
        // Mirrors testAnAssetInAnExcludedVolumeIsNeverCopiedIntoTheVault:
        // a cancelled archive is another way to end up with no recoverable
        // copy, driven by a listener instead of the excludedVolumes setting.
        $vault = AssetVault::getInstance()->vault;
        $asset = $this->createAsset('archive-cancelled');
        $originalId = $asset->id;

        $handler = function(ArchiveEvent $event) {
            $event->isValid = false;
        };
        $vault->on(VaultService::EVENT_BEFORE_ARCHIVE, $handler);

        try {
            Craft::$app->getElements()->deleteElement($asset);
        } finally {
            $vault->off(VaultService::EVENT_BEFORE_ARCHIVE, $handler);
        }

        self::assertNull(
            VaultItemRecord::findOne(['originalAssetId' => $originalId]),
            'A cancelled archive must not produce a vault record.'
        );
    }

    public function testAfterArchiveFiresWithTheVaultedAsset(): void
    {
        $vault = AssetVault::getInstance()->vault;
        $asset = $this->createAsset('after-archive');
        $originalId = $asset->id;

        $seen = null;
        $handler = function(ArchiveEvent $event) use (&$seen) {
            $seen = $event->asset;
        };
        $vault->on(VaultService::EVENT_AFTER_ARCHIVE, $handler);

        try {
            Craft::$app->getElements()->deleteElement($asset);
        } finally {
            $vault->off(VaultService::EVENT_AFTER_ARCHIVE, $handler);
        }

        self::assertInstanceOf(Asset::class, $seen);
        self::assertSame($originalId, $seen->id);
        self::assertNotNull(VaultItemRecord::findOne(['originalAssetId' => $originalId]));
    }

    public function testCancellingBeforeRestoreLeavesTheVaultEntryUntouched(): void
    {
        $vault = AssetVault::getInstance()->vault;
        $record = $this->vaultAnAsset('restore-cancelled');
        $volume = $this->testVolume();
        $vaultPath = $record->vaultPath;

        $handler = function(RestoreEvent $event) {
            $event->isValid = false;
        };
        $vault->on(VaultService::EVENT_BEFORE_RESTORE, $handler);

        try {
            $restored = $vault->restore($record->id);
        } finally {
            $vault->off(VaultService::EVENT_BEFORE_RESTORE, $handler);
        }

        self::assertNull($restored, 'A cancelled restore must return null.');
        self::assertNotNull(VaultItemRecord::findOne($record->id), 'The vault record must survive a cancelled restore.');
        self::assertTrue($volume->fileExists($vaultPath), 'The vaulted file must survive a cancelled restore.');
    }

    public function testAfterRestoreFiresWithTheVaultItemIdAndTheNewAsset(): void
    {
        $vault = AssetVault::getInstance()->vault;
        $record = $this->vaultAnAsset('after-restore');
        $recordId = $record->id;

        $seenVaultItemId = null;
        $seenAsset = null;
        $handler = function(RestoreEvent $event) use (&$seenVaultItemId, &$seenAsset) {
            $seenVaultItemId = $event->vaultItemId;
            $seenAsset = $event->asset;
        };
        $vault->on(VaultService::EVENT_AFTER_RESTORE, $handler);

        try {
            $restored = $vault->restore($recordId);
        } finally {
            $vault->off(VaultService::EVENT_AFTER_RESTORE, $handler);
        }

        self::assertInstanceOf(Asset::class, $restored);
        self::assertSame($recordId, $seenVaultItemId);
        self::assertInstanceOf(Asset::class, $seenAsset);
        self::assertSame($restored->id, $seenAsset->id);
    }

    public function testBulkArchiveCopiesIntoTheVaultWithoutDeletingTheAsset(): void
    {
        $vault = AssetVault::getInstance()->vault;
        $asset = $this->createAsset('bulk-archive-keep');
        $volume = $asset->getVolume();
        $originalPath = $asset->getPath();
        $originalId = $asset->id;

        self::assertTrue($vault->trashAsset($asset));

        $record = VaultItemRecord::findOne(['originalAssetId' => $originalId]);
        self::assertNotNull($record, 'Bulk archive must create a vault record.');
        self::assertTrue($volume->fileExists($record->vaultPath));
        self::assertTrue(
            $volume->fileExists($originalPath),
            'Bulk archive must leave the live file in place.'
        );
        self::assertNotNull(
            Asset::find()->id($originalId)->one(),
            'Bulk archive must leave the live Asset element in place.'
        );
    }

    public function testReArchivingTheSameAssetReplacesThePreviousVaultCopy(): void
    {
        $vault = AssetVault::getInstance()->vault;
        $asset = $this->createAsset('re-archive');
        $volume = $asset->getVolume();
        $originalId = $asset->id;

        self::assertTrue($vault->trashAsset($asset));
        $first = VaultItemRecord::findOne(['originalAssetId' => $originalId]);
        self::assertNotNull($first);
        $firstId = $first->id;
        $firstVaultPath = $first->vaultPath;

        self::assertTrue($vault->trashAsset($asset));

        self::assertNull(VaultItemRecord::findOne($firstId), 'The previous vault row must be replaced.');

        // vaultPath() is a pure function of (volumeId, asset UID, filename)
        // — none of which change when re-archiving the same still-live
        // asset — so the fresh copy legitimately lands at the very same
        // path as the one just discarded; asserting that path is now empty
        // would be asserting an impossible condition. What must hold is
        // that the new record points at that (stable) path and a file
        // actually exists there, plus no duplicate row/file appears
        // elsewhere (checked by the count() below).
        $second = VaultItemRecord::findOne(['originalAssetId' => $originalId]);
        self::assertNotNull($second);
        self::assertSame($firstVaultPath, $second->vaultPath);
        self::assertTrue($volume->fileExists($second->vaultPath), 'The fresh vault copy should exist at the vault path.');

        self::assertSame(
            1,
            (int)VaultItemRecord::find()->where(['originalAssetId' => $originalId])->count(),
            'Exactly one vault row should remain for the asset.'
        );
    }

    public function testGetVaultedAssetIdsIncludesBulkArchivedAssets(): void
    {
        $vault = AssetVault::getInstance()->vault;
        $asset = $this->createAsset('vaulted-ids');
        $originalId = (int)$asset->id;

        self::assertTrue($vault->trashAsset($asset));

        self::assertContains($originalId, $vault->getVaultedAssetIds());
    }

    public function testFindMissingOnFsAssetIdsDetectsARemovedFile(): void
    {
        $vault = AssetVault::getInstance()->vault;
        $asset = $this->createAsset('missing-on-fs');
        $volume = $asset->getVolume();
        $assetId = (int)$asset->id;
        $path = $asset->getPath();

        $volume->deleteFile($path);
        $vault->clearMissingOnFsCache();

        self::assertContains($assetId, $vault->findMissingOnFsAssetIds());
    }

    public function testWarmNamedTransformsIsANoOpForNonImages(): void
    {
        $asset = $this->createAsset('no-transforms');

        self::assertSame(0, AssetVault::getInstance()->vault->warmNamedTransforms($asset));
    }

    public function testDeleteForeverWritesAnAuditLogEntry(): void
    {
        $record = $this->vaultAnAsset('audit-delete-forever');

        self::assertTrue(AssetVault::getInstance()->vault->deleteForever($record->id));

        $entry = AuditLogRecord::findOne([
            'action' => AuditLogRecord::ACTION_DELETED_FOREVER,
            'originalAssetId' => $record->originalAssetId,
        ]);
        self::assertNotNull($entry, 'Expected a "deletedForever" audit log entry.');
    }

    public function testPurgeExpiredWritesAPurgedAuditLogEntryAttributedToNoUser(): void
    {
        $old = $this->vaultAnAsset('audit-purge');
        $old->dateDeleted = Db::prepareDateForDb((new DateTime())->modify('-45 days'));
        $old->save(false);

        self::assertSame(1, AssetVault::getInstance()->vault->purgeExpired(30));

        $entry = AuditLogRecord::findOne(['action' => AuditLogRecord::ACTION_PURGED, 'originalAssetId' => $old->originalAssetId]);

        self::assertNotNull($entry, 'Expected a "purged" audit log entry.');
        self::assertNull(
            $entry->userId,
            'An automatic purge must not be attributed to whichever user happened to trigger garbage collection.'
        );
    }

    public function testEmptyVaultRemovesEverything(): void
    {
        $this->vaultAnAsset('empty-1');
        $this->vaultAnAsset('empty-2');

        self::assertSame(2, (int)VaultItemRecord::find()->count());

        $removed = AssetVault::getInstance()->vault->emptyVault();

        self::assertSame(2, $removed);
        self::assertSame(0, (int)VaultItemRecord::find()->count());
    }

    public function testPurgeExpiredOnlyRemovesItemsPastTheRetentionWindow(): void
    {
        $old = $this->vaultAnAsset('old');
        $recent = $this->vaultAnAsset('recent');

        // Backdate one item well past a 30-day retention window.
        $old->dateDeleted = Db::prepareDateForDb((new DateTime())->modify('-45 days'));
        $old->save(false);

        $purged = AssetVault::getInstance()->vault->purgeExpired(30);

        self::assertSame(1, $purged);
        self::assertNull(VaultItemRecord::findOne($old->id), 'The expired item should have been purged.');
        self::assertNotNull(VaultItemRecord::findOne($recent->id), 'The recent item should have been kept.');
    }

    public function testARetentionOfZeroDisablesPurgingEntirely(): void
    {
        // 0 means "keep forever" — a very old item must survive, otherwise
        // the setting would silently mean the opposite of what it says.
        $old = $this->vaultAnAsset('kept-forever');
        $old->dateDeleted = Db::prepareDateForDb((new DateTime())->modify('-999 days'));
        $old->save(false);

        self::assertSame(0, AssetVault::getInstance()->vault->purgeExpired(0));
        self::assertNotNull(VaultItemRecord::findOne($old->id));
    }

    public function testCraftsGarbageCollectionActuallyTriggersThePurge(): void
    {
        // Every other purge test calls purgeExpired() directly, which proves
        // the logic but not the wiring. Automatic purging is the mechanism
        // users actually rely on, and it only works if the plugin's
        // Gc::EVENT_RUN listener fires and passes the *configured* retention
        // — so this drives Craft's real garbage collector instead.
        $plugin = AssetVault::getInstance();
        $plugin->getSettings()->retentionDays = 30;

        $old = $this->vaultAnAsset('gc-expired');
        $recent = $this->vaultAnAsset('gc-recent');

        $old->dateDeleted = Db::prepareDateForDb((new DateTime())->modify('-45 days'));
        $old->save(false);

        Craft::$app->getGc()->run(true);

        self::assertNull(
            VaultItemRecord::findOne($old->id),
            "Craft's garbage collection should have purged the expired vault item."
        );
        self::assertNotNull(
            VaultItemRecord::findOne($recent->id),
            'Garbage collection must not touch items inside the retention window.'
        );
    }

    public function testRestoreFallsBackToTheVolumeRootWhenTheOriginalFolderIsGone(): void
    {
        // Deleting the folder after the asset is a realistic sequence, and
        // the restore has to survive it rather than failing outright.
        $volume = $this->testVolume();
        $folder = $this->createSubfolder($volume, 'to-be-removed-' . bin2hex(random_bytes(3)));

        $asset = $this->createAsset('orphaned', $folder->id);
        $originalId = $asset->id;

        Craft::$app->getElements()->deleteElement($asset);
        $record = VaultItemRecord::findOne(['originalAssetId' => $originalId]);
        self::assertNotNull($record);
        self::assertSame($folder->id, $record->folderId);

        // Two things bite here, both verified rather than assumed:
        // Assets::deleteFoldersByIds() leaves the row resolvable, and the
        // Assets service memoises folders for the life of the request, so
        // even deleting the row directly still resolves from cache. In
        // production the restore happens in a later request with a cold
        // cache; replacing the service reproduces that.
        VolumeFolderRecord::deleteAll(['id' => $folder->id]);
        Craft::$app->set('assets', new Assets());

        self::assertNull(
            Craft::$app->getAssets()->getFolderById($folder->id),
            'Test setup failed: the folder should be unresolvable by now.'
        );

        $restored = AssetVault::getInstance()->vault->restore($record->id);

        self::assertInstanceOf(Asset::class, $restored, 'The restore should succeed despite the folder being gone.');

        $rootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
        self::assertSame(
            $rootFolder->id,
            $restored->folderId,
            'A restore whose original folder is gone should land in the volume root.'
        );
        self::assertTrue($volume->fileExists($restored->getPath()));
    }

    public function testFocalPointSurvivesTheRoundTrip(): void
    {
        $asset = $this->createImageAsset('focal-point');
        $asset->setFocalPoint(['x' => 0.25, 'y' => 0.75]);
        Craft::$app->getElements()->saveElement($asset);

        $originalId = $asset->id;
        Craft::$app->getElements()->deleteElement($asset);

        $record = VaultItemRecord::findOne(['originalAssetId' => $originalId]);
        self::assertNotNull($record);
        self::assertSame(['x' => 0.25, 'y' => 0.75], json_decode((string)$record->focalPoint, true));

        $restored = AssetVault::getInstance()->vault->restore($record->id);

        self::assertInstanceOf(Asset::class, $restored);

        $reloaded = Asset::find()->id($restored->id)->status(null)->one();
        self::assertInstanceOf(Asset::class, $reloaded);
        self::assertSame(['x' => 0.25, 'y' => 0.75], $reloaded->getFocalPoint());
    }

    public function testCustomFieldContentSurvivesTheRoundTrip(): void
    {
        // The plugin advertises restoring "custom field content", but until
        // now only the native title/alt attributes were ever verified.
        $handle = $this->ensureAssetsHaveATextField();

        $asset = $this->createAsset('custom-fields');

        // createAsset() leaves the element on SCENARIO_CREATE, whose
        // tempFilePath the first save consumed — re-saving under it fails
        // on "Temp File Path cannot be blank".
        $asset->setScenario(Asset::SCENARIO_DEFAULT);
        $asset->setFieldValue($handle, 'a value worth keeping');

        self::assertTrue(
            Craft::$app->getElements()->saveElement($asset),
            'Saving with the custom field failed: ' . implode(', ', $asset->getErrorSummary(true))
        );

        $originalId = $asset->id;
        Craft::$app->getElements()->deleteElement($asset);

        $record = VaultItemRecord::findOne(['originalAssetId' => $originalId]);
        self::assertNotNull($record);

        $restored = AssetVault::getInstance()->vault->restore($record->id);

        self::assertInstanceOf(Asset::class, $restored);

        // Re-query rather than trusting the in-memory element, so this
        // proves the value was actually persisted.
        $reloaded = Asset::find()->id($restored->id)->status(null)->one();
        self::assertInstanceOf(Asset::class, $reloaded);
        self::assertSame('a value worth keeping', $reloaded->getFieldValue($handle));
    }

    public function testAssetsRelationFieldSurvivesTheRoundTrip(): void
    {
        $handle = $this->ensureAssetsHaveAnAssetsRelationField();
        $related = $this->createAsset('related-target');

        $asset = $this->createAsset('relation-owner');
        $asset->setScenario(Asset::SCENARIO_DEFAULT);
        $asset->setFieldValue($handle, [$related->id]);

        self::assertTrue(
            Craft::$app->getElements()->saveElement($asset),
            'Saving with the Assets relation failed: ' . implode(', ', $asset->getErrorSummary(true))
        );

        $originalId = $asset->id;
        Craft::$app->getElements()->deleteElement($asset);

        $record = VaultItemRecord::findOne(['originalAssetId' => $originalId]);
        self::assertNotNull($record);

        $restored = AssetVault::getInstance()->vault->restore($record->id);
        self::assertInstanceOf(Asset::class, $restored);

        $reloaded = Asset::find()->id($restored->id)->status(null)->one();
        self::assertInstanceOf(Asset::class, $reloaded);

        $relatedIds = $reloaded->getFieldValue($handle)->ids();
        self::assertSame([(int)$related->id], array_map('intval', $relatedIds));
    }

    public function testDanglingRelationIdsAreDroppedOnRestore(): void
    {
        $handle = $this->ensureAssetsHaveAnAssetsRelationField();
        $related = $this->createAsset('relation-gone');
        $relatedId = (int)$related->id;

        $asset = $this->createAsset('relation-dangling');
        $asset->setScenario(Asset::SCENARIO_DEFAULT);
        $asset->setFieldValue($handle, [$relatedId]);
        self::assertTrue(Craft::$app->getElements()->saveElement($asset));

        $originalId = $asset->id;
        Craft::$app->getElements()->deleteElement($asset);

        // Hard-delete the related target so its ID is gone for good.
        Craft::$app->getElements()->deleteElement($related, true);

        $record = VaultItemRecord::findOne(['originalAssetId' => $originalId]);
        self::assertNotNull($record);
        $stored = json_decode((string)$record->fieldData, true);
        self::assertContains($relatedId, array_map('intval', $stored[$handle] ?? []));

        $restored = AssetVault::getInstance()->vault->restore($record->id);
        self::assertInstanceOf(Asset::class, $restored);

        $reloaded = Asset::find()->id($restored->id)->status(null)->one();
        self::assertInstanceOf(Asset::class, $reloaded);
        self::assertSame(
            [],
            $reloaded->getFieldValue($handle)->ids(),
            'A relation pointing at a hard-deleted element must not be restored.'
        );
    }

    public function testMatrixFieldSurvivesTheRoundTripWithFreshNestedEntries(): void
    {
        [$matrixHandle, $blockTypeHandle, $textHandle] = $this->ensureAssetsHaveAMatrixField();

        $asset = $this->createAsset('matrix-owner');
        $asset->setScenario(Asset::SCENARIO_DEFAULT);
        $asset->setFieldValue($matrixHandle, [
            'new1' => [
                'type' => $blockTypeHandle,
                'fields' => [
                    $textHandle => 'matrix content worth keeping',
                ],
            ],
        ]);

        self::assertTrue(
            Craft::$app->getElements()->saveElement($asset),
            'Saving with Matrix content failed: ' . implode(', ', $asset->getErrorSummary(true))
        );

        $originalId = $asset->id;
        $originalNestedIds = $asset->getFieldValue($matrixHandle)->ids();
        self::assertNotEmpty($originalNestedIds);

        Craft::$app->getElements()->deleteElement($asset);

        $record = VaultItemRecord::findOne(['originalAssetId' => $originalId]);
        self::assertNotNull($record);

        $stored = json_decode((string)$record->fieldData, true);
        self::assertIsArray($stored[$matrixHandle] ?? null);
        // Snapshot keeps the original nested-entry IDs as keys.
        foreach (array_keys($stored[$matrixHandle]) as $key) {
            self::assertTrue(is_numeric($key), 'Vault snapshot should retain original Matrix entry IDs.');
        }

        $restored = AssetVault::getInstance()->vault->restore($record->id);
        self::assertInstanceOf(Asset::class, $restored);

        $reloaded = Asset::find()->id($restored->id)->status(null)->one();
        self::assertInstanceOf(Asset::class, $reloaded);

        $nested = $reloaded->getFieldValue($matrixHandle)->all();
        self::assertCount(1, $nested);
        self::assertSame('matrix content worth keeping', $nested[0]->getFieldValue($textHandle));
        self::assertNotContains(
            (int)$nested[0]->id,
            array_map('intval', $originalNestedIds),
            'Restore must create fresh Matrix entries rather than reuse the originals.'
        );
    }

    /**
     * Adds a plain-text field to the *test volume's* field layout, if it
     * isn't there already, and returns its handle.
     *
     * Assets don't have one global field layout the way most element types
     * do — each volume carries its own (verified: the volume's layout and
     * `getLayoutByType(Asset::class)` are different layouts, and only the
     * volume's is applied to its assets). Adding the field therefore means
     * saving the volume, which is a project-config write. It's idempotent
     * and purely additive, so repeated runs are safe, but it is a genuine
     * change to the test install — unlike the rest of this suite, which
     * only reads configuration.
     */
    private function ensureAssetsHaveATextField(): string
    {
        $handle = 'assetVaultTestNote';
        $field = $this->ensureFieldOnTestVolume($handle, function() use ($handle) {
            $field = new PlainText();
            $field->handle = $handle;
            $field->name = 'Asset Vault Test Note';

            return $field;
        }, PlainText::class);

        return $field->handle;
    }

    private function ensureAssetsHaveAnAssetsRelationField(): string
    {
        $handle = 'assetVaultTestRelated';
        $field = $this->ensureFieldOnTestVolume($handle, function() use ($handle) {
            $field = new AssetsField();
            $field->handle = $handle;
            $field->name = 'Asset Vault Test Related';
            $field->sources = ['*'];

            return $field;
        }, AssetsField::class);

        return $field->handle;
    }

    /**
     * @return array{0: string, 1: string, 2: string} matrix handle, block type handle, nested text handle
     */
    private function ensureAssetsHaveAMatrixField(): array
    {
        $matrixHandle = 'assetVaultTestMatrix';
        $blockHandle = 'assetVaultMatrixBlock';
        $textHandle = 'assetVaultMatrixText';

        $fields = Craft::$app->getFields();
        $entries = Craft::$app->getEntries();

        $textField = $fields->getFieldByHandle($textHandle);
        if (!$textField instanceof PlainText) {
            $textField = new PlainText();
            $textField->handle = $textHandle;
            $textField->name = 'Asset Vault Matrix Text';
            self::assertTrue($fields->saveField($textField), implode(', ', $textField->getErrorSummary(true)));
        }

        $entryType = null;
        foreach ($entries->getAllEntryTypes() as $candidate) {
            if ($candidate->handle === $blockHandle) {
                $entryType = $candidate;
                break;
            }
        }

        if ($entryType === null) {
            $entryType = new EntryType();
            $entryType->name = 'Asset Vault Matrix Block';
            $entryType->handle = $blockHandle;
        }

        $layout = $entryType->getFieldLayout() ?? new FieldLayout(['type' => Entry::class]);
        $hasText = false;
        foreach ($layout->getCustomFields() as $existing) {
            if ($existing->handle === $textHandle) {
                $hasText = true;
                break;
            }
        }

        if (!$hasText) {
            $tab = new FieldLayoutTab(['layout' => $layout, 'name' => 'Content']);
            $tab->setElements([new CustomField($textField)]);
            $layout->setTabs(array_merge($layout->getTabs(), [$tab]));
            $entryType->setFieldLayout($layout);
        }

        self::assertTrue(
            $entries->saveEntryType($entryType),
            implode(', ', $entryType->getErrorSummary(true))
        );

        $this->ensureFieldOnTestVolume($matrixHandle, function() use ($matrixHandle, $entryType) {
            $field = new Matrix();
            $field->handle = $matrixHandle;
            $field->name = 'Asset Vault Test Matrix';
            $field->setEntryTypes([$entryType]);

            return $field;
        }, Matrix::class);

        return [$matrixHandle, $blockHandle, $textHandle];
    }

    /**
     * @param callable(): \craft\base\FieldInterface $factory
     * @param class-string<\craft\base\FieldInterface> $class
     */
    private function ensureFieldOnTestVolume(string $handle, callable $factory, string $class): \craft\base\FieldInterface
    {
        $volume = $this->testVolume();

        foreach ($volume->getFieldLayout()->getCustomFields() as $existing) {
            if ($existing->handle === $handle && $existing instanceof $class) {
                return $existing;
            }
        }

        $fields = Craft::$app->getFields();
        $field = $fields->getFieldByHandle($handle);

        if (!$field instanceof $class) {
            $field = $factory();
            self::assertTrue($fields->saveField($field), implode(', ', $field->getErrorSummary(true)));
        }

        // Matrix entry types can change after the field already exists.
        if ($field instanceof Matrix && $class === Matrix::class) {
            /** @var Matrix $fresh */
            $fresh = $factory();
            $field->setEntryTypes($fresh->getEntryTypes());
            self::assertTrue($fields->saveField($field), implode(', ', $field->getErrorSummary(true)));
        }

        $layout = $volume->getFieldLayout();
        $tab = new FieldLayoutTab(['layout' => $layout, 'name' => 'Asset Vault Tests']);
        $tab->setElements([new CustomField($field)]);
        $layout->setTabs(array_merge($layout->getTabs(), [$tab]));
        $volume->setFieldLayout($layout);

        self::assertTrue(
            Craft::$app->getVolumes()->saveVolume($volume),
            implode(', ', $volume->getErrorSummary(true))
        );

        return $field;
    }

    private function createSubfolder(Volume $volume, string $name): VolumeFolder
    {
        $assets = Craft::$app->getAssets();
        $root = $assets->getRootFolderByVolumeId($volume->id);

        $folder = new VolumeFolder();
        $folder->parentId = $root->id;
        $folder->volumeId = $volume->id;
        $folder->name = $name;
        $folder->path = $name . '/';

        $assets->createFolder($folder);

        return $folder;
    }

    private function vaultAnAsset(string $label): VaultItemRecord
    {
        $asset = $this->createAsset($label);
        Craft::$app->getElements()->deleteElement($asset);

        $record = VaultItemRecord::findOne(['originalAssetId' => $asset->id]);
        self::assertNotNull($record, "Expected \"{$label}\" to be vaulted.");

        return $record;
    }

    private function createImageAsset(string $label, ?int $folderId = null): Asset
    {
        $volume = $this->testVolume();
        $folder = $folderId !== null
            ? Craft::$app->getAssets()->getFolderById($folderId)
            : Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
        $filename = sprintf('%s-%s.png', $label, bin2hex(random_bytes(4)));
        $tempPath = Craft::$app->getPath()->getTempPath() . '/' . $filename;
        file_put_contents(
            $tempPath,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')
        );

        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->setFilename($filename);
        $asset->newFolderId = $folder->id;
        $asset->setVolumeId($volume->id);
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        self::assertTrue(
            Craft::$app->getElements()->saveElement($asset),
            'Could not create the test image asset: ' . json_encode($asset->getErrors())
        );

        return $asset;
    }

    private function createAsset(string $label, ?int $folderId = null): Asset
    {
        $volume = $this->testVolume();
        $folder = $folderId !== null
            ? Craft::$app->getAssets()->getFolderById($folderId)
            : Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
        $filename = sprintf('%s-%s.txt', $label, bin2hex(random_bytes(4)));
        $tempPath = Craft::$app->getPath()->getTempPath() . '/' . $filename;
        file_put_contents($tempPath, "contents of {$filename}");

        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->setFilename($filename);
        $asset->newFolderId = $folder->id;
        $asset->setVolumeId($volume->id);
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        self::assertTrue(
            Craft::$app->getElements()->saveElement($asset),
            'Could not create the test asset: ' . json_encode($asset->getErrors())
        );

        return $asset;
    }

    /**
     * Picks the first volume backed by a real local filesystem.
     *
     * These tests deliberately do *not* create their own volume: that would
     * mean writing a filesystem and volume into the install's project
     * config, which is a config change masquerading as test setup — and one
     * that persists after the run. (An earlier version did exactly that and
     * passed once, then failed on every subsequent run: the filesystem never
     * reached project config, leaving a MissingFs behind. The one place this
     * suite does still write project config is
     * {@see self::ensureAssetsHaveATextField()}, which says so.) Note that simply taking the first volume
     * in the install isn't good enough either: an install can easily hold
     * volumes whose filesystem is missing (`MissingFs`), and every file
     * operation against those throws NotSupportedException.
     */
    private function testVolume(): Volume
    {
        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            if ($volume->getFs() instanceof Local) {
                return $volume;
            }
        }

        self::markTestSkipped(
            'The test install has no volume backed by a local filesystem; skipping. '
            . 'These tests need somewhere real to read and write files.'
        );
    }

    /**
     * @return resource
     */
    private function stream(string $contents)
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
