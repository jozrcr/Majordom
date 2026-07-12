<div class="flex min-h-[calc(100vh-52px)]">
    {{-- Left Nav --}}
    <aside class="w-[224px] border-r border-border p-4">
        <nav class="flex flex-col gap-1">
            <button wire:click="$set('section', 'actors')" class="rounded px-3 py-2 text-left text-sm font-medium transition-colors {{ $section === 'actors' ? 'bg-surface-active text-hi' : 'text-t3 hover:text-hi' }}">
                Actors & roles
            </button>
            <button wire:click="$set('section', 'workflow')" class="rounded px-3 py-2 text-left text-sm font-medium transition-colors {{ $section === 'workflow' ? 'bg-surface-active text-hi' : 'text-t3 hover:text-hi' }}">
                Workflow
            </button>
            <button wire:click="$set('section', 'workflows')" class="rounded px-3 py-2 text-left text-sm font-medium transition-colors {{ $section === 'workflows' ? 'bg-surface-active text-hi' : 'text-t3 hover:text-hi' }}">
                Workflows
            </button>
            <button wire:click="$set('section', 'integrations')" class="rounded px-3 py-2 text-left text-sm font-medium transition-colors {{ $section === 'integrations' ? 'bg-surface-active text-hi' : 'text-t3 hover:text-hi' }}">
                Integrations
            </button>
        </nav>
    </aside>

    {{-- Content --}}
    <main class="flex-1 p-8">
        <div class="mx-auto max-w-[760px]">
            @if($section === 'actors')
                <h2 class="mb-6 text-lg font-semibold text-hi">Actors & roles</h2>
                <div class="space-y-6">
                    @foreach($roleDrafts as $id => $draft)
                        @php $role = \App\Models\Role::find($id); @endphp
                        <div class="grid grid-cols-[170px_1fr] gap-4 items-start border-b border-border pb-4">
                            <div class="flex flex-col gap-1">
                                <label class="text-xs font-medium text-t3">Name</label>
                                <input type="text" value="{{ $role->name }}" readonly class="w-full rounded border border-border bg-surface-chip px-2 py-1.5 font-mono text-sm text-hi {{ $role->is_builtin ? 'opacity-60' : '' }}" />
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="text-xs font-medium text-t3">Provider</label>
                                    <select wire:model.live="roleDrafts.{{ $id }}.provider" class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi">
                                        <option value="openrouter">openrouter</option>
                                        <option value="metallama">metallama</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-t3">Model</label>
                                    <input type="text" wire:model.live="roleDrafts.{{ $id }}.model" class="w-full rounded border border-border bg-surface px-2 py-1.5 font-mono text-sm text-hi" />
                                    @error("roleDrafts.{$id}.model") <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-t3">Temperature</label>
                                    <input type="number" step="0.1" wire:model.live="roleDrafts.{{ $id }}.temperature" class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi" />
                                    @error("roleDrafts.{$id}.temperature") <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-t3">Max tokens</label>
                                    <input type="number" wire:model.live="roleDrafts.{{ $id }}.max_tokens" class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi" />
                                    @error("roleDrafts.{$id}.max_tokens") <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                                </div>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button wire:click="saveRole('{{ $id }}')" class="rounded border border-border px-3 py-1.5 text-xs font-medium text-hi hover:bg-surface-chip transition-colors">Save</button>
                            @if(!$role->is_builtin)
                                <button wire:click="deleteRole('{{ $id }}')" wire:confirm="Delete this role?" class="rounded border border-status-failed px-3 py-1.5 text-xs font-medium text-status-failed hover:bg-status-failed/10 transition-colors">Delete</button>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="mt-8 border-t border-border pt-6">
                    <p class="mb-3 text-xs font-medium text-t3">ADD ROLE</p>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="text-xs font-medium text-t3">Name</label>
                            <input type="text" wire:model.live="newRole.name" class="w-full rounded border border-border bg-surface px-2 py-1.5 font-mono text-sm text-hi" />
                            @error('newRole.name') <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="text-xs font-medium text-t3">Provider</label>
                            <select wire:model.live="newRole.provider" class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi">
                                <option value="openrouter">openrouter</option>
                                <option value="metallama">metallama</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-medium text-t3">Model</label>
                            <input type="text" wire:model.live="newRole.model" class="w-full rounded border border-border bg-surface px-2 py-1.5 font-mono text-sm text-hi" />
                            @error('newRole.model') <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <button wire:click="addRole" class="mt-3 rounded border border-border px-3 py-1.5 text-xs font-medium text-hi hover:bg-surface-chip transition-colors">Add</button>
                </div>

                <p class="mt-6 font-mono text-xs text-t3">roles become workflow actors — builders use metallama, thinkers use openrouter</p>
            @elseif($section === 'workflow')
                <h2 class="mb-6 text-lg font-semibold text-hi">Workflow</h2>
                <div class="space-y-6">
                    <div class="grid grid-cols-[170px_1fr] gap-4 items-center">
                        <label class="text-xs font-medium text-t3">Max revisions</label>
                        <div>
                            <input type="number" wire:model.live="workflow.max_revisions" class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi" />
                            @error('workflow.max_revisions') <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-[170px_1fr] gap-4 items-center">
                        <label class="text-xs font-medium text-t3">Overnight spend cap USD</label>
                        <div>
                            <input type="number" step="0.05" wire:model.live="workflow.overnight_spend_cap_usd" class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi" />
                            @error('workflow.overnight_spend_cap_usd') <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <button wire:click="saveWorkflowSettings" class="rounded bg-accent px-4 py-2 text-sm font-medium text-accent-ink hover:opacity-90 transition-opacity">Save</button>
                </div>
            @elseif($section === 'workflows')
                <h2 class="mb-6 text-lg font-semibold text-hi">Workflows</h2>
                <div class="space-y-6">
                    @foreach($workflows as $wf)
                        <div class="flex items-center justify-between border-b border-border pb-4">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-hi">{{ $wf->name }}</span>
                                    @if($wf->is_builtin)
                                        <span class="rounded-[5px] bg-surface-chip px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-[.1em] text-t3">builtin</span>
                                    @endif
                                </div>
                                <p class="mt-1 font-mono text-xs text-t3">{{ implode(' → ', $wf->chain) }}</p>
                            </div>
                            <div class="flex gap-2">
                                @if(!$wf->is_builtin)
                                    <button wire:click="loadWorkflowForEdit({{ $wf->id }})" class="rounded border border-border px-3 py-1.5 text-xs font-medium text-hi hover:bg-surface-chip transition-colors">Load into builder</button>
                                    <button wire:click="deleteWorkflow({{ $wf->id }})" wire:confirm="Delete this workflow?" class="rounded border border-status-failed px-3 py-1.5 text-xs font-medium text-status-failed hover:bg-status-failed/10 transition-colors">Delete</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-8 border-t border-border pt-6">
                    <p class="mb-3 text-xs font-medium text-t3">{{ $editingId ? 'EDIT WORKFLOW' : 'CREATE WORKFLOW' }}</p>
                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-xs font-medium text-t3">Name</label>
                                <input type="text" wire:model.live="workflowName" class="w-full rounded border border-border bg-surface px-2 py-1.5 font-mono text-sm text-hi" />
                                @error('workflowName') <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="text-xs font-medium text-t3">Description</label>
                                <input type="text" wire:model.live="workflowDescription" class="w-full rounded border border-border bg-surface px-2 py-1.5 font-mono text-sm text-hi" />
                            </div>
                        </div>

                        <div>
                            <label class="text-xs font-medium text-t3">Chain</label>
                            <div class="mt-2 space-y-2">
                                @foreach($chainDraft as $i => $step)
                                    <div class="flex items-center gap-2 font-mono text-sm text-hi">
                                        <button wire:click="moveStep({{ $i }}, 'up')" class="text-t3 hover:text-hi disabled:opacity-30" @disabled($i === 0)">↑</button>
                                        <button wire:click="moveStep({{ $i }}, 'down')" class="text-t3 hover:text-hi disabled:opacity-30" @disabled($i === count($chainDraft) - 1)">↓</button>
                                        <button wire:click="removeStep({{ $i }})" class="text-status-failed hover:underline">×</button>
                                        <span>{{ $step }}</span>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-3 flex items-center gap-2">
                                <select wire:model.live="chainPick" class="rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi">
                                    <option value="">Select step…</option>
                                    @foreach($knownTypes as $type)
                                        <option value="{{ $type }}">{{ $type }}</option>
                                    @endforeach
                                </select>
                                <button wire:click="addStep" class="rounded border border-border px-3 py-1.5 text-xs font-medium text-hi hover:bg-surface-chip transition-colors">Add step</button>
                            </div>
                            @error('chainDraft') <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                        </div>

                        <button wire:click="saveWorkflow" class="rounded bg-accent px-4 py-2 text-sm font-medium text-accent-ink hover:opacity-90 transition-opacity">
                            {{ $editingId ? 'Save' : 'Create workflow' }}
                        </button>
                        @if($editingId)
                            <button wire:click="resetWorkflowDraft" class="ml-2 rounded border border-border px-3 py-1.5 text-xs font-medium text-t3 hover:bg-surface-chip transition-colors">Cancel</button>
                        @endif
                    </div>
                    <p class="mt-4 font-mono text-xs text-t3">gates & autonomy come from the run profile; custom AI roles become chain steps in a later milestone</p>
                </div>
            @elseif($section === 'integrations')
                <h2 class="mb-6 text-lg font-semibold text-hi">Integrations</h2>
                <div class="space-y-4">
                    <div class="flex items-center justify-between border-b border-border pb-3">
                        <span class="text-sm font-medium text-hi">Metallama</span>
                        <div class="flex items-center gap-3">
                            <span class="font-mono text-xs text-t3">{{ config('majordom.metallama.base_url', 'N/A') }}</span>
                            <span class="h-2 w-2 rounded-full {{ $metallamaOk ? 'bg-ok' : 'bg-status-failed' }}"></span>
                        </div>
                    </div>
                    <div class="flex items-center justify-between border-b border-border pb-3">
                        <span class="text-sm font-medium text-hi">Telegram</span>
                        <span class="text-sm text-t3">{{ $telegramConfigured ? 'configured' : 'not configured' }}</span>
                    </div>
                    <div class="flex items-center justify-between border-b border-border pb-3">
                        <span class="text-sm font-medium text-hi">Reverb</span>
                        <span class="font-mono text-xs text-t3">{{ $reverbHost ?: 'N/A' }}</span>
                    </div>
                </div>
                <p class="mt-6 font-mono text-xs text-t3">integration credentials live in .env by design</p>
            @endif
        </div>
    </main>
</div>
