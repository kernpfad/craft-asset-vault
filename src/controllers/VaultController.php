<?php

declare(strict_types=1);

namespace kernpfad\assetvault\controllers;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use kernpfad\assetvault\AssetVault;
use kernpfad\assetvault\records\AuditLogRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

class VaultController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePermission('assetVault:manage');

        return parent::beforeAction($action);
    }

    /**
     * The plugin instance is always present while one of its own
     * controllers is running, but `getInstance()` is nullable — resolving
     * it in one place keeps that assertion out of every action.
     */
    private function plugin(): AssetVault
    {
        $plugin = AssetVault::getInstance();

        if ($plugin === null) {
            throw new ServerErrorHttpException('The Asset Vault plugin is not installed.');
        }

        return $plugin;
    }

    public function actionIndex(): Response
    {
        $retentionDays = $this->plugin()->getSettings()->retentionDays;

        $items = $this->plugin()->vault->getAllItems();

        return $this->renderTemplate('asset-vault/index.twig', [
            'items' => $items,
            'retentionDays' => $retentionDays,
        ]);
    }

    public function actionAuditLog(): Response
    {
        $entries = $this->plugin()->vault->getAuditLog();

        $userIds = array_values(array_unique(array_filter(array_map(
            static fn(AuditLogRecord $entry): ?int => $entry->userId,
            $entries
        ))));

        $users = $userIds !== []
            ? User::find()->id($userIds)->status(null)->indexBy('id')->all()
            : [];

        return $this->renderTemplate('asset-vault/audit-log.twig', [
            'entries' => $entries,
            'users' => $users,
        ]);
    }

    /**
     * Shows where a restore would land, and whether it would collide with a
     * file already at the original location, before anything actually happens.
     */
    public function actionPreview(int $id): Response
    {
        $item = $this->plugin()->vault->getItem($id);

        if ($item === null) {
            throw new NotFoundHttpException('Vault item not found.');
        }

        return $this->renderTemplate('asset-vault/preview.twig', [
            'item' => $item,
            'preview' => $this->plugin()->vault->previewRestore($id),
        ]);
    }

    public function actionRestore(int $id): Response
    {
        $this->requirePostRequest();

        $asset = $this->plugin()->vault->restore($id);

        if ($asset === null) {
            $this->setFailFlash(Craft::t('asset-vault', "Couldn't restore that file."));

            return $this->redirectToPostedUrl();
        }

        $this->setSuccessFlash(Craft::t('asset-vault', '{filename} restored.', [
            'filename' => $asset->getFilename(),
        ]));

        return $this->redirectToPostedUrl();
    }

    public function actionDeleteForever(int $id): Response
    {
        $this->requirePostRequest();

        $deleted = $this->plugin()->vault->deleteForever($id);

        if ($deleted) {
            $this->setSuccessFlash(Craft::t('asset-vault', 'File permanently deleted.'));
        } else {
            $this->setFailFlash(Craft::t('asset-vault', "Couldn't delete that file."));
        }

        return $this->redirectToPostedUrl();
    }

    public function actionEmpty(): Response
    {
        $this->requirePostRequest();

        $count = $this->plugin()->vault->emptyVault();

        $this->setSuccessFlash(Craft::t('asset-vault', '{count} file(s) permanently deleted.', [
            'count' => $count,
        ]));

        return $this->redirectToPostedUrl();
    }
}
