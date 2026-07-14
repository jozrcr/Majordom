<?php

namespace App\Livewire;

use App\Core\Workflow\ChainStep;
use App\Models\ProviderEndpoint;
use App\Models\Role;
use App\Models\Workflow;
use App\Support\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Majordom — Settings')]
#[Layout('components.layouts.app')]
class SettingsPage extends Component
{
    public string $section = 'actors';

    public array $roleDrafts = [];
    public array $newRole = ['name' => '', 'provider' => 'openrouter', 'model' => ''];

    public array $workflow = [
        'max_revisions' => 3,
        'overnight_spend_cap_usd' => 5.0,
    ];

    public bool $metallamaOk = false;
    public bool $telegramConfigured = false;
    public string $reverbHost = '';

    // Workflow builder state
    public string $workflowName = '';
    public string $workflowDescription = '';
    public array $chainDraft = [];
    public string $chainPick = '';
    public ?int $editingId = null;
    public array $availableRoles = [];
    public array $providerOptions = [];

    // Provider endpoints state
    public array $endpointDrafts = [];
    public array $newEndpoint = ['name' => '', 'label' => '', 'driver' => 'openai_compatible', 'base_url' => '', 'api_key' => ''];
    public array $endpointTestResults = [];

    /** Feedback marker: which thing was just saved ('role:{id}', 'workflow-settings', 'endpoint:{id}'). */
    public ?string $justSaved = null;

    public function mount(): void
    {
        $this->loadRoles();
        $this->loadWorkflow();
        $this->loadIntegrations();
        $this->loadEndpoints();
        $this->availableRoles = array_values(array_unique(array_merge(
            ['builder', 'reviewer', 'architect'],
            Role::whereNull('project_id')->pluck('name')->toArray(),
        )));
        $this->providerOptions = ProviderEndpoint::orderBy('name')->pluck('label', 'name')->toArray();
    }

    public function loadRoles(): void
    {
        $roles = Role::whereNull('project_id')->orderBy('is_builtin', 'desc')->orderBy('name')->get();
        foreach ($roles as $role) {
            $meta = $role->meta ?? [];
            $this->roleDrafts[$role->id] = [
                'provider' => $role->provider,
                'model' => $role->model,
                'temperature' => $role->temperature,
                'max_tokens' => $role->max_tokens,
                'system_prompt_extra' => $meta['system_prompt_extra'] ?? '',
                'extra_instructions' => $meta['extra_instructions'] ?? '',
                'top_p' => $meta['top_p'] ?? '',
                'frequency_penalty' => $meta['frequency_penalty'] ?? '',
                'presence_penalty' => $meta['presence_penalty'] ?? '',
                'stop' => isset($meta['stop']) && is_array($meta['stop']) ? implode(', ', $meta['stop']) : '',
                'timeout' => $meta['timeout'] ?? '',
            ];
        }
    }

    public function loadWorkflow(): void
    {
        $this->workflow['max_revisions'] = Setting::get('workflow.max_revisions', config('majordom.workflow.max_revisions', 3));
        $this->workflow['overnight_spend_cap_usd'] = Setting::get('workflow.overnight_spend_cap_usd', config('majordom.workflow.overnight_spend_cap_usd', 5.0));
    }

    public function loadIntegrations(): void
    {
        $this->metallamaOk = Cache::remember('settings:metallama-ok', 30, function () {
            try {
                app(\App\Runtime\Metallama\MetallamaClient::class)->models();
                return true;
            } catch (\Throwable $e) {
                return false;
            }
        });

        $this->telegramConfigured = app(\App\Integrations\Telegram\TelegramClient::class)->configured();
        $this->reverbHost = config('broadcasting.connections.reverb.host', '') . ':' . (config('broadcasting.connections.reverb.port', ''));
    }

    public function loadEndpoints(): void
    {
        $endpoints = ProviderEndpoint::orderBy('is_builtin', 'desc')->orderBy('name')->get();
        foreach ($endpoints as $ep) {
            $this->endpointDrafts[$ep->id] = [
                'name' => $ep->name,
                'driver' => $ep->driver,
                'is_builtin' => $ep->is_builtin,
                'has_key' => $ep->api_key !== null,
                'label' => $ep->label,
                'base_url' => $ep->base_url,
                'timeout' => $ep->timeout,
                'api_key' => '',
            ];
        }
    }

