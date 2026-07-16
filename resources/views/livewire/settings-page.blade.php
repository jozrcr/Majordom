<div class="flex min-h-[calc(100vh-52px)]">
    {{-- Left Nav --}}
    <aside class="w-[224px] border-r border-border p-4">
        <nav class="flex flex-col gap-1">
            <button wire:click="$set('section', 'actors')" class="rounded px-3 py-2 text-left text-sm font-medium transition-colors {{ $section === 'actors' ? 'bg-surface-active text-hi' : 'text-t3 hover:text-hi' }}">
                Actors & roles
            </button>
            <button wire:click="$set('section', 'providers')" class="rounded px-3 py-2 text-left text-sm font-medium transition-colors {{ $section === 'providers' ? 'bg-surface-active text-hi' : 'text-t3 hover:text-hi' }}">
                Providers
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
        <div class="mx-auto max-w-3xl">
            @if($section === 'actors')
                <h2 class="mb-6 text-lg font-semibold text-hi">Actors & roles</h2>
                <div class="space-y-6 ">
                    @foreach($roleDrafts as $id => $draft)
                        @php $role = \App\Models\Role::find($id); @endphp
                        <div class="bg-surface-card p-6 rounded-lg">
                            <div class="grid grid-cols-[170px_1fr] gap-4 items-start border-b border-border pb-4">
                                <div class="flex flex-col gap-1">
                                    <label class="text-xs font-medium text-t3">Name</label>
                                    <input type="text" value="{{ $role->name }}" readonly class="w-full rounded border border-border bg-surface-chip px-2 py-1.5 font-mono text-sm text-hi {{ $role->is_builtin ? 'opacity-60' : '' }}" />
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-xs font-medium text-t3">Provider</label>
                                        <select wire:model.live="roleDrafts.{{ $id }}.provider" class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi">
                                            @foreach($providerOptions as $name => $label)
                                                <option value="{{ $name }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-xs font-medium text-t3">Model</label>
                                        <input type="text" wire:model.live="roleDrafts.{{ $id }}.model" class="w-full rounded border border-border bg-surface px-2 py-1.5 font-mono text-sm text-hi" />
                                        @error("roleDrafts.{$id}.model") <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                                    </div>
                                    <div>
                                        <label class="text-xs font-medium text-t3">Temperature</label>
                                        <input type="number" step="0.1" wire:model.live="roleDrafts.{{ $id }}.temperature" class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi {{ $draft['knobs_inert'] ? 'opacity-50 cursor-not-allowed' : '' }}" {{ $draft['knobs_inert'] ? 'disabled' : '' }} />
                                        @error("roleDrafts.{$id}.temperature") <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                                    </div>
                                    <div>
                                        <label class="text-xs font-medium text-t3">Max tokens</label>
                                        <input type="number" wire:model.live="roleDrafts.{{ $id }}.max_tokens" class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi" />
                                        @error("roleDrafts.{$id}.max_tokens") <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                                    </div>
                                </div>
                            </div>
                            <div class="mt-2">
                                <div x-data="{ open: false }">
                                    <button @click="open = !open" class="flex items-center gap-1 text-xs font-medium text-t3 hover:text-hi transition-colors">
                                        <span x-text="open ? '▾' : '▸'"></span> Developer
                                    </button>
                                    <div x-show="open" x-transition class="mt-3 space-y-4">
                                        <div>
                                            <label class="text-xs font-medium text-t3">
                                                {{ $role->name === 'builder' || ($role->meta['managed_model'] ?? false) 
                                                    ? 'Additional instructions (injected into the task message)' 
                                                    : 'Additional system instructions (appended to built-in prompt)' }}
                                            </label>
                                            <textarea 
                                                wire:model.live="roleDrafts.{{ $id }}.{{ $role->name === 'builder' || ($role->meta['managed_model'] ?? false) ? 'extra_instructions' : 'system_prompt_extra' }}" 
                                                rows="3"
                                                class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi"
                                            ></textarea>
                                        </div>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <label class="text-xs font-medium text-t3">Top P</label>
                                                <input type="number" step="0.01" wire:model.live="roleDrafts.{{ $id }}.top_p" class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi {{ $draft['knobs_inert'] ? 'opacity-50 cursor-not-allowed' : '' }}" {{ $draft['knobs_inert'] ? 'disabled' : '' }} />
                                            </div>
                                            <div>
                                                <label class="text-xs font-medium text-t3">Frequency penalty</label>
                                                <input type="number" step="0.1" wire:model.live="roleDrafts.{{ $id }}.frequency_penalty" class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi {{ $draft['knobs_inert'] ? 'opacity-50 cursor-not-allowed' : '' }}" {{ $draft['knobs_inert'] ? 'disabled' : '' }} />
                                            </div>
                                            <div>
                                                <label class="text-xs font-medium text-t3">Presence penalty</label>
                                                <input type="number" step="0.1" wire:model.live="roleDrafts.{{ $id }}.presence_penalty" class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi {{ $draft['knobs_inert'] ? 'opacity-50 cursor-not-allowed' : '' }}" {{ $draft['knobs_inert'] ? 'disabled' : '' }} />
                                            </div>
                                            <div>
                                                <label class="text-xs font-medium text-t3">Timeout (s)</label>
                                                <input type="number" wire:model.live="roleDrafts.{{ $id }}.timeout" class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi" />
                                            </div>
                                        </div>
                                        @if($draft['knobs_inert'])
                                            <p class="text-[10px] text-mute">Sampler params are preset by metallama for this provider — these fields have no effect.</p>
                                        @endif
                                        <div>
                                            <label class="text-xs font-medium text-t3">Stop sequences (comma-separated)</label>
                                            <input type="text" wire:model.live="roleDrafts.{{ $id }}.stop" class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi {{ $draft['knobs_inert'] ? 'opacity-50 cursor-not-allowed' : '' }}" {{ $draft['knobs_inert'] ? 'disabled' : '' }} />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                @if(!$role->is_builtin)
                                    <button wire:click="deleteRole('{{ $id }}')" wire:confirm="Delete this role?" class="rounded border border-status-failed px-3 py-1.5 text-xs font-medium text-status-failed hover:bg-status-failed/10 transition-colors">Delete</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button wire:click="saveAllRoles" class="rounded bg-accent px-4 py-2 text-sm font-medium text-accent-ink hover:opacity-90 transition-opacity">
                        <span wire:loading.remove wire:target="saveAllRoles">Save all roles</span>
                        <span wire:loading wire:target="saveAllRoles">Saving…</span>
                    </button>
                    @if($justSaved === 'roles')
                        <span class="text-xs font-medium text-status-completed">Saved ✓</span>
                    @endif
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
                                @foreach($providerOptions as $name => $label)
                                    <option value="{{ $name }}">{{ $label }}</option>
                                @endforeach
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
            @elseif($section === 'providers')
                <h2 class="mb-6 text-lg font-semibold text-hi">Providers</h2>
                <div class="space-y-6">
                    @foreach($endpointDrafts as $id => $draft)
                        <div class="rounded-lg border border-border bg-surface-raised p-4">
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-semibold text-hi">{{ $draft['label'] }}</p>
                                    <span class="font-mono text-xs text-t3">{{ $draft['name'] }}</span>
                                    <span class="rounded bg-surface-chip px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-[.1em] text-t3">{{ $draft['driver'] }}</span>
                                    @if($draft['is_builtin'])
                                        <span class="rounded bg-surface-chip px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-[.1em] text-t3">builtin</span>
                                    @endif
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="text-xs font-medium text-t3">Label</label>
                                    <input type="text" wire:model.live="endpointDrafts.{{ $id }}.label" class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi" />
                                    @error("endpointDrafts.{$id}.label") <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-t3">Base URL</label>
                                    <input type="text" wire:model.live="endpointDrafts.{{ $id }}.base_url" class="w-full rounded border border-border bg-surface px-2 py-1.5 font-mono text-sm text-hi" />
                                    @error("endpointDrafts.{$id}.base_url") <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-t3">Timeout (s)</label>
                                    <input type="number" wire:model.live="endpointDrafts.{{ $id }}.timeout" class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi" />
                                    @error("endpointDrafts.{$id}.timeout") <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-t3">API Key</label>
                                    <div class="flex items-center gap-2">
                                        @if($draft['key_source'] === 'db')
                                            <span class="text-xs text-status-completed">Key set ✓</span>
                                            <button wire:click="startChangeKey('{{ $id }}')" class="rounded border border-border px-2 py-1 text-xs text-hi hover:bg-surface-chip transition-colors">Change API key</button>
                                            <button wire:click="clearEndpointKey('{{ $id }}')" class="rounded border border-border px-2 py-1 text-xs text-mute hover:text-hi hover:bg-surface-chip transition-colors">clear</button>
                                        @elseif($draft['key_source'] === 'env')
                                            <span class="rounded bg-surface-chip px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-[.1em] text-t3">from env ({{ $draft['key_config'] }})</span>
                                        @else
                                            <span class="text-xs text-mute">No key</span>
                                            <button wire:click="startChangeKey('{{ $id }}')" class="rounded border border-border px-2 py-1 text-xs text-hi hover:bg-surface-chip transition-colors">Change API key</button>
                                        @endif
                                    </div>
                                    @if($changingKey[$id] ?? false)
                                        <div class="mt-2 flex gap-2">
                                            <input type="password" wire:model.live="endpointDrafts.{{ $id }}.api_key" placeholder="Enter new API key" class="flex-1 rounded border border-border bg-surface px-2 py-1.5 font-mono text-sm text-hi" />
                                            <button wire:click="cancelChangeKey('{{ $id }}')" class="rounded border border-border px-2 py-1.5 text-xs text-mute hover:text-hi hover:bg-surface-chip transition-colors">Cancel</button>
                                        </div>
                                    @endif
                                    @error("endpointDrafts.{$id}.api_key") <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                                </div>
                            </div>
                            <div class="mt-4 flex items-center gap-2">
                                <button wire:click="saveEndpoint('{{ $id }}')" class="rounded bg-accent px-4 py-2 text-sm font-medium text-accent-ink hover:opacity-90 transition-opacity">
                                    <span wire:loading.remove wire:target="saveEndpoint">Save</span>
                                    <span wire:loading wire:target="saveEndpoint">Saving…</span>
                                </button>
                                @if($justSaved === "endpoint:{$id}")
                                    <span class="text-xs font-medium text-status-completed">Saved ✓</span>
                                @endif
                                <button wire:click="testEndpoint('{{ $id }}')" class="rounded border border-border px-3 py-2 text-xs font-medium text-hi hover:bg-surface-chip transition-colors">Test</button>
                                @if(isset($endpointTestResults[$id]))
                                    <span class="text-xs font-medium {{ $endpointTestResults[$id] === 'ok' ? 'text-status-completed' : 'text-status-failed' }}">
                                        {{ $endpointTestResults[$id] === 'ok' ? 'ok' : 'fail' }}
                                    </span>
                                @endif
                                @if(!$draft['is_builtin'])
                                    <button wire:click="deleteEndpoint('{{ $id }}')" wire:confirm="Delete this provider?" class="rounded border border-status-failed px-3 py-1.5 text-xs font-medium text-status-failed hover:bg-status-failed/10 transition-colors">Delete</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-8 rounded-lg border border-border bg-surface-raised p-4">
                    <p class="mb-4 text-xs font-medium tracking-[.1em] text-t3">ADD PROVIDER</p>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-xs font-medium text-t3">Name</label>
                            <input type="text" wire:model.live="newEndpoint.name" class="w-full rounded border border-border bg-surface px-2 py-1.5 font-mono text-sm text-hi" />
                            @error('newEndpoint.name') <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="text-xs font-medium text-t3">Label</label>
                            <input type="text" wire:model.live="newEndpoint.label" class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi" />
                            @error('newEndpoint.label') <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="text-xs font-medium text-t3">Driver</label>
                            <select wire:model.live="newEndpoint.driver" class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi">
                                <option value="openai_compatible">OpenAI-compatible — Ollama /v1, LM Studio, vLLM, llama.cpp server, OpenRouter…</option>
                                <option value="metallama">metallama-managed local model</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-medium text-t3">Base URL</label>
                            <input type="text" wire:model.live="newEndpoint.base_url" class="w-full rounded border border-border bg-surface px-2 py-1.5 font-mono text-sm text-hi" />
                            @error('newEndpoint.base_url') <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-span-2">
                            <label class="text-xs font-medium text-t3">API Key</label>
                            <input type="password" wire:model.live="newEndpoint.api_key" placeholder="optional" class="w-full rounded border border-border bg-surface px-2 py-1.5 font-mono text-sm text-hi" />
                            @error('newEndpoint.api_key') <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <button wire:click="addEndpoint" class="mt-3 rounded border border-border px-3 py-1.5 text-xs font-medium text-hi hover:bg-surface-chip transition-colors">Add provider</button>
                </div>
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
                    <div class="grid grid-cols-[170px_1fr] gap-4 items-start">
                        <div class="flex items-center gap-2 pt-1">
                            <input type="checkbox" wire:model.live="workflow.push_after_merge" class="rounded border-border bg-surface text-accent focus:ring-accent h-4 w-4" />
                            <label class="text-xs font-medium text-t3">Push to remote after milestone merge</label>
                        </div>
                        <div class="text-xs text-mute pt-1">
                            Off by default. When on, a merged milestone is pushed to the configured git remote. No remote = no-op.
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <button wire:click="saveWorkflowSettings" class="rounded bg-accent px-4 py-2 text-sm font-medium text-accent-ink hover:opacity-90 transition-opacity">Save</button>
                        @if($justSaved === 'workflow-settings')
                            <span class="text-xs font-medium text-status-completed">Saved ✓</span>
                        @endif
                    </div>
                </div>
            @elseif($section === 'workflows')
                <h2 class="mb-6 text-lg font-semibold text-hi">Workflows</h2>

                <div class="space-y-4">
                    @foreach($workflows as $wf)
                        <div class="rounded-lg border {{ $editingId === $wf->id ? 'border-accent' : 'border-border' }} bg-surface-raised p-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-semibold text-hi">{{ $wf->name }}</p>
                                    @if($wf->is_builtin)
                                        <span class="rounded bg-surface-chip px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-[.1em] text-t3">builtin</span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2">
                                    <button wire:click="loadWorkflowForEdit({{ $wf->id }})" class="rounded border border-border px-3 py-1 text-xs font-medium text-hi hover:bg-surface-chip transition-colors">Edit</button>
                                    @if(!$wf->is_builtin)
                                        <button wire:click="deleteWorkflow({{ $wf->id }})" wire:confirm="Delete this workflow?" class="rounded border border-status-failed px-3 py-1 text-xs font-medium text-status-failed hover:bg-status-failed/10 transition-colors">Delete</button>
                                    @endif
                                </div>
                            </div>
                            @if($wf->description)
                                <p class="mt-1 text-xs text-mute">{{ $wf->description }}</p>
                            @endif
                            <div class="mt-3 flex flex-wrap items-center gap-1.5">
                                @foreach(\App\Core\Workflow\ChainStep::normalize($wf->chain) as $s)
                                    @if(!$loop->first)<span class="font-mono text-xs text-t3">&rarr;</span>@endif
                                    <span class="rounded border border-border bg-surface px-2 py-0.5 font-mono text-xs text-hi">{{ $s->type }}@if($s->role && $s->role !== 'system')<span class="text-t3"> &middot; {{ $s->role }}</span>@endif</span>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                    @error('workflow') <p class="text-xs text-status-failed">{{ $message }}</p> @enderror
                </div>

                <div class="mt-8 rounded-lg border border-border bg-surface-raised p-4">
                    <p class="mb-4 text-xs font-medium tracking-[.1em] text-t3">{{ $editingId ? 'EDIT WORKFLOW' : 'CREATE WORKFLOW' }}</p>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-xs font-medium text-t3">Name</label>
                            <input type="text" wire:model.live="workflowName" class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi" />
                            @error('workflowName') <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="text-xs font-medium text-t3">Description</label>
                            <input type="text" wire:model.live="workflowDescription" class="w-full rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi" />
                        </div>
                    </div>

                    <div class="mt-5">
                        <label class="text-xs font-medium text-t3">Chain</label>
                        <div class="mt-2 space-y-2">
                            @forelse($chainDraft as $i => $step)
                                @php $step = is_array($step) ? $step : ['type' => $step, 'role' => 'system', 'config' => []]; @endphp
                                <div class="flex items-center gap-2 rounded border border-border bg-surface px-3 py-2">
                                    <span class="w-5 font-mono text-xs text-t3">{{ $i + 1 }}</span>
                                    <span class="min-w-[130px] font-mono text-sm text-hi">{{ $step['type'] }}</span>
                                    <select wire:model.live="chainDraft.{{ $i }}.role" class="rounded border border-border bg-surface-raised px-2 py-1 text-xs text-hi">
                                        <option value="system">system</option>
                                        @foreach($availableRoles as $r)
                                            <option value="{{ $r }}">{{ $r }}</option>
                                        @endforeach
                                    </select>
                                    @if(in_array($step['type'], ['human_task', 'human_review'], true))
                                        <input type="text" wire:model.live="chainDraft.{{ $i }}.config.instructions" placeholder="instructions&hellip;" maxlength="500" class="flex-1 rounded border border-border bg-surface-raised px-2 py-1 text-xs text-hi" />
                                        <select wire:model.live="chainDraft.{{ $i }}.config.rescue_role" class="rounded border border-border bg-surface-raised px-2 py-1 text-xs text-hi">
                                            <option value="">rescue: none</option>
                                            @foreach($availableRoles as $r)
                                                <option value="{{ $r }}">rescue: {{ $r }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                    <div class="ml-auto flex items-center gap-1">
                                        <button wire:click="moveStep({{ $i }}, 'up')" @disabled($i === 0) class="rounded border border-border px-1.5 py-0.5 text-xs text-mute hover:text-hi disabled:opacity-30">&uarr;</button>
                                        <button wire:click="moveStep({{ $i }}, 'down')" @disabled($i === count($chainDraft) - 1) class="rounded border border-border px-1.5 py-0.5 text-xs text-mute hover:text-hi disabled:opacity-30">&darr;</button>
                                        <button wire:click="removeStep({{ $i }})" class="rounded border border-border px-1.5 py-0.5 text-xs text-status-failed hover:bg-status-failed/10">&times;</button>
                                    </div>
                                </div>
                            @empty
                                <p class="rounded border border-dashed border-border px-3 py-4 text-center text-xs text-mute">No steps yet &mdash; pick one below to start the chain.</p>
                            @endforelse
                        </div>
                        @error('chainDraft') <span class="text-xs text-status-failed">{{ $message }}</span> @enderror
                    </div>

                    <div class="mt-3 flex items-center gap-2">
                        <select wire:model.live="chainPick" class="rounded border border-border bg-surface px-2 py-1.5 text-sm text-hi">
                            <option value="">Select step&hellip;</option>
                            @foreach($knownTypes as $type)
                                <option value="{{ $type }}">{{ $type }}</option>
                            @endforeach
                        </select>
                        <button wire:click="addStep" class="rounded border border-border px-3 py-1.5 text-xs font-medium text-hi hover:bg-surface-chip transition-colors">Add step</button>
                    </div>

                    <div class="mt-5 flex items-center gap-2">
                        <button wire:click="saveWorkflow" class="rounded bg-accent px-4 py-2 text-sm font-medium text-accent-ink hover:opacity-90 transition-opacity">{{ $editingId ? 'Save workflow' : 'Create workflow' }}</button>
                        @if($editingId || count($chainDraft))
                            <button wire:click="resetWorkflowDraft" class="rounded border border-border px-3 py-2 text-xs font-medium text-mute hover:bg-surface-chip transition-colors">Cancel</button>
                        @endif
                    </div>

                    <p class="mt-4 font-mono text-xs text-t3">gates &amp; autonomy come from the run profile; custom AI roles become chain steps in a later milestone</p>
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
