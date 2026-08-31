# Asset Vault

A recycle bin for Craft CMS assets. Deleted files are moved to a vault and can be restored from the control panel, instead of being erased immediately.

Craft gives entries, categories and users a trashed status you can restore from. Assets are the exception: deleting one removes the underlying file straight away, with no way back through the control panel. Asset Vault closes that gap.

## Requirements

- Craft CMS 5.0.0+
- PHP 8.2+

## Installation

```sh
composer require kernpfad/craft-asset-vault
php craft plugin/install asset-vault
```

## What it does

- Copies a deleted file into a `.vault/` path inside its own volume before Craft removes it, through Craft's filesystem abstraction (`Volume::copyFile()` / `deleteFile()`). Local and remote volumes — S3, DigitalOcean Spaces — behave the same way.
- Stores the asset's title, alt text, focal point and serialized custom field values alongside the file, so a restore recreates the asset as it was rather than just the raw file.
- Adds an **Asset Vault** control panel section to restore or permanently delete individual files, or empty the vault.
- Shows a preview before a restore is confirmed — the exact path the file will be restored to, and a warning if that would collide with a file already there (in which case the restored file is renamed, never overwritten).
- Bulk **Archive to Vault** on the Assets index copies selected files into the vault *without* deleting them (queue job with progress). Useful before risky edits; re-archiving replaces the previous vault copy for that asset.
- Extra Asset Index sources: **Vaulted** and **Missing on filesystem**.
- Keeps an audit log of who archived, restored, or permanently deleted each file, and when — including automatic purges, attributed to the system rather than whichever user happened to trigger garbage collection.
- After a restore, named image transforms are warmed in the background so restored images aren't cold on first request.
- Purges vault items past their retention period during Craft's garbage collection.
- `php craft asset-vault/purge` runs that same purge on demand — useful for a dedicated cron entry that shouldn't wait for GC. Pass `--retentionDays=N` to override the configured retention for a single run.

## Settings

Under **Settings → Plugins → Asset Vault**:

| Setting | Default | Description |
|---|---|---|
| `retentionDays` | `30` | Days a deleted file stays in the vault before automatic purging. `0` keeps files forever. |
| `excludedVolumes` | none | Volume handles whose deletions skip the vault entirely — the file is gone immediately, as it would be without this plugin. For volumes whose contents must not linger. |

## Permissions

The **Manage Asset Vault (archive, restore, permanently delete)** permission controls:

- the Assets Index **Archive to Vault** bulk action
- restoring and permanently deleting items in the Asset Vault CP section

Assignable per user group.

## Events

`kernpfad\assetvault\services\VaultService` fires four events other plugins can hook into:

| Event | Fires | Cancelable |
|---|---|---|
| `EVENT_BEFORE_ARCHIVE` | Before an asset is copied into the vault (on delete *or* bulk archive) | Yes — setting `$isValid = false` skips vaulting it. On delete, the asset is still removed by Craft; this only controls whether a recoverable copy is made first. |
| `EVENT_AFTER_ARCHIVE` | After the copy has been made | No |
| `EVENT_BEFORE_RESTORE` | Before a vault item is restored | Yes — setting `$isValid = false` aborts the restore; the vault entry is left exactly as it was. |
| `EVENT_AFTER_RESTORE` | After the vault item has been restored as a new asset | No |

## Limitations

- Filename conflicts on restore are resolved by appending `_restored`, `_restored-2` and so on. Nothing is overwritten, but the restored asset may carry a different name than the one deleted.
- If the original folder was deleted after the asset, a restore falls back to the volume root rather than failing.
- Only soft deletes are intercepted. Hard deletes that explicitly bypass the trash are not, matching how Craft treats every other element type.
- Custom field round-tripping covers plain text, relation fields (e.g. Assets), and Matrix: nested entries are recreated on restore (not reused by ID), and relation targets that no longer exist are dropped.
- Bulk **Archive to Vault** leaves the live asset in place. Restoring that vault copy while the original still exists creates a second asset (with conflict renaming if needed) — it does not replace the live one.
- The **Missing on filesystem** source scans volumes only when that source is opened (results cached ~60s), not on every Assets Index load.
- This is not an offsite backup. Vault copies live in the same volume as the originals (under `.vault/`). Use real backups / object-storage versioning for disaster recovery.

## License

Licensed under the [Craft License Agreement](LICENSE.md).

[Legal notice](https://kernpfad.dev/en/legal-notice) · [Privacy policy](https://kernpfad.dev/en/privacy-policy) · [Terms and conditions](https://kernpfad.dev/en/terms-and-conditions)
