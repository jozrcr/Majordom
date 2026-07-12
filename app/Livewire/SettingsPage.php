<?php

namespace App\Livewire;

use App\Models\Role;
use App\Models\Workflow;
use App\Support\Setting;
use Illuminate\Support\Facades\Cache;
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

    public function mount(): void
    {
        $this->loadRoles();
        $this->loadWorkflow();
        $this->loadIntegrations();
    }

    public function loadRoles(): void
    {
        $roles = Role::whereNull('project_id')->orderBy('is_builtin', 'desc')->orderBy('name')->get();
        foreach ($roles as $role) {
            $this->roleDrafts[$role->id] = [
                'provider' => $role->provider,
                'model' => $role->model,
                'temperature' => $role->temperature,
                'max_tokens' => $role->max_tokens,
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

    public function saveRole(string $id): void
    {
        $role = Role::findOrFail($id);
        $validated = $this->validate([
            "roleDrafts.{$id}.provider" => 'required|in:openrouter,metallama',
            "roleDrafts.{$id}.model" => 'required|string',
            "roleDrafts.{$id}.temperature" => 'nullable|numeric|min:0|max:2',
            "roleDrafts.{$id}.max_tokens" => 'nullable|integer|min:0',
        ]);

        $role->update([
            'provider' => data_get($validated, "roleDrafts.{$id}.provider"),
            'model' => data_get($validated, "roleDrafts.{$id}.model"),
            'temperature' => data_get($validated, "roleDrafts.{$id}.temperature"),
            'max_tokens' => data_get($validated, "roleDrafts.{$id}.max_tokens"),
        ]);
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
            'newRole.provider' => 'required|in:openrouter,metallama',
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
        foreach ($validated['chainDraft'] as $type) {
            if (!$engine->knows($type)) {
                $this->addError('chainDraft', "Unknown node type '{$type}'.");
                return;
            }
        }

        if ($this->editingId) {
            $wf = Workflow::findOrFail($this->editingId);
            $wf->update([
                'name' => $validated['workflowName'],
                'description' => $validated['workflowDescription'],
                'chain' => $validated['chainDraft'],
            ]);
        } else {
            Workflow::create([
                'name' => $validated['workflowName'],
                'description' => $validated['workflowDescription'],
                'chain' => $validated['chainDraft'],
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
        $this->chainDraft = $wf->chain;
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
            $this->chainDraft[] = $this->chainPick;
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

    public function render()
    {
        return view('livewire.settings-page', [
            'workflows' => Workflow::orderBy('is_builtin', 'desc')->orderBy('name')->get(),
            'knownTypes' => app(\App\Core\Workflow\WorkflowEngine::class)->knownTypes(),
        ]);
    }
}
