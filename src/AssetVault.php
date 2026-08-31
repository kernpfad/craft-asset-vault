<?php

declare(strict_types=1);

namespace kernpfad\assetvault;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\db\ElementQuery;
use craft\events\CancelableEvent;
use craft\events\DefineBehaviorsEvent;
use craft\events\DeleteElementEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterElementSourcesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use kernpfad\assetvault\elements\actions\ArchiveAssetsAction;
use kernpfad\assetvault\elements\db\AssetQueryBehavior;
use kernpfad\assetvault\models\Settings;
use kernpfad\assetvault\services\FieldDataNormalizer;
use kernpfad\assetvault\services\PathResolver;
use kernpfad\assetvault\services\VaultService;
use yii\base\Event;

/**
 * @property VaultService $vault
 * @method Settings getSettings()
 */
class AssetVault extends Plugin
{
    public string $schemaVersion = '1.1.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();

        $this->set('vault', function() {
            return new VaultService(new PathResolver(), new FieldDataNormalizer());
        });

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'kernpfad\\assetvault\\console\\controllers';
        }

        Event::on(
            Elements::class,
            Elements::EVENT_BEFORE_DELETE_ELEMENT,
            function(DeleteElementEvent $event) {
                if (
                    $event->element instanceof Asset
                    && !$event->hardDelete
                    && !$this->isExcluded($event->element)
                ) {
                    $this->vault->trashAsset($event->element);
                }
            }
        );

        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            function() {
                $this->vault->purgeExpired($this->getSettings()->retentionDays);
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['asset-vault'] = 'asset-vault/vault/index';
                $event->rules['asset-vault/audit-log'] = 'asset-vault/vault/audit-log';
                $event->rules['asset-vault/preview/<id:\d+>'] = 'asset-vault/vault/preview';
                $event->rules['asset-vault/restore/<id:\d+>'] = 'asset-vault/vault/restore';
                $event->rules['asset-vault/delete-forever/<id:\d+>'] = 'asset-vault/vault/delete-forever';
                $event->rules['asset-vault/empty'] = 'asset-vault/vault/empty';
            }
        );

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'Asset Vault',
                    'permissions' => [
                        'assetVault:manage' => [
                            'label' => Craft::t('asset-vault', 'Manage Asset Vault (archive, restore, permanently delete)'),
                        ],
                    ],
                ];
            }
        );

        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_ACTIONS,
            function(RegisterElementActionsEvent $event) {
                if (!Craft::$app->getUser()->checkPermission('assetVault:manage')) {
                    return;
                }

                $event->actions[] = ArchiveAssetsAction::class;
            }
        );

        Event::on(
            AssetQuery::class,
            Query::EVENT_DEFINE_BEHAVIORS,
            function(DefineBehaviorsEvent $event) {
                $event->behaviors['assetVault'] = AssetQueryBehavior::class;
            }
        );

        Event::on(
            AssetQuery::class,
            ElementQuery::EVENT_BEFORE_PREPARE,
            function(CancelableEvent $event) {
                $query = $event->sender;

                if (!$query instanceof AssetQuery) {
                    return;
                }

                $behavior = $query->getBehavior('assetVault');

                if (!$behavior instanceof AssetQueryBehavior) {
                    return;
                }

                if ($behavior->assetVaultVaulted) {
                    $ids = $this->vault->getVaultedAssetIds();
                    $query->id($ids !== [] ? $ids : [0]);
                    // Soft-deleted assets vaulted on delete + live bulk-archives.
                    $query->trashed(null);
                }

                if ($behavior->assetVaultMissingOnFs) {
                    $ids = $this->vault->findMissingOnFsAssetIds();
                    $query->id($ids !== [] ? $ids : [0]);
                }
            }
        );

        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_SOURCES,
            function(RegisterElementSourcesEvent $event) {
                if ($event->context !== 'index') {
                    return;
                }

                // Criteria are flags applied by AssetQueryBehavior — IDs are
                // resolved only when the source is actually queried.
                $event->sources[] = ['heading' => Craft::t('asset-vault', 'Asset Vault')];

                $event->sources[] = [
                    'key' => 'assetvault:vaulted',
                    'label' => Craft::t('asset-vault', 'Vaulted'),
                    'criteria' => [
                        'assetVaultVaulted' => true,
                    ],
                    'defaultSort' => ['dateUpdated', 'desc'],
                ];

                $event->sources[] = [
                    'key' => 'assetvault:missing-on-fs',
                    'label' => Craft::t('asset-vault', 'Missing on filesystem'),
                    'criteria' => [
                        'assetVaultMissingOnFs' => true,
                    ],
                    'defaultSort' => ['filename', 'asc'],
                ];
            }
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('asset-vault', 'Asset Vault');
        $item['subnav'] = [
            'vault' => ['label' => Craft::t('asset-vault', 'Vault'), 'url' => 'asset-vault'],
            'audit-log' => ['label' => Craft::t('asset-vault', 'Audit Log'), 'url' => 'asset-vault/audit-log'],
        ];

        return $item;
    }

    /**
     * Whether an asset's volume is on the excluded list, in which case
     * deletion behaves exactly as it did before this plugin was installed:
     * the file is gone, with no copy kept anywhere.
     *
     * That "no copy kept" guarantee is the whole point of the setting, so
     * getting it wrong is not a cosmetic bug — a volume holding sensitive
     * documents may have been excluded precisely so deletions are final.
     */
    public function isExcluded(Asset $asset): bool
    {
        $excluded = $this->getSettings()->excludedVolumes;

        if ($excluded === []) {
            return false;
        }

        try {
            $handle = $asset->getVolume()->handle;
        } catch (\Throwable) {
            // A missing/misconfigured volume can't be matched against the
            // list; fall back to vaulting, which is the recoverable option.
            return false;
        }

        return in_array($handle, $excluded, true);
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('asset-vault/settings.twig', [
            'settings' => $this->getSettings(),
        ]);
    }
}
