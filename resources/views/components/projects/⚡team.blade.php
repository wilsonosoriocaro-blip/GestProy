<?php

use App\Actions\Projects\ManageProjectMembers;
use App\Enums\ProjectMemberRole;
use App\Models\Project;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public Project $project;

    public string $userId = '';

    public string $role = 'member';

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->project->members()->orderBy('name')->get(['users.id', 'users.name']);
    }

    /**
     * People who can still be added: not the owner and not already in the team.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function candidates(): Collection
    {
        return User::query()
            ->whereKeyNot($this->project->owner_id)
            ->whereNotIn('id', $this->members->modelKeys())
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function add(ManageProjectMembers $members): void
    {
        $this->authorize('manageMembers', $this->project);

        $this->validate([
            'userId' => ['required', 'integer', Rule::exists('users', 'id'), Rule::notIn([$this->project->owner_id])],
            'role' => ['required', Rule::enum(ProjectMemberRole::class)],
        ], attributes: ['userId' => 'persona', 'role' => 'rol']);

        $user = User::findOrFail((int) $this->userId);
        $members->add($this->project, $user, ProjectMemberRole::from($this->role));

        $this->reset('userId', 'role');
        unset($this->members, $this->candidates);
        Flux::toast(variant: 'success', text: "{$user->name} agregado al equipo.");
    }

    public function remove(int $userId, ManageProjectMembers $members): void
    {
        $this->authorize('manageMembers', $this->project);

        $members->remove($this->project, User::findOrFail($userId));

        unset($this->members, $this->candidates);
    }
}; ?>

<div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
    <flux:heading level="2" size="lg">Equipo</flux:heading>

    <ul class="mt-3 flex flex-col gap-2 text-sm">
        <li class="flex items-center gap-2">
            <flux:avatar size="xs" :name="$project->owner->name" :initials="$project->owner->initials()" />
            <span class="font-medium">{{ $project->owner->name }}</span>
            <flux:badge size="sm" color="blue" class="ms-auto">Responsable</flux:badge>
        </li>

        @foreach ($this->members as $member)
            <li class="flex items-center gap-2" wire:key="member-{{ $member->id }}">
                <flux:avatar size="xs" :name="$member->name" :initials="$member->initials()" />
                <span>{{ $member->name }}</span>
                <flux:badge size="sm" class="ms-auto">{{ $member->membership->role->label() }}</flux:badge>
                @can('manageMembers', $project)
                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="remove({{ $member->id }})"
                        wire:confirm="¿Retirar a {{ $member->name }} del equipo?" aria-label="Retirar a {{ $member->name }}" />
                @endcan
            </li>
        @endforeach
    </ul>

    @can('manageMembers', $project)
        @if ($this->candidates->isNotEmpty())
            <form wire:submit="add" class="mt-4 flex flex-col gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:select wire:model="userId" aria-label="Persona">
                    <flux:select.option value="">Agregar persona…</flux:select.option>
                    @foreach ($this->candidates as $candidate)
                        <flux:select.option :value="$candidate->id" wire:key="candidate-{{ $candidate->id }}">{{ $candidate->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="flex gap-2">
                    <flux:select wire:model="role" aria-label="Rol" class="flex-1">
                        @foreach (App\Enums\ProjectMemberRole::cases() as $option)
                            <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button type="submit" icon="user-plus">Agregar</flux:button>
                </div>
            </form>
        @endif
    @endcan
</div>
