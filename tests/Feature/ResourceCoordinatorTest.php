<?php

use App\Runtime\Metallama\MetallamaClient;
use App\Runtime\Metallama\ModelState;
use App\Runtime\Metallama\ResourceCoordinator;
use App\Runtime\Metallama\ServerStatus;
use App\Runtime\Metallama\Exceptions\CoordinatorTimeout;

class FakeMetallamaClient extends MetallamaClient
{
    public array $calls = [];
    public array $scriptedModels = [];
    public array $scriptedStatus = [];
    public int $statusIndex = 0;

    public function __construct()
    {
        parent::__construct('http://fake', null, 1);
    }

    public function models(): array
    {
        $this->calls[] = 'models';
        return $this->scriptedModels;
    }

    public function status(string $id): ModelState
    {
        $this->calls[] = "status:{$id}";
        return $this->scriptedStatus[$this->statusIndex++] ?? $this->scriptedStatus[array_key_last($this->scriptedStatus)];
    }

    public function start(string $id): ModelState
    {
        $this->calls[] = "start:{$id}";
        return $this->scriptedStatus[$this->statusIndex++] ?? new ModelState($id, ServerStatus::Starting, null, null);
    }

    public function stop(string $id): ModelState
    {
        $this->calls[] = "stop:{$id}";
        return $this->scriptedStatus[$this->statusIndex++] ?? new ModelState($id, ServerStatus::Offline, null, null);
    }
}

function makeState(string $id, string $statusStr, ?string $lastExit = null, ?string $lastLog = null): ModelState
{
    return new ModelState($id, ServerStatus::from($statusStr), $lastExit, $lastLog);
}

beforeEach(function () {
    config(['majordom.metallama.poll_interval_ms' => 1, 'majordom.metallama.start_timeout' => 1, 'majordom.metallama.stop_timeout' => 1]);
});

test('required online returns immediately', function () {
    $client = new FakeMetallamaClient();
    $client->scriptedModels = [makeState('req', 'Online')];
    
    $coord = new ResourceCoordinator($client, fn () => null);
    $result = $coord->ensure('req');
    
    expect($result->id)->toBe('req');
    expect($client->calls)->toBe(['models']);
});

test('required starting polls until online', function () {
    $client = new FakeMetallamaClient();
    $client->scriptedModels = [makeState('req', 'Starting')];
    $client->scriptedStatus = [makeState('req', 'Online')];
    
    $coord = new ResourceCoordinator($client, fn () => null);
    $result = $coord->ensure('req');
    
    expect($result->id)->toBe('req');
    expect($client->calls)->toContain('status:req');
    expect($client->calls)->not->toContain('start:req');
});

test('stops other online model before starting required', function () {
    $client = new FakeMetallamaClient();
    $client->scriptedModels = [makeState('other', 'Online'), makeState('req', 'Offline')];
    $client->scriptedStatus = [
        makeState('other', 'Offline'),
        makeState('req', 'Online'),
    ];
    
    $coord = new ResourceCoordinator($client, fn () => null);
    $coord->ensure('req');
    
    expect($client->calls)->toBe([
        'models',
        'stop:other',
        'status:other',
        'start:req',
        'status:req',
    ]);
});

test('stops two other models sequentially', function () {
    $client = new FakeMetallamaClient();
    $client->scriptedModels = [makeState('a', 'Online'), makeState('b', 'Online'), makeState('req', 'Offline')];
    $client->scriptedStatus = [
        makeState('a', 'Offline'),
        makeState('b', 'Offline'),
        makeState('req', 'Online'),
    ];
    
    $coord = new ResourceCoordinator($client, fn () => null);
    $coord->ensure('req');
    
    expect($client->calls)->toBe([
        'models',
        'stop:a',
        'status:a',
        'stop:b',
        'status:b',
        'start:req',
        'status:req',
    ]);
});

test('throws CoordinatorTimeout if stop never reaches Offline', function () {
    $client = new FakeMetallamaClient();
    $client->scriptedModels = [makeState('other', 'Online'), makeState('req', 'Offline')];
    $client->scriptedStatus = [makeState('other', 'Starting')];
    
    $coord = new ResourceCoordinator($client, fn () => null);
    
    expect(fn () => $coord->ensure('req'))->toThrow(CoordinatorTimeout::class, 'timed out stopping other');
});

test('throws CoordinatorTimeout if start never reaches Online', function () {
    $client = new FakeMetallamaClient();
    $client->scriptedModels = [makeState('req', 'Offline')];
    $client->scriptedStatus = [makeState('req', 'Starting')];
    
    $coord = new ResourceCoordinator($client, fn () => null);
    
    expect(fn () => $coord->ensure('req'))->toThrow(CoordinatorTimeout::class, 'timed out starting req');
});

test('throws CoordinatorTimeout if model exits while starting', function () {
    $client = new FakeMetallamaClient();
    $client->scriptedModels = [makeState('req', 'Offline')];
    $client->scriptedStatus = [
        makeState('req', 'Starting'),
        makeState('req', 'Offline', '1', 'crash.log'),
    ];
    
    $coord = new ResourceCoordinator($client, fn () => null);
    
    expect(fn () => $coord->ensure('req'))->toThrow(CoordinatorTimeout::class, 'model req exited while starting');
});

test('never calls start while another model is still Online', function () {
    $client = new FakeMetallamaClient();
    $client->scriptedModels = [makeState('other', 'Online'), makeState('req', 'Offline')];
    $client->scriptedStatus = [
        makeState('other', 'Offline'),
        makeState('req', 'Online'),
    ];
    
    $coord = new ResourceCoordinator($client, fn () => null);
    $coord->ensure('req');
    
    $startIdx = array_search('start:req', $client->calls);
    $offlineIdx = array_search('status:other', $client->calls);
    expect($startIdx)->toBeGreaterThan($offlineIdx);
});
