<?php

namespace App\Projects\Repositories;

use Illuminate\Support\Facades\Process;

/**
 * Grounding: a cheap, real listing of the repository's tracked files so
 * prompt-consumers (Architect decompose, Reviewer) reason about paths that
 * actually exist instead of inventing plausible ones (e2e #2, Fix #1).
 * Read-only, capped, and best-effort — a missing/bare repo yields null and
 * callers degrade gracefully.
 */
class RepoIndex
{
    public const MAX_FILES = 400;

    public function fileList(?string $repoPath, int $maxFiles = self::MAX_FILES): ?string
    {
        if ($repoPath === null || trim($repoPath) === '' || ! is_dir($repoPath)) {
            return null;
        }

        $result = Process::path($repoPath)->run(['git', 'ls-tree', '-r', '--name-only', 'HEAD']);
        if (! $result->successful()) {
            return null; // unborn HEAD / not a repo — no grounding available
        }

        $files = array_values(array_filter(array_map('trim', explode("\n", $result->output()))));
        if ($files === []) {
            return null;
        }

        $total = count($files);
        $shown = array_slice($files, 0, $maxFiles);
        $listing = implode("\n", $shown);

        return $total > $maxFiles
            ? $listing."\n… (+".($total - $maxFiles).' more tracked files)'
            : $listing;
    }

    /**
     * Read a single file's contents, confined to the repository directory
     * (M14a/T-66 Architect self-inspection). realpath resolution blocks `..`
     * traversal and symlinks pointing outside the repo. Tracked-only
     * enforcement is the caller's job (membership against fileList) so
     * gitignored secrets like .env never reach a model; this method only
     * guarantees the path stays inside the repo and is capped.
     */
    public function readFile(?string $repoPath, string $rel, int $maxBytes = 4000): ?string
    {
        if ($repoPath === null || trim($repoPath) === '' || ! is_dir($repoPath)) {
            return null;
        }

        $root = realpath($repoPath);
        $target = realpath($repoPath.DIRECTORY_SEPARATOR.ltrim($rel, '/'));
        if ($root === false || $target === false) {
            return null;
        }
        // Must resolve to a real file INSIDE the repo root.
        if ($target !== $root && ! str_starts_with($target, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }
        if (! is_file($target)) {
            return null;
        }

        $contents = @file_get_contents($target, false, null, 0, $maxBytes + 1);
        if ($contents === false) {
            return null;
        }

        return mb_strlen($contents) > $maxBytes
            ? mb_substr($contents, 0, $maxBytes)."\n… (truncated)"
            : $contents;
    }
}
