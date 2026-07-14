<?php

use App\Livewire\SettingsPage;
use App\Models\ProviderEndpoint;
use App\Models\Role;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses()->group('settings');

test('providers section renders seeded builtin cards', function () {
    ProviderEndpoint::create([
        'name' => 'builtin_test',
        'label' => 'Builtin Test',
        'driver' => 'openai_compatible',
        'base_url' => 'https://api.example.com',
        'timeout' => 30,
        'is_builtin' => true,
    ]);

    Livewire::test(SettingsPage::class)
        ->set('section', 'providers')
        ->assertSee('Builtin Test')
        ->assertSee('builtin_test')
        ->assertSee('builtin');
});

test('add endpoint persists a row and appears in actors role-provider select', function () {
    Livewire::test(SettingsPage::class)
        ->set('newEndpoint.name', 'my_provider')
        ->set('newEndpoint.label', 'My Provider')
        ->set('newEndpoint.driver', 'openai_compatible')
        ->set('newEndpoint.base_url', 'https://my.provider.com/v1')
        ->call('addEndpoint')
        ->assertHasNoErrors();

    expect(ProviderEndpoint::where('name', 'my_provider')->exists())->toBeTrue();

    Livewire::test(SettingsPage::class)
        ->set('section', 'actors')
        ->assertSee('My Provider');
});

test('save with blank api_key keeps stored key; non-blank replaces; clear nulls it', function () {
    $ep = ProviderEndpoint::create([
        'name' => 'key_test',
        'label' => 'Key Test',
        'driver' => 'openai_compatible',
        'base_url' => 'https://key.test.com',
        'api_key' => 'original-secret',
        'timeout' => 30,
        'is_builtin' => false,
    ]);

    $component = Livewire::test(SettingsPage::class)
        ->set('section', 'providers');

    // Blank keeps original
    $component->set("endpointDrafts.{$ep->id}.api_key", '')
        ->call("saveEndpoint", $ep->id);
    expect($ep->fresh()->api_key)->toBe('original-secret');

    // Non-blank replaces
    $component->set("endpointDrafts.{$ep->id}.api_key", 'new-secret')
        ->call("saveEndpoint", $ep->id);
    expect($ep->fresh()->api_key)->toBe('new-secret');

    // Clear nulls it
    $component->call("clearEndpointKey", $ep->id);
    expect($ep->fresh()->api_key)->toBeNull();
});

test('api_key value never appears in rendered HTML', function () {
    ProviderEndpoint::create([
        'name' => 'secret_test',
        'label' => 'Secret Test',
        'driver' => 'openai_compatible',
        'base_url' => 'https://secret.test.com',
        'api_key' => 'super-secret-key-123',
        'timeout' => 30,
        'is_builtin' => false,
    ]);

    Livewire::test(SettingsPage::class)
        ->set('section', 'providers')
        ->assertDontSee('super-secret-key-123');
});

test('delete builtin refused', function () {
    $ep = ProviderEndpoint::create([
        'name' => 'builtin_del',
        'label' => 'Builtin Del',
        'driver' => 'openai_compatible',
        'base_url' => 'https://builtin.del.com',
        'timeout' => 30,
        'is_builtin' => true,
    ]);

    Livewire::test(SettingsPage::class)
        ->call('deleteEndpoint', $ep->id)
        ->assertHasErrors(['endpoint' => 'Cannot delete builtin providers.']);
});

test('delete endpoint referenced by role refused', function () {
    $ep = ProviderEndpoint::create([
        'name' => 'used_provider',
        'label' => 'Used Provider',
        'driver' => 'openai_compatible',
        'base_url' => 'https://used.com',
        'timeout' => 30,
        'is_builtin' => false,
    ]);
    Role::create(['name' => 'test_role', 'provider' => 'used_provider', 'model' => 'gpt-4', 'is_builtin' => false]);

    Livewire::test(SettingsPage::class)
        ->call('deleteEndpoint', $ep->id)
        ->assertHasErrors(['endpoint' => 'Cannot delete providers referenced by roles.']);
});

test('delete unreferenced custom endpoint succeeds', function () {
    $ep = ProviderEndpoint::create([
        'name' => 'unused_provider',
        'label' => 'Unused Provider',
        'driver' => 'openai_compatible',
        'base_url' => 'https://unused.com',
        'timeout' => 30,
        'is_builtin' => false,
    ]);

    Livewire::test(SettingsPage::class)
        ->call('deleteEndpoint', $ep->id)
        ->assertHasNoErrors();

    expect(ProviderEndpoint::find($ep->id))->toBeNull();
});

test('testEndpoint success returns ok', function () {
    $ep = ProviderEndpoint::create([
        'name' => 'test_ok',
        'label' => 'Test OK',
        'driver' => 'openai_compatible',
        'base_url' => 'https://test.ok.com',
        'timeout' => 30,
        'is_builtin' => false,
    ]);

    Http::fake([
        'test.ok.com/models' => Http::response(['data' => []], 200),
    ]);

    Livewire::test(SettingsPage::class)
        ->call('testEndpoint', $ep->id)
        ->assertSet("endpointTestResults.{$ep->id}", 'ok');
});

