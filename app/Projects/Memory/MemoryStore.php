<?php

namespace App\Projects\Memory;

use App\Models\Project;
use InvalidArgumentException;

class MemoryStore
{
    public function __construct(
        private readonly string $root
    ) {
    }

    public static function fromConfig(): self
    {
        $root = config('majordom.memory_root');
        if (!$root) {
            $home = getenv('HOME') ?: sys_get_temp_dir();
            $root = rtrim($home, '/').'/.majordom/projects';
        }
        return new self($root);
    }

    public function pathFor(Project $project): string
    {
        if ($project->memory_path) {
            return $project->memory_path;
        }
        return $this->root.'/'.$project->slug;
    }

    public function write(Project $project, string $relative, string $content): string
    {
        $this->assertSafePath($relative);
        $dir = $this->pathFor($project);
        $path = $dir.'/'.$relative;
        
        $dirPath = dirname($path);
        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0755, true);
        }
        
        file_put_contents($path, $content);
        return $path;
    }

    public function read(Project $project, string $relative): ?string
    {
        $this->assertSafePath($relative);
        $path = $this->pathFor($project).'/'.$relative;
        
        if (!file_exists($path)) {
            return null;
        }
        
        return file_get_contents($path);
    }

    public function exists(Project $project, string $relative): bool
    {
        $this->assertSafePath($relative);
        $path = $this->pathFor($project).'/'.$relative;
        return file_exists($path);
    }

    public function documents(Project $project): array
    {
        $dir = $this->pathFor($project);
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'md') {
                $path = $file->getPathname();
                $relative = str_replace($dir.DIRECTORY_SEPARATOR, '', $path);
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            }
        }

        sort($files);
        return $files;
    }

    private function assertSafePath(string $relative): void
    {
        if (str_starts_with($relative, '/') || str_contains($relative, '..')) {
            throw new InvalidArgumentException('Unsafe memory path.');
        }
    }
}
