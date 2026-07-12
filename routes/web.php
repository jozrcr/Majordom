<?php

use Illuminate\Support\Facades\Route;

Route::get('/', \App\Livewire\ProjectDashboard::class)->name('home');
Route::get('/inbox', \App\Livewire\Inbox::class)->name('inbox');
Route::get('/projects/{project}', \App\Livewire\ProjectWorkspace::class)->name('project.workspace');
Route::get('/settings', \App\Livewire\SettingsPage::class)->middleware('auth')->name('settings');

// Dev-only M1 de-risk surface; gone once the real workflow exists (M3).
if (config('app.debug')) {
    Route::get('/dev/harness', \App\Livewire\HarnessSmoke::class)->name('dev.harness');
}

Route::get('/login', function () {
    return view('auth.login');
})->name('login');

Route::post('/login', function (\Illuminate\Http\Request $request) {
    $request->validate(['token' => 'required|string']);
    
    if (hash_equals((string) config('majordom.token'), (string) $request->input('token'))) {
        $request->session()->put('majordom_authenticated', true);
        $request->session()->regenerate();
        return redirect()->intended('/');
    }
    
    return back()->withErrors(['token' => 'Invalid token.']);
})->name('login.attempt');

Route::post('/logout', function (\Illuminate\Http\Request $request) {
    $request->session()->forget('majordom_authenticated');
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect()->route('login');
})->name('logout');
