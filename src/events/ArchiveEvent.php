<?php

declare(strict_types=1);

namespace kernpfad\assetvault\events;

use craft\elements\Asset;
use craft\events\CancelableEvent;

/**
 * Fired before and after an asset is copied into the vault.
 *
 * On EVENT_BEFORE_ARCHIVE, setting $isValid = false skips vaulting this
 * asset (it still gets deleted for real by whatever triggered the
 * archive — this only controls whether a recoverable copy is made first).
 * $isValid has no effect on EVENT_AFTER_ARCHIVE, since the copy already
 * happened by then.
 */
class ArchiveEvent extends CancelableEvent
{
    public Asset $asset;
}