    public function saveRole(string $id): void
    {
        $role = Role::findOrFail($id);
        $validated = $this->validate([
            "roleDrafts.{$id}.provider" => ['required', Rule::exists('provider_endpoints', 'name')],
            "roleDrafts.{$id}.model" => 'required|string',
            "roleDrafts.{$id}.temperature" => 'nullable|numeric|min:0|max:2',
            "roleDrafts.{$id}.max_tokens" => 'nullable|integer|min:0',
            "roleDrafts.{$id}.system_prompt_extra" => 'nullable|string',
            "roleDrafts.{$id}.extra_instructions" => 'nullable|string',
            "roleDrafts.{$id}.top_p" => 'nullable|numeric|min:0|max:1',
            "roleDrafts.{$id}.frequency_penalty" => 'nullable|numeric|min:-2|max:2',
            "roleDrafts.{$id}.presence_penalty" => 'nullable|numeric|min:-2|max:2',
            "roleDrafts.{$id}.stop" => 'nullable|string',
            "roleDrafts.{$id}.timeout" => 'nullable|integer|min:5|max:3600',
        ]);

        $meta = $role->meta ?? [];
        $this->applyMeta($meta, $validated, $id);

        $role->update([
            'provider' => data_get($validated, "roleDrafts.{$id}.provider"),
            'model' => data_get($validated, "roleDrafts.{$id}.model"),
            'temperature' => data_get($validated, "roleDrafts.{$id}.temperature"),
            'max_tokens' => data_get($validated, "roleDrafts.{$id}.max_tokens"),
            'meta' => $meta,
        ]);

        $this->justSaved = "role:{$id}";
    }

    /** One button saves every role draft on the page. */
    public function saveAllRoles(): void
    {
        $rules = [];
        foreach (array_keys($this->roleDrafts) as $id) {
            $rules["roleDrafts.{$id}.provider"] = ['required', Rule::exists('provider_endpoints', 'name')];
            $rules["roleDrafts.{$id}.model"] = 'required|string';
            $rules["roleDrafts.{$id}.temperature"] = 'nullable|numeric|min:0|max:2';
            $rules["roleDrafts.{$id}.max_tokens"] = 'nullable|integer|min:0';
            $rules["roleDrafts.{$id}.system_prompt_extra"] = 'nullable|string';
            $rules["roleDrafts.{$id}.extra_instructions"] = 'nullable|string';
            $rules["roleDrafts.{$id}.top_p"] = 'nullable|numeric|min:0|max:1';
            $rules["roleDrafts.{$id}.frequency_penalty"] = 'nullable|numeric|min:-2|max:2';
            $rules["roleDrafts.{$id}.presence_penalty"] = 'nullable|numeric|min:-2|max:2';
            $rules["roleDrafts.{$id}.stop"] = 'nullable|string';
            $rules["roleDrafts.{$id}.timeout"] = 'nullable|integer|min:5|max:3600';
        }

        $validated = $this->validate($rules);

        foreach (Role::whereIn('id', array_keys($this->roleDrafts))->get() as $role) {
            $meta = $role->meta ?? [];
            $this->applyMeta($meta, $validated, $role->id);

            $role->update([
                'provider' => data_get($validated, "roleDrafts.{$role->id}.provider"),
                'model' => data_get($validated, "roleDrafts.{$role->id}.model"),
                'temperature' => data_get($validated, "roleDrafts.{$role->id}.temperature"),
                'max_tokens' => data_get($validated, "roleDrafts.{$role->id}.max_tokens"),
                'meta' => $meta,
            ]);
        }

        $this->justSaved = 'roles';
    }

    private function applyMeta(array &$meta, array $validated, string $id): void
    {
        $extraSystem = trim(data_get($validated, "roleDrafts.{$id}.system_prompt_extra") ?? '');
        $extraInstr = trim(data_get($validated, "roleDrafts.{$id}.extra_instructions") ?? '');
        $topP = trim(data_get($validated, "roleDrafts.{$id}.top_p") ?? '');
        $freqPen = trim(data_get($validated, "roleDrafts.{$id}.frequency_penalty") ?? '');
        $presPen = trim(data_get($validated, "roleDrafts.{$id}.presence_penalty") ?? '');
        $stopRaw = trim(data_get($validated, "roleDrafts.{$id}.stop") ?? '');
        $timeout = trim(data_get($validated, "roleDrafts.{$id}.timeout") ?? '');

        if ($extraSystem !== '') $meta['system_prompt_extra'] = $extraSystem; else unset($meta['system_prompt_extra']);
        if ($extraInstr !== '') $meta['extra_instructions'] = $extraInstr; else unset($meta['extra_instructions']);
        if ($topP !== '') $meta['top_p'] = (float) $topP; else unset($meta['top_p']);
        if ($freqPen !== '') $meta['frequency_penalty'] = (float) $freqPen; else unset($meta['frequency_penalty']);
        if ($presPen !== '') $meta['presence_penalty'] = (float) $presPen; else unset($meta['presence_penalty']);
        
        if ($stopRaw !== '') {
            $stopArr = array_map('trim', explode(',', $stopRaw));
            $stopArr = array_filter($stopArr, fn($s) => $s !== '');
            $meta['stop'] = array_slice($stopArr, 0, 4);
        } else {
            unset($meta['stop']);
        }
        
        if ($timeout !== '') $meta['timeout'] = (int) $timeout; else unset($meta['timeout']);
    }

