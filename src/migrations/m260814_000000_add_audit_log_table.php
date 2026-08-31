<?php

declare(strict_types=1);

namespace kernpfad\assetvault\migrations;

use craft\db\Migration;

/**
 * Creates {{%assetvault_audit_log}} for installs that already had Asset
 * Vault before the audit log was added. That table only ever existed in
 * Install.php, which Craft runs solely on a brand-new install -- an
 * upgrading site never gets it, and every archive/restore/delete-forever/
 * purge call crashes with an uncaught "table does not exist" exception the
 * moment it tries to write an audit entry (VaultService::logAudit() calls
 * AuditLogRecord::save(), which throws on a missing table rather than
 * returning false -- there's no validation to fail, so the try/warn path
 * around it never triggers). Confirmed live: deleting any asset in the CP
 * on an upgraded install fatals instead of completing the delete.
 */
class m260814_000000_add_audit_log_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%assetvault_audit_log}}')) {
            return true;
        }

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

        return true;
    }
}
