<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

it('creates a project with factory and persists idle status', function () {
    $project = Project::factory()->create();

    expect($project->id)->not->toBeNull()
        ->and($project->status)->toBe(ProjectStatus::Idle);
});

it('round-trips status as enum', function () {
    $project = Project::factory()->create();
    $project->status = ProjectStatus::NeedsYou;
    $project->save();

    $refreshed = Project::find($project->id);
    expect($refreshed->status)->toBe(ProjectStatus::NeedsYou);
});

it('enforces slug uniqueness', function () {
    $project = Project::factory()->create(['slug' => 'unique-slug']);

    expect(fn () => Project::factory()->create(['slug' => 'unique-slug']))
        ->toThrow(QueryException::class);
});

it('casts last_activity_at to Carbon instance', function () {
    $now = Carbon::now()->startOfSecond();
    $project = Project::factory()->create(['last_activity_at' => $now]);

    expect($project->last_activity_at)->toBeInstanceOf(Carbon::class)
        ->and($project->last_activity_at->eq($now))->toBeTrue();
});