    public function deleteRole(string $id): void
    {
        $role = Role::findOrFail($id);
        if (!$role->is_builtin) {
            $role->delete();
            unset($this->roleDrafts[$id]);
        }
    }

    public function addRole(): void
    {
        $validated = $this->validate([
            'newRole.name' => 'required|string|alpha_dash|unique:roles,name',
            'newRole.provider' => ['required', Rule::exists('provider_endpoints', 'name')],
            'newRole.model' => 'required|string',
        ]);

        $role = Role::make([
            'name' => strtolower(data_get($validated, 'newRole.name')),
            'provider' => data_get($validated, 'newRole.provider'),
            'model' => data_get($validated, 'newRole.model'),
            'is_builtin' => false,
        ]);
        $role->save();

        $this->roleDrafts[$role->id] = [
            'provider' => $role->provider,
            'model' => $role->model,
            'temperature' => null,
            'max_tokens' => null,
            'system_prompt_extra' => '',
            'extra_instructions' => '',
            'top_p' => '',
            'frequency_penalty' => '',
            'presence_penalty' => '',
            'stop' => '',
            'timeout' => '',
        ];
        $this->reset('newRole');
    }

    public function saveWorkflowSettings(): void
    {
        $validated = $this->validate([
            'workflow.max_revisions' => 'required|integer|min:1|max:10',
            'workflow.overnight_spend_cap_usd' => 'required|numeric|min:0.05|max:100',
        ]);

        Setting::put('workflow.max_revisions', data_get($validated, 'workflow.max_revisions'));
        Setting::put('workflow.overnight_spend_cap_usd', data_get($validated, 'workflow.overnight_spend_cap_usd'));

        $this->justSaved = 'workflow-settings';
    }

    // Workflow CRUD
    public function saveWorkflow(): void
    {
        $validated = $this->validate([
            'workflowName' => 'required|string|unique:workflows,name,' . ($this->editingId ?? 0),
            'workflowDescription' => 'nullable|string',
            'chainDraft' => 'required|array|min:1',
        ]);

        $engine = app(\App\Core\Workflow\WorkflowEngine::class);
        $steps = ChainStep::normalize($validated['chainDraft']);
        foreach ($steps as $step) {
            if (!$engine->knows($step->type)) {
                $this->addError('chainDraft', "Unknown node type '{$step->type}'.");
                return;
            }
        }

        $storable = ChainStep::toStorable($steps);

        if ($this->editingId) {
            $wf = Workflow::findOrFail($this->editingId);
            $wf->update([
                'name' => $validated['workflowName'],
                'description' => $validated['workflowDescription'],
                'chain' => $storable,
            ]);
        } else {
            Workflow::create([
                'name' => $validated['workflowName'],
                'description' => $validated['workflowDescription'],
                'chain' => $storable,
                'is_builtin' => false,
            ]);
        }

        $this->resetWorkflowDraft();
    }

    public function resetWorkflowDraft(): void
    {
        $this->workflowName = '';
        $this->workflowDescription = '';
        $this->chainDraft = [];
        $this->chainPick = '';
        $this->editingId = null;
    }

    public function loadWorkflowForEdit(int $id): void
    {
        $wf = Workflow::findOrFail($id);
        $this->editingId = $id;
        $this->workflowName = $wf->name;
        $this->workflowDescription = $wf->description ?? '';
        $steps = ChainStep::normalize($wf->chain);
        $this->chainDraft = array_map(fn(ChainStep $s) => [
            'type' => $s->type,
            'role' => $s->role,
            'config' => $s->config,
        ], $steps);
    }

    public function deleteWorkflow(int $id): void
    {
        $wf = Workflow::findOrFail($id);
        if ($wf->is_builtin) {
            $this->addError('workflow', 'Cannot delete builtin workflows.');
            return;
        }
        if ($wf->projects()->exists()) {
            $this->addError('workflow', 'Cannot delete workflows in use by projects.');
            return;
        }
        $wf->delete();
    }

    public function addStep(): void
    {
        if ($this->chainPick !== '' && app(\App\Core\Workflow\WorkflowEngine::class)->knows($this->chainPick)) {
            $defaults = ['build' => 'builder', 'review' => 'reviewer'];
            $this->chainDraft[] = [
                'type' => $this->chainPick,
                'role' => $defaults[$this->chainPick] ?? 'system',
                'config' => [],
            ];
            $this->chainPick = '';
        }
    }

