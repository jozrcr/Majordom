<?php

use App\Agents\Harness\AiderHarness;
use App\Agents\Harness\HarnessRequest;
use App\Agents\Harness\HarnessStatus;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/majordom-test-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    mkdir($this->tempDir . '/.git', 0777, true);
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($this->tempDir);
    }
});

it('runs happy path and returns completed', function () {
    Process::fake([
        'git rev-parse HEAD' => Process::result(output: "abc123\n"),
        'git diff abc123 HEAD' => Process::result(output: "diff --git a/app/File.php b/app/File.php\nnew file"),
        'git diff HEAD' => Process::result(output: ""),
        'git diff --name-only abc123 HEAD' => Process::result(output: "app/File.php\n"),
        'git diff --name-only HEAD' => Process::result(output: ""),
        'aider --model openai/test-model --yes-always --no-check-update --analytics-disable --no-show-model-warnings --no-stream --message-file' => Process::result(output: "aider output", exitCode: 0),
    ]);

    $harness = new AiderHarness('aider', 1800);
    $request = new HarnessRequest(
        repoPath: $this->tempDir,
        endpointBaseUrl: 'http://127.0.0.1:8010/ollama/v1',
        modelName: 'test-model',
        rolePrompt: 'You are a coder',
        taskPrompt: 'Fix the bug',
    );

    $result = $harness->runTask($request);

    expect($result->status)->toBe(HarnessStatus::Completed)
        ->and($result->diff)->toContain('new file')
        ->and($result->filesChanged)->toBe(['app/File.php'])
        ->and($result->testsPassed)->toBeNull()
        ->and($result->summary)->toBe('1 file(s) changed.');

    Process::assertRan(fn ($process) => 
        $process->environment['OPENAI_API_BASE'] === 'http://127.0.0.1:8010/ollama/v1' &&
        in_array('--model', $process->command) &&
        in_array('openai/test-model', $process->command) &&
        in_array('--message-file', $process->command)
    );
});

it('runs test command and passes', function () {
    Process::fake([
        'git rev-parse HEAD' => Process::result(output: "abc123\n"),
        'git diff abc123 HEAD' => Process::result(output: "diff --git a/app/File.php b/app/File.php\nnew file"),
        'git diff HEAD' => Process::result(output: ""),
        'git diff --name-only abc123 HEAD' => Process::result(output: "app/File.php\n"),
        'git diff --name-only HEAD' => Process::result(output: ""),
        'aider --model openai/test-model --yes-always --no-check-update --analytics-disable --no-show-model-warnings --no-stream --message-file' => Process::result(output: "aider output", exitCode: 0),
        'php artisan test' => Process::result(output: "Tests passed", exitCode: 0),
    ]);

    $harness = new AiderHarness('aider', 1800);
    $request = new HarnessRequest(
        repoPath: $this->tempDir,
        endpointBaseUrl: 'http://127.0.0.1:8010/ollama/v1',
        modelName: 'test-model',
        rolePrompt: 'You are a coder',
        taskPrompt: 'Fix the bug',
        testCommand: 'php artisan test',
    );

    $result = $harness->runTask($request);

    expect($result->status)->toBe(HarnessStatus::Completed)
        ->and($result->testsPassed)->toBeTrue();
        
    Process::assertRan(fn ($process) => 
        in_array('--test-cmd', $process->command) &&
        in_array('php artisan test', $process->command)
    );
});

it('fails when tests fail', function () {
    Process::fake([
        'git rev-parse HEAD' => Process::result(output: "abc123\n"),
        'git diff abc123 HEAD' => Process::result(output: "diff --git a/app/File.php b/app/File.php\nnew file"),
        'git diff HEAD' => Process::result(output: ""),
        'git diff --name-only abc123 HEAD' => Process::result(output: "app/File.php\n"),
        'git diff --name-only HEAD' => Process::result(output: ""),
        'aider --model openai/test-model --yes-always --no-check-update --analytics-disable --no-show-model-warnings --no-stream --message-file' => Process::result(output: "aider output", exitCode: 0),
        'php artisan test' => Process::result(output: "Tests failed", exitCode: 1),
    ]);

    $harness = new AiderHarness('aider', 1800);
    $request = new HarnessRequest(
        repoPath: $this->tempDir,
        endpointBaseUrl: 'http://127.0.0.1:8010/ollama/v1',
        modelName: 'test-model',
        rolePrompt: 'You are a coder',
        taskPrompt: 'Fix the bug',
        testCommand: 'php artisan test',
    );

    $result = $harness->runTask($request);

    expect($result->status)->toBe(HarnessStatus::Failed)
        ->and($result->summary)->toBe('Changes produced but tests fail.')
        ->and($result->testsPassed)->toBeFalse();
});

