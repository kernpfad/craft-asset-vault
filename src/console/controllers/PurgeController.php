<?php

declare(strict_types=1);

namespace kernpfad\assetvault\console\controllers;

use craft\console\Controller;
use kernpfad\assetvault\AssetVault;
use yii\console\ExitCode;

/**
 * `php craft asset-vault/purge` — runs the same retention purge Craft's own
 * garbage collection cycle triggers, on demand (e.g. from a dedicated cron
 * entry that shouldn't wait for GC to run).
 *
 * Defaults to the configured Settings::$retentionDays; --retentionDays
 * overrides it for a single run without touching the saved setting.
 *
 * Example:
 *   php craft asset-vault/purge --retentionDays=14
 */
class PurgeController extends Controller
{
    /** @var int Overrides Settings::$retentionDays for this run. 0 means "use the configured value". */
    public int $retentionDays = 0;

    public function options($actionID): array
    {
        return ['retentionDays'];
    }

    public function actionIndex(): int
    {
        $plugin = AssetVault::getInstance();

        if ($plugin === null) {
            $this->stderr("Asset Vault is not installed.\n");

            return ExitCode::UNAVAILABLE;
        }

        $retentionDays = $this->retentionDays > 0
            ? $this->retentionDays
            : $plugin->getSettings()->retentionDays;

        if ($retentionDays <= 0) {
            $this->stdout("Retention is disabled (0 days) - nothing to purge.\n");

            return ExitCode::OK;
        }

        $count = $plugin->vault->purgeExpired($retentionDays);

        $this->stdout("Purged {$count} vault item(s) older than {$retentionDays} day(s).\n");

        return ExitCode::OK;
    }
}
