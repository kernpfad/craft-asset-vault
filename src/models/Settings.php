<?php

declare(strict_types=1);

namespace kernpfad\assetvault\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * Days a deleted asset stays in the vault before it's purged automatically
     * during Craft's garbage collection. 0 disables auto-purging entirely.
     */
    public int $retentionDays = 30;

    /**
     * Volume handles to exclude from vaulting (files in these volumes are
     * deleted immediately, as before this plugin was installed).
     *
     * @var string[]
     */
    public array $excludedVolumes = [];

    /**
     * @return array<int, array<array-key, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['retentionDays'], 'integer', 'min' => 0],
            [['excludedVolumes'], 'each', 'rule' => ['string']],
        ];
    }
}