    public function moveStep(int $index, string $dir): void
    {
        $count = count($this->chainDraft);
        if ($dir === 'up' && $index > 0) {
            $tmp = $this->chainDraft[$index];
            $this->chainDraft[$index] = $this->chainDraft[$index - 1];
            $this->chainDraft[$index - 1] = $tmp;
        } elseif ($dir === 'down' && $index < $count - 1) {
            $tmp = $this->chainDraft[$index];
            $this->chainDraft[$index] = $this->chainDraft[$index + 1];
            $this->chainDraft[$index + 1] = $tmp;
        }
    }

    public function removeStep(int $index): void
    {
        unset($this->chainDraft[$index]);
        $this->chainDraft = array_values($this->chainDraft);
    }

    // Provider Endpoints CRUD
    public function saveEndpoint(string $id): void
    {
        $ep = ProviderEndpoint::findOrFail($id);
        $validated = $this->validate([
            "endpointDrafts.{$id}.label" => 'required|string',
            "endpointDrafts.{$id}.base_url" => 'required|url',
            "endpointDrafts.{$id}.timeout" => 'required|integer|min:5|max:3600',
            "endpointDrafts.{$id}.api_key" => 'nullable|string',
        ]);

        $updateData = [
            'label' => data_get($validated, "endpointDrafts.{$id}.label"),
            'base_url' => rtrim(data_get($validated, "endpointDrafts.{$id}.base_url"), '/'),
            'timeout' => data_get($validated, "endpointDrafts.{$id}.timeout"),
        ];

        $newKey = data_get($validated, "endpointDrafts.{$id}.api_key");
        if ($newKey !== null && $newKey !== '') {
            $updateData['api_key'] = $newKey;
        }

        $ep->update($updateData);
        $this->loadEndpoints();
        $this->justSaved = "endpoint:{$id}";
    }

    public function clearEndpointKey(string $id): void
    {
        $ep = ProviderEndpoint::findOrFail($id);
        $ep->update(['api_key' => null]);
        $this->loadEndpoints();
    }

    public function addEndpoint(): void
    {
        $validated = $this->validate([
            'newEndpoint.name' => 'required|alpha_dash|unique:provider_endpoints,name',
            'newEndpoint.label' => 'required|string',
            'newEndpoint.driver' => 'required|in:openai_compatible,metallama',
            'newEndpoint.base_url' => 'required|url',
            'newEndpoint.api_key' => 'nullable|string',
        ]);

        ProviderEndpoint::create([
            'name' => strtolower(data_get($validated, 'newEndpoint.name')),
            'label' => data_get($validated, 'newEndpoint.label'),
            'driver' => data_get($validated, 'newEndpoint.driver'),
            'base_url' => rtrim(data_get($validated, 'newEndpoint.base_url'), '/'),
            'api_key' => data_get($validated, 'newEndpoint.api_key') ?: null,
            'timeout' => 30,
            'is_builtin' => false,
        ]);

        $this->reset('newEndpoint');
        $this->loadEndpoints();
    }

    public function deleteEndpoint(string $id): void
    {
        $ep = ProviderEndpoint::findOrFail($id);
        if ($ep->is_builtin) {
            $this->addError('endpoint', 'Cannot delete builtin providers.');
            return;
        }
        if (Role::where('provider', $ep->name)->exists()) {
            $this->addError('endpoint', 'Cannot delete providers referenced by roles.');
            return;
        }
        $ep->delete();
        $this->loadEndpoints();
    }

    public function testEndpoint(string $id): void
    {
        $ep = ProviderEndpoint::findOrFail($id);
        try {
            $response = Http::baseUrl($ep->chatBaseUrl())
                ->timeout(5)
                ->withHeaders(['Accept' => 'application/json'])
                ->when($ep->resolvedApiKey(), fn($h) => $h->withToken($ep->resolvedApiKey()))
                ->get('/models');

            $this->endpointTestResults[$id] = $response->successful() ? 'ok' : 'fail';
        } catch (ConnectionException $e) {
            $this->endpointTestResults[$id] = 'fail';
        } catch (\Throwable $e) {
            $this->endpointTestResults[$id] = 'fail';
        }
    }

    public function render()
    {
        return view('livewire.settings-page', [
            'workflows' => Workflow::orderBy('is_builtin', 'desc')->orderBy('name')->get(),
            'knownTypes' => app(\App\Core\Workflow\WorkflowEngine::class)->knownTypes(),
        ]);
    }
}
