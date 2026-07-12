<?php

use App\Models\Project;
use App\Projects\Memory\MemoryStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tempRoot = sys_get_temp_dir().'/majordom-mem-'.uniqid();
    $this->store = new MemoryStore($this->tempRoot);
});

afterEach(function () {
    if (is_dir($this->tempRoot)) {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tempRoot);
    }
});

test('write creates nested dirs and returns absolute path, read round-trips', function () {
    $project = Project::factory()->create();
    $relative = 'tasks/1/task.md';
    $content = '# Task 1';
    
    $path = $this->store->write($project, $relative, $content);
    
    expect($path)->toBe($this->tempRoot.'/'.$project->slug.'/'.$relative);
    expect($this->store->read($project, $relative))->toBe($content);
});

test('pathFor uses slug under root by default and memory_path override when set', function () {
    $project1 = Project::factory()->create();
    expect($this->store->pathFor($project1))->toBe($this->tempRoot.'/'.$project1->slug);
    
    $customPath = '/custom/memory/path';
    $project2 = Project::factory()->create(['memory_path' => $customPath]);
    expect($this->store->pathFor($project2))->toBe($customPath);
});

test('read returns null for a missing file', function () {
    $project = Project::factory()->create();
    expect($this->store->read($project, 'nonexistent.md'))->toBeNull();
});

test('write with ../escape.md throws InvalidArgumentException', function () {
    $project = Project::factory()->create();
    $this->store->write($project, '../escape.md', 'content');
})->throws(InvalidArgumentException::class, 'Unsafe memory path.');

test('write with /absolute.md throws InvalidArgumentException', function () {
    $project = Project::factory()->create();
    $this->store->write($project, '/absolute.md', 'content');
})->throws(InvalidArgumentException::class, 'Unsafe memory path.');

test('documents lists nested md files sorted and ignores non-md files', function () {
    $project = Project::factory()->create();
    $this->store->write($project, 'z.md', 'z');
    $this->store->write($project, 'a.md', 'a');
    $this->store->write($project, 'tasks/1/task.md', 'task');
    $this->store->write($project, 'tasks/1/notes.txt', 'txt');
    
    $docs = $this->store->documents($project);
    
    expect($docs)->toBe(['a.md', 'tasks/1/task.md', 'z.md']);
});
