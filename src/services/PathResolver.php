<?php

declare(strict_types=1);

namespace kernpfad\assetvault\services;

/**
 * Pure, framework-agnostic path/filename logic shared by the vault service.
 *
 * Kept free of Craft/Yii dependencies on purpose: this is the part of the
 * plugin most likely to survive a future port to a different framework
 * (e.g. if Craft's filesystem abstraction changes), so it's isolated from
 * the Craft-coupled glue code in VaultService.
 */
class PathResolver
{
    /**
     * Builds a collision-proof storage path inside the vault for a file
     * that's about to be trashed.
     */
    public function vaultPath(int $volumeId, string $uid, string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $suffix = $extension !== '' ? ".{$extension}" : '';

        return sprintf('.vault/%d/%s%s', $volumeId, $uid, $suffix);
    }

    /**
     * Joins a folder path and filename the way volumes expect: no leading
     * slash, a single slash between the two. Exposed publicly so callers can
     * compute the *desired* restore path — before conflict resolution might
     * rename it — for things like dry-run previews.
     */
    public function buildPath(string $folderPath, string $filename): string
    {
        $folderPath = trim($folderPath, '/');

        return $folderPath === '' ? $filename : "{$folderPath}/{$filename}";
    }

    /**
     * Given a desired restore path and a closure that reports whether a
     * path is already taken, returns a free path — appending "_restored",
     * then "_restored-2", "_restored-3", ... until one is free.
     *
     * @param callable(string): bool $exists
     */
    public function resolveConflict(string $folderPath, string $filename, callable $exists): string
    {
        $candidate = $this->buildPath($folderPath, $filename);

        if (!$exists($candidate)) {
            return $candidate;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $extSuffix = $extension !== '' ? ".{$extension}" : '';

        $attempt = 1;
        do {
            $attempt++;
            $newFilename = $attempt === 2
                ? "{$basename}_restored{$extSuffix}"
                : "{$basename}_restored-{$attempt}{$extSuffix}";
            $candidate = $this->buildPath($folderPath, $newFilename);
        } while ($exists($candidate));

        return $candidate;
    }
}
