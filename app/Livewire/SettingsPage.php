<?php

namespace App\Livewire;

use App\Models\Role;
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
            'provider' => $validated["roleDrafts.{$id}.provider"],
            'model' => $validated["roleDrafts.{$id}.model"],
            'temperature' => $validated["roleDrafts.{$id}.temperature"],
            'max_tokens' => $validated["roleDrafts.{$id}.max_tokens"],
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
            'name' => strtolower($validated['newRole.name']),
            'provider' => $validated['newRole.provider'],
            'model' => $validated['newRole.model'],
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

    public function saveWorkflow(): void
    {
        $validated = $this->validate([
            'workflow.max_revisions' => 'required|integer|min:1|max:10',
            'workflow.overnight_spend_cap_usd' => 'required|numeric|min:0.05|max:100',
        ]);

        Setting::put('workflow.max_revisions', $validated['workflow.max_revisions']);
        Setting::put('workflow.overnight_spend_cap_usd', $validated['workflow.overnight_spend_cap_usd']);
    }

    public function render()
    {
        return view('livewire.settings-page');
    }
}
