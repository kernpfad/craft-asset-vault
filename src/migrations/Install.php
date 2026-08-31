<?php

declare(strict_types=1);

namespace kernpfad\assetvault\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%assetvault_items}}', [
            'id' => $this->primaryKey(),
            'originalAssetId' => $this->integer()->null(),
            'volumeId' => $this->integer()->notNull(),
            'volumeHandle' => $this->string()->notNull(),
            'folderId' => $this->integer()->null(),
            'folderPath' => $this->string()->notNull()->defaultValue(''),
            'filename' => $this->string()->notNull(),
            'vaultPath' => $this->string()->notNull(),
            'kind' => $this->string(50)->null(),
            'size' => $this->bigInteger()->unsigned()->null(),
            'width' => $this->integer()->unsigned()->null(),
            'height' => $this->integer()->unsigned()->null(),
            'title' => $this->string()->null(),
            'altText' => $this->text()->null(),
            'focalPoint' => $this->string()->null(),
            'fieldData' => $this->text()->null(),
            'deletedByUserId' => $this->integer()->null(),
            'dateDeleted' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%assetvault_items}}', ['volumeId']);
        // Retention is computed from dateDeleted at purge time (see
        // VaultService::purgeExpired()), so that's what needs the index.
        $this->createIndex(null, '{{%assetvault_items}}', ['dateDeleted']);
        // Bulk-archive / "Vaulted" Asset Index source look up by original asset.
        $this->createIndex(null, '{{%assetvault_items}}', ['originalAssetId']);

        $this->addForeignKey(
            null,
            '{{%assetvault_items}}',
            ['deletedByUserId'],
            '{{%users}}',
            ['id'],
            'SET NULL'
        );

        $this->createTable('{{%assetvault_audit_log}}', [
            'id' => $this->primaryKey(),
            'action' => $this->string(20)->notNull(),
            'originalAssetId' => $this->integer()->null(),
            'restoredAssetId' => $this->integer()->null(),
            'volumeId' => $this->integer()->null(),
            'volumeHandle' => $this->string()->null(),
            'filename' => $this->string()->notNull(),
            'userId' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // The audit log page lists newest-first, so that's what needs the index.
        $this->createIndex(null, '{{%assetvault_audit_log}}', ['dateCreated']);

        $this->addForeignKey(
            null,
            '{{%assetvault_audit_log}}',
            ['userId'],
            '{{%users}}',
            ['id'],
            'SET NULL'
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%assetvault_audit_log}}');
        $this->dropTableIfExists('{{%assetvault_items}}');

        return true;
    }
}
