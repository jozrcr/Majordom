<?php

use Illuminate\Support\Facades\Route;

Route::get('/', \App\Livewire\ProjectDashboard::class)->name('home');

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
