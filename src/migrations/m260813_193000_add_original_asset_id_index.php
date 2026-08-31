<?php

declare(strict_types=1);

namespace kernpfad\assetvault\migrations;

use craft\db\Migration;

/**
 * Speeds up "is this asset already vaulted?" lookups used by bulk archive
 * and the Vaulted Asset Index source.
 */
class m260813_193000_add_original_asset_id_index extends Migration
{
    public function safeUp(): bool
    {
        $this->createIndex(null, '{{%assetvault_items}}', ['originalAssetId']);

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
