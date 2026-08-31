<?php

declare(strict_types=1);

namespace kernpfad\assetvault\events;

use craft\elements\Asset;
use craft\events\CancelableEvent;

/**
 * Fired before and after a vault item is restored as a new asset.
 *
 * On EVENT_BEFORE_RESTORE, $asset is null (nothing has been created yet)
 * and setting $isValid = false aborts the restore - the vault entry stays
 * exactly as it was, nothing is copied or indexed. On EVENT_AFTER_RESTORE,
 * $asset is the newly created, already-saved element; $isValid has no
 * effect there.
 */
class RestoreEvent extends CancelableEvent
{
    public int $vaultItemId;

    public ?Asset $asset = null;
}
