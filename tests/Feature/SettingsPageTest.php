<?php

use App\Livewire\SettingsPage;
use App\Models\Role;
use App\Support\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RoleSeeder::class);
});

test('page loads and shows actors section with builtins', function () {
    $response = $this->withHeaders(['Authorization' => 'Bearer test-token'])
        ->get(route('settings'));
        
    $response->assertStatus(200);
    $response->assertSee('Actors & roles');
    
    $builtins = Role::whereNull('project_id')->where('is_builtin', true)->pluck('name');
    foreach ($builtins as $name) {
        $response->assertSee($name);
    }
});

test('saving a role draft updates the database', function () {
    $role = Role::whereNull('project_id')->first();
    
    \Livewire\Livewire::test(SettingsPage::class)
        ->set("roleDrafts.{$role->id}.model", 'gpt-4o')
        ->call('saveRole', $role->id)
        ->assertHasNoErrors()
        ->assertSet("roleDrafts.{$role->id}.model", 'gpt-4o');
        
    $this->assertDatabaseHas('roles', ['id' => $role->id, 'model' => 'gpt-4o']);
});

test('adding a custom role creates it and duplicate name errors', function () {
    \Livewire\Livewire::test(SettingsPage::class)
        ->set('newRole.name', 'custom-builder')
        ->set('newRole.provider', 'metallama')
        ->set('newRole.model', 'llama-3')
        ->call('addRole')
        ->assertHasNoErrors();
        
    $this->assertDatabaseHas('roles', ['name' => 'custom-builder', 'is_builtin' => false]);
    
    \Livewire\Livewire::test(SettingsPage::class)
        ->set('newRole.name', 'custom-builder')
        ->set('newRole.provider', 'openrouter')
        ->set('newRole.model', 'gpt-4')
        ->call('addRole')
        ->assertHasErrors(['newRole.name' => 'unique']);
});

test('deleting a custom role works but builtin cannot be deleted', function () {
    $custom = Role::make(['name' => 'temp-role', 'provider' => 'openrouter', 'model' => 'gpt-3', 'is_builtin' => false]);
    $custom->save();
    
    $builtin = Role::where('is_builtin', true)->first();
    
    \Livewire\Livewire::test(SettingsPage::class)
        ->call('deleteRole', $custom->id);
    $this->assertDatabaseMissing('roles', ['id' => $custom->id]);
        
    \Livewire\Livewire::test(SettingsPage::class)
        ->call('deleteRole', $builtin->id);
    $this->assertDatabaseHas('roles', ['id' => $builtin->id]);
});

test('workflow section saves settings and round-trips', function () {
    Setting::put('workflow.max_revisions', 1);
    $this->assertEquals(1, Setting::get('workflow.max_revisions'));
    
    \Livewire\Livewire::test(SettingsPage::class)
        ->set('workflow.max_revisions', 5)
        ->set('workflow.overnight_spend_cap_usd', 10.00)
        ->call('saveWorkflow')
        ->assertHasNoErrors();
        
    $this->assertEquals(5, Setting::get('workflow.max_revisions'));
    $this->assertEquals(10.00, Setting::get('workflow.overnight_spend_cap_usd'));
});

test('integrations section renders with metallama LED and telegram status', function () {
    Http::fake([
        '*/api/models' => Http::response(['models' => []], 200),
    ]);
    Cache::flush();
    
    \Livewire\Livewire::test(SettingsPage::class)
        ->assertSet('metallamaOk', true)
        ->assertSet('telegramConfigured', false);
});