test('testEndpoint connection failure returns fail', function () {
    $ep = ProviderEndpoint::create([
        'name' => 'test_fail',
        'label' => 'Test Fail',
        'driver' => 'openai_compatible',
        'base_url' => 'https://test.fail.com',
        'timeout' => 30,
        'is_builtin' => false,
    ]);

    Http::fake([
        'test.fail.com/*' => fn () => throw new ConnectionException('Connection refused'),
    ]);

    Livewire::test(SettingsPage::class)
        ->call('testEndpoint', $ep->id)
        ->assertSet("endpointTestResults.{$ep->id}", 'fail');
});

test('save while not changing key leaves stored key untouched', function () {
    $ep = ProviderEndpoint::create([
        'name' => 'key_unchanged',
        'label' => 'Key Unchanged',
        'driver' => 'openai_compatible',
        'base_url' => 'https://unchanged.com',
        'api_key' => 'original',
        'timeout' => 30,
        'is_builtin' => false,
    ]);

    Livewire::test(SettingsPage::class)
        ->set('section', 'providers')
        ->set("endpointDrafts.{$ep->id}.api_key", 'leaked-key')
        ->call("saveEndpoint", $ep->id);

    expect($ep->fresh()->api_key)->toBe('original');
});

test('startChangeKey + set + save persists new key and resets state', function () {
    $ep = ProviderEndpoint::create([
        'name' => 'key_change',
        'label' => 'Key Change',
        'driver' => 'openai_compatible',
        'base_url' => 'https://change.com',
        'api_key' => 'old',
        'timeout' => 30,
        'is_builtin' => false,
    ]);

    $component = Livewire::test(SettingsPage::class)
        ->set('section', 'providers')
        ->call("startChangeKey", $ep->id)
        ->assertSet("changingKey.{$ep->id}", true)
        ->set("endpointDrafts.{$ep->id}.api_key", 'new-secret')
        ->call("saveEndpoint", $ep->id);

    expect($ep->fresh()->api_key)->toBe('new-secret');
    $component->assertSet("changingKey.{$ep->id}", false)
        ->assertSet("endpointDrafts.{$ep->id}.api_key", '');
});

test('cancelChangeKey blanks draft and prevents save', function () {
    $ep = ProviderEndpoint::create([
        'name' => 'key_cancel',
        'label' => 'Key Cancel',
        'driver' => 'openai_compatible',
        'base_url' => 'https://cancel.com',
        'api_key' => 'original',
        'timeout' => 30,
        'is_builtin' => false,
    ]);

    $component = Livewire::test(SettingsPage::class)
        ->set('section', 'providers')
        ->call("startChangeKey", $ep->id)
        ->set("endpointDrafts.{$ep->id}.api_key", 'temp-key')
        ->call("cancelChangeKey", $ep->id);

    $component->assertSet("changingKey.{$ep->id}", false)
        ->assertSet("endpointDrafts.{$ep->id}.api_key", '');
        
    $component->call("saveEndpoint", $ep->id);
    expect($ep->fresh()->api_key)->toBe('original');
});

test('env-sourced endpoint has key_source env and startChangeKey is no-op', function () {
    $ep = ProviderEndpoint::create([
        'name' => 'env_key',
        'label' => 'Env Key',
        'driver' => 'openai_compatible',
        'base_url' => 'https://env.com',
        'api_key' => null,
        'meta' => ['api_key_config' => 'services.openai.key'],
        'timeout' => 30,
        'is_builtin' => true,
    ]);

    $component = Livewire::test(SettingsPage::class)
        ->set('section', 'providers')
        ->assertSet("endpointDrafts.{$ep->id}.key_source", 'env')
        ->assertSet("endpointDrafts.{$ep->id}.key_config", 'services.openai.key')
        ->call("startChangeKey", $ep->id);

    $component->assertSet("changingKey.{$ep->id}", false);
});

test('clear action nulls db key and updates key_source to none', function () {
    $ep = ProviderEndpoint::create([
        'name' => 'key_clear',
        'label' => 'Key Clear',
        'driver' => 'openai_compatible',
        'base_url' => 'https://clear.com',
        'api_key' => 'secret',
        'timeout' => 30,
        'is_builtin' => false,
    ]);

    Livewire::test(SettingsPage::class)
        ->set('section', 'providers')
        ->call("clearEndpointKey", $ep->id);

    expect($ep->fresh()->api_key)->toBeNull();
    
    Livewire::test(SettingsPage::class)
        ->set('section', 'providers')
        ->assertSet("endpointDrafts.{$ep->id}.key_source", 'none');
});

test('creation with a key still works', function () {
    Livewire::test(SettingsPage::class)
        ->set('newEndpoint.name', 'new_with_key')
        ->set('newEndpoint.label', 'New With Key')
        ->set('newEndpoint.driver', 'openai_compatible')
        ->set('newEndpoint.base_url', 'https://new.com/v1')
        ->set('newEndpoint.api_key', 'brand-new-key')
        ->call('addEndpoint')
        ->assertHasNoErrors();

    $ep = ProviderEndpoint::where('name', 'new_with_key')->first();
    expect($ep->api_key)->toBe('brand-new-key');
});
