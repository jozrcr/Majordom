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
}