it('fails when aider exits with non-zero', function () {
    Process::fake([
        'git rev-parse HEAD' => Process::result(output: "abc123\n"),
        'aider --model openai/test-model --yes-always --no-check-update --analytics-disable --no-show-model-warnings --no-stream --message-file' => Process::result(output: "error", exitCode: 1),
    ]);

    $harness = new AiderHarness('aider', 1800);
    $request = new HarnessRequest(
        repoPath: $this->tempDir,
        endpointBaseUrl: 'http://127.0.0.1:8010/ollama/v1',
        modelName: 'test-model',
        rolePrompt: 'You are a coder',
        taskPrompt: 'Fix the bug',
    );

    $result = $harness->runTask($request);

    expect($result->status)->toBe(HarnessStatus::Failed)
        ->and($result->summary)->toBe('aider exited with code 1.');
});

it('fails when diff is empty', function () {
    Process::fake([
        'git rev-parse HEAD' => Process::result(output: "abc123\n"),
        'git diff abc123 HEAD' => Process::result(output: ""),
        'git diff HEAD' => Process::result(output: ""),
        'git diff --name-only abc123 HEAD' => Process::result(output: ""),
        'git diff --name-only HEAD' => Process::result(output: ""),
        'aider --model openai/test-model --yes-always --no-check-update --analytics-disable --no-show-model-warnings --no-stream --message-file' => Process::result(output: "aider output", exitCode: 0),
    ]);

    $harness = new AiderHarness('aider', 1800);
    $request = new HarnessRequest(
        repoPath: $this->tempDir,
        endpointBaseUrl: 'http://127.0.0.1:8010/ollama/v1',
        modelName: 'test-model',
        rolePrompt: 'You are a coder',
        taskPrompt: 'Fix the bug',
    );

    $result = $harness->runTask($request);

    expect($result->status)->toBe(HarnessStatus::Failed)
        ->and($result->summary)->toBe('No changes were produced.');
});

it('fails when path is not a git repository', function () {
    Process::fake();
    $nonGitDir = sys_get_temp_dir() . '/majordom-nogit-' . uniqid();
    mkdir($nonGitDir, 0777, true);

    $harness = new AiderHarness('aider', 1800);
    $request = new HarnessRequest(
        repoPath: $nonGitDir,
        endpointBaseUrl: 'http://127.0.0.1:8010/ollama/v1',
        modelName: 'test-model',
        rolePrompt: 'You are a coder',
        taskPrompt: 'Fix the bug',
    );

    $result = $harness->runTask($request);

    expect($result->status)->toBe(HarnessStatus::Failed)
        ->and($result->summary)->toBe('Not a git repository.');
        
    Process::assertNothingRan();
    
    rmdir($nonGitDir);
});

it('includes file hints as trailing args', function () {
    Process::fake([
        'git rev-parse HEAD' => Process::result(output: "abc123\n"),
        'git diff abc123 HEAD' => Process::result(output: "diff"),
        'git diff HEAD' => Process::result(output: ""),
        'git diff --name-only abc123 HEAD' => Process::result(output: "app/File.php\n"),
        'git diff --name-only HEAD' => Process::result(output: ""),
        'aider --model openai/test-model --yes-always --no-check-update --analytics-disable --no-show-model-warnings --no-stream --message-file' => Process::result(output: "aider output", exitCode: 0),
    ]);

    $harness = new AiderHarness('aider', 1800);
    $request = new HarnessRequest(
        repoPath: $this->tempDir,
        endpointBaseUrl: 'http://127.0.0.1:8010/ollama/v1',
        modelName: 'test-model',
        rolePrompt: 'You are a coder',
        taskPrompt: 'Fix the bug',
        fileHints: ['app/Model.php', 'config/app.php'],
    );

    $result = $harness->runTask($request);

    Process::assertRan(fn ($process) => 
        in_array('app/Model.php', $process->command) &&
        in_array('config/app.php', $process->command)
    );
});
