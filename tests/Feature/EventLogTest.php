<?php

use App\Core\Events\EventRecorder;
use App\Core\Workflow\NodeJob;
use App\Core\Workflow\NodeResult;
use App\Core\Workflow\WorkflowEngine;
use App\Agents\Architect\ArchitectService;
use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Enums\ApprovalType;
use App\Models\Event;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Project;
use App\Projects\Memory\MemoryStore;
use Illuminate\Support\Facades\Schema;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->execution = Execution::factory()->create(['project_id' => $this->project->id]);
    $this->memoryRoot = sys_get_temp_dir().'/majordom-arch-'.uniqid();
    config(['majordom.memory_root' => $this->memoryRoot]);
});

afterEach(function () {
    if (is_dir($this->memoryRoot)) {
        exec('rm -rf '.escapeshellarg($this->memoryRoot));
    }
});

it('inserts a row with name, actor, payload and created_at', function () {
    $recorder = app(EventRecorder::class);
    $recorder->record($this->project, 'test.event', ['key' => 'value'], $this->execution, 'tester');

    $event = Event::first();
    expect($event)->not->toBeNull()
        ->and($event->name)->toBe('test.event')
        ->and($event->actor)->toBe('tester')
        ->and($event->payload)->toBe(['key' => 'value'])
        ->and($event->created_at)->not->toBeNull();
});

it('swallows storage failures without throwing', function () {
    Schema::drop('events');
    
    $recorder = app(EventRecorder::class);
    expect(fn () => $recorder->record($this->project, 'test.event'))->not->toThrow();
});

class TestDoneNode extends NodeJob
{
    protected function run(Node $node, Execution $execution): NodeResult
    {
        return NodeResult::done(['ran' => $node->type]);
    }
}

it('records workflow chain events in order', function () {
    config(['queue.connections.harness.driver' => 'sync']);
    
    $engine = new WorkflowEngine(['x' => TestDoneNode::class, 'y' => TestDoneNode::class]);
    app()->instance(WorkflowEngine::class, $engine);
    
    $engine->start($this->execution, ['x', 'y']);
    
    $events = Event::orderBy('id')->pluck('name')->all();
    expect($events)->toBe([
        'workflow.started',
        'x.started',
        'x.completed',
        'y.started',
        'y.completed',
        'workflow.completed',
    ]);
});

class TestGateNode extends NodeJob
{
    protected function run(Node $node, Execution $execution): NodeResult
    {
        return NodeResult::waitHuman(ApprovalType::Review, 'Review', []);
    }
}

it('records approval.granted with actor you', function () {
    config(['queue.connections.harness.driver' => 'sync']);
    
    $engine = new WorkflowEngine(['gate' => TestGateNode::class]);
    app()->instance(WorkflowEngine::class, $engine);
    
    $engine->start($this->execution, ['gate']);
    
    $approval = $this->execution->approvals()->first();
    $engine->resolveApproval($approval, true, 'looks good');
    
    $event = Event::where('name', 'approval.granted')->first();
    expect($event)->not->toBeNull()
        ->and($event->actor)->toBe('you')
        ->and($event->payload['comment'])->toBe('looks good');
});

class TestScriptedProvider implements Provider
{
    public function __construct(public array $responses) {}
    public function chat(ProviderRequest $request): ProviderResponse
    {
        $content = array_shift($this->responses) ?? '{}';
        return new ProviderResponse($content, 'stop', 10, 20);
    }
}

it('records consensus.message on architect turn', function () {
    $provider = new TestScriptedProvider([json_encode([
        'reply' => 'Agreed.',
        'questions' => [],
        'consensus_reached' => true,
    ])]);
    
    $service = new ArchitectService($provider, MemoryStore::fromConfig());
    $service->converse($this->project, 'Let\'s build it.');
    
    $event = Event::where('name', 'consensus.message')->first();
    expect($event)->not->toBeNull()
        ->and($event->actor)->toBe('architect')
        ->and($event->payload['consensusClaimed'])->toBeTrue();
});
