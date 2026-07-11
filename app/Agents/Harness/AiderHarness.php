<?php

namespace App\Agents\Harness;

use Illuminate\Support\Facades\Process;
use Illuminate\Process\Exceptions\ProcessTimedOutException;

class AiderHarness implements Harness
{
    public function __construct(
        private readonly string $aiderBin,
        private readonly int $defaultTimeout
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            config('majordom.harness.aider_bin'),
            config('majordom.harness.timeout')
        );
    }

    public function runTask(HarnessRequest $request): HarnessResult
    {
        $repoPath = $request->repoPath;
        $timeout = $request->timeoutSeconds ?? $this->defaultTimeout;
        $instructionFile = null;

        // 1. Guard
        if (!is_dir($repoPath) || !file_exists($repoPath . '/.git')) {
            return new HarnessResult(
                status: HarnessStatus::Failed,
                diff: '',
                filesChanged: [],
                testsPassed: null,
                summary: 'Not a git repository.',
                openQuestions: [],
                rawLog: ''
            );
        }

        // 2. Resolve HEAD. A fresh `git init` repo has none — aider will make
        // the first commit; we then diff against git's empty-tree object.
        $beforeResult = Process::path($repoPath)->run(['git', 'rev-parse', 'HEAD']);
        $before = $beforeResult->successful() ? trim($beforeResult->output()) : null;

        // 3. Write instruction file
        $instructionFile = sys_get_temp_dir() . '/majordom-task-' . uniqid() . '.md';
        file_put_contents($instructionFile, $request->rolePrompt . "\n\n---\n\n" . $request->taskPrompt);

        try {
            // 4. Build command
            $command = [
                $this->aiderBin,
                '--model', 'openai/' . $request->modelName,
                '--yes-always',
                '--no-check-update',
                '--analytics-disable',
                '--no-show-model-warnings',
                '--no-stream',
                '--message-file', $instructionFile,
            ];

            if ($request->testCommand) {
                $command[] = '--test-cmd';
                $command[] = $request->testCommand;
                $command[] = '--auto-test';
            }

            foreach ($request->fileHints as $hint) {
                $command[] = $hint;
            }

            // 5. Run
            try {
                $result = Process::path($repoPath)
                    ->env(['OPENAI_API_BASE' => $request->endpointBaseUrl, 'OPENAI_API_KEY' => 'majordom'])
                    ->timeout($timeout)
                    ->run($command);
            } catch (ProcessTimedOutException $e) {
                $partialOutput = '';
                if (method_exists($e, 'result') && $e->result()) {
                    $partialOutput = $e->result()->output() . "\n" . $e->result()->errorOutput();
                }
                return new HarnessResult(
                    status: HarnessStatus::Failed,
                    diff: '',
                    filesChanged: [],
                    testsPassed: null,
                    summary: "Harness timed out after {$timeout}s.",
                    openQuestions: [],
                    rawLog: $partialOutput
                );
            }

            // 6. Raw log
            $rawLog = $result->output() . "\n" . $result->errorOutput();

            // 9a. Check aider exit code
            if ($result->exitCode() !== 0) {
                return new HarnessResult(
                    status: HarnessStatus::Failed,
                    diff: '',
                    filesChanged: [],
                    testsPassed: null,
                    summary: "aider exited with code {$result->exitCode()}.",
                    openQuestions: [],
                    rawLog: $rawLog
                );
            }

            // 7. Diff & filesChanged. With no pre-run HEAD, diff from the
            // canonical empty-tree object (works in every git repo).
            $base = $before ?? '4b825dc642cb6eb9a060e54bf8d69288fbee4904';
            $afterResult = Process::path($repoPath)->run(['git', 'rev-parse', 'HEAD']);
            $hasHead = $afterResult->successful();

            $diffCommitted = $hasHead ? trim(Process::path($repoPath)->run(["git", "diff", $base, "HEAD"])->output()) : '';
            $diffUncommitted = $hasHead ? trim(Process::path($repoPath)->run(["git", "diff", "HEAD"])->output()) : '';
            $diff = $diffCommitted . ($diffUncommitted ? "\n" . $diffUncommitted : '');

            $namesCommitted = $hasHead ? array_filter(array_map('trim', explode("\n", Process::path($repoPath)->run(["git", "diff", "--name-only", $base, "HEAD"])->output()))) : [];
            $namesUncommitted = $hasHead ? array_filter(array_map('trim', explode("\n", Process::path($repoPath)->run(["git", "diff", "--name-only", "HEAD"])->output()))) : [];
            $filesChanged = array_values(array_unique(array_merge($namesCommitted, $namesUncommitted)));
            sort($filesChanged);

            // 9b. Check empty diff
            if ($diff === '') {
                return new HarnessResult(
                    status: HarnessStatus::Failed,
                    diff: '',
                    filesChanged: [],
                    testsPassed: null,
                    summary: 'No changes were produced.',
                    openQuestions: [],
                    rawLog: $rawLog
                );
            }

            // 8. Tests check
            $testsPassed = null;
            if ($request->testCommand && $diff !== '') {
                $testResult = Process::path($repoPath)->timeout(600)->run($request->testCommand);
                $testsPassed = $testResult->exitCode() === 0;
                $rawLog .= "\n" . $testResult->output() . "\n" . $testResult->errorOutput();
            }

            // 9c. Test failure check
            if ($request->testCommand && $testsPassed === false) {
                return new HarnessResult(
                    status: HarnessStatus::Failed,
                    diff: $diff,
                    filesChanged: $filesChanged,
                    testsPassed: false,
                    summary: 'Changes produced but tests fail.',
                    openQuestions: [],
                    rawLog: $rawLog
                );
            }

            // 9d. Success
            return new HarnessResult(
                status: HarnessStatus::Completed,
                diff: $diff,
                filesChanged: $filesChanged,
                testsPassed: $testsPassed,
                summary: count($filesChanged) . ' file(s) changed.',
                openQuestions: [],
                rawLog: $rawLog
            );

        } finally {
            if ($instructionFile && file_exists($instructionFile)) {
                unlink($instructionFile);
            }
        }
    }
}
