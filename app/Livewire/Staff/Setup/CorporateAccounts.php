<?php

namespace App\Livewire\Staff\Setup;

use App\Livewire\Concerns\WithinTenant;
use App\Models\CorporateAccount;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Corporate accounts')]
class CorporateAccounts extends Component
{
    use WithinTenant;

    public string $name = '';

    public string $editingId = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize('viewAny', CorporateAccount::class);
    }

    public function create(): void
    {
        $this->authorize('create', CorporateAccount::class);
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('corporate_accounts', 'name')->where('tenant_id', $this->tenant()->id)],
        ]);
        $account = CorporateAccount::query()->create([
            'tenant_id' => $this->tenant()->id,
            'name' => $validated['name'],
            'active' => true,
        ]);
        $this->audit('corporate_account.created', $account, ['name' => $account->name]);
        $this->reset('name');
        $this->flash = $account->name.' added.';
    }

    public function edit(string $id): void
    {
        $account = $this->account($id);
        $this->authorize('update', $account);
        $this->editingId = $account->id;
        $this->name = $account->name;
        $this->flash = null;
    }

    public function save(): void
    {
        $account = $this->account($this->editingId);
        $this->authorize('update', $account);
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('corporate_accounts', 'name')->where('tenant_id', $this->tenant()->id)->ignore($account->id)],
        ]);
        $account->name = $validated['name'];
        $account->save();
        $this->audit('corporate_account.updated', $account, ['name' => $account->name]);
        $this->reset('name', 'editingId');
        $this->flash = 'Account saved.';
    }

    public function deactivate(string $id): void
    {
        $account = $this->account($id);
        $this->authorize('update', $account);

        if (! $account->active) {
            return;
        }

        $account->active = false;
        $account->save();
        $this->audit('corporate_account.deactivated', $account, ['name' => $account->name]);
        $this->flash = $account->name.' is off.';
    }

    public function render(): View
    {
        return view('livewire.staff.setup.corporate-accounts', [
            'accounts' => CorporateAccount::query()->orderBy('name')->get(),
            'canManage' => $this->allows('inventory.manage'),
        ]);
    }

    private function account(string $id): CorporateAccount
    {
        $account = CorporateAccount::query()->find($id);
        abort_unless($account instanceof CorporateAccount, 404);

        return $account;
    }
}
