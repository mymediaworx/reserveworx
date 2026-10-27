<?php

namespace App\Livewire\Staff\Setup;

use App\Enums\AddOnKind;
use App\Enums\AddOnPricing;
use App\Livewire\Concerns\WithinTenant;
use App\Models\AddOn;
use App\Models\BookingAddOn;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Add-ons')]
class AddOns extends Component
{
    use WithinTenant;

    public string $name = '';

    public string $description = '';

    public string $kind = 'addon';

    public string $pricing = 'flat';

    public string $amount = '';

    public string $maxQuantity = '';

    public bool $active = true;

    public string $editingId = '';

    public ?string $flash = null;

    public ?string $problem = null;

    public function mount(): void
    {
        $this->authorize('viewAny', AddOn::class);
    }

    public function create(): void
    {
        $this->authorize('create', AddOn::class);
        $validated = $this->validate($this->rules());
        $addOn = new AddOn($this->attributes($validated));

        try {
            $addOn->save();
        } catch (QueryException $exception) {
            $this->rethrowDuplicateName($exception);
        }

        $this->audit('add_on.created', $addOn, $this->auditSnapshot($addOn));
        $this->resetForm();
        $this->flash = $addOn->name.' added.';
        $this->problem = null;
    }

    public function edit(string $id): void
    {
        $addOn = $this->addOn($id);
        $this->authorize('update', $addOn);
        $this->editingId = $addOn->id;
        $this->name = $addOn->name;
        $this->description = (string) $addOn->description;
        $this->kind = $addOn->kind->value;
        $this->pricing = $addOn->pricing->value;
        $this->amount = number_format($addOn->amount_cents / 100, 2, '.', '');
        $this->maxQuantity = $addOn->max_quantity === null ? '' : (string) $addOn->max_quantity;
        $this->active = $addOn->active;
        $this->flash = null;
        $this->problem = null;
    }

    public function save(): void
    {
        $addOn = $this->addOn($this->editingId);
        $this->authorize('update', $addOn);
        $before = $this->auditSnapshot($addOn);
        $addOn->fill($this->attributes($this->validate($this->rules())));

        try {
            $addOn->save();
        } catch (QueryException $exception) {
            $this->rethrowDuplicateName($exception);
        }

        $this->audit('add_on.updated', $addOn, [
            'before' => $before,
            'after' => $this->auditSnapshot($addOn),
        ]);
        $this->resetForm();
        $this->flash = 'Add-on saved.';
        $this->problem = null;
    }

    public function deactivate(string $id): void
    {
        $addOn = $this->addOn($id);
        $this->authorize('update', $addOn);

        if (! $addOn->active) {
            return;
        }

        $addOn->active = false;
        $addOn->save();
        $this->audit('add_on.updated', $addOn, ['name' => $addOn->name, 'active' => false]);

        if ($this->editingId === $addOn->id) {
            $this->active = false;
        }

        $this->flash = $addOn->name.' is off.';
        $this->problem = null;
    }

    public function delete(string $id): void
    {
        $addOn = $this->addOn($id);
        $this->authorize('delete', $addOn);

        if (BookingAddOn::query()->where('add_on_id', $addOn->id)->exists()) {
            $this->problem = 'This add-on is on a booking. Mark it inactive instead.';
            $this->flash = null;

            return;
        }

        $name = $addOn->name;
        $this->audit('add_on.deleted', $addOn, ['name' => $name]);
        $addOn->delete();

        if ($this->editingId === $addOn->id) {
            $this->resetForm();
        }

        $this->flash = $name.' removed.';
        $this->problem = null;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function render(): View
    {
        return view('livewire.staff.setup.add-ons', [
            'addOns' => AddOn::query()->orderBy('name')->orderBy('id')->get(),
            'canManage' => $this->allows('inventory.manage'),
            'kinds' => [
                AddOnKind::Addon->value => 'Add-on',
                AddOnKind::Equipment->value => 'Equipment',
            ],
            'prices' => [
                AddOnPricing::Flat->value => 'Once per booking',
                AddOnPricing::PerPerson->value => 'Per person',
                AddOnPricing::Each->value => 'Sold each',
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $this->name = trim($this->name);
        $this->description = trim($this->description);
        $this->amount = trim($this->amount);
        $this->maxQuantity = trim($this->maxQuantity);

        $unique = Rule::unique('add_ons', 'name')->where(
            fn ($query) => $query->where('tenant_id', $this->tenant()->id),
        );

        if ($this->editingId !== '') {
            $unique = $unique->ignore($this->editingId);
        }

        return [
            'name' => ['required', 'string', 'max:120', $unique],
            'description' => ['nullable', 'string', 'max:500'],
            'kind' => ['required', Rule::enum(AddOnKind::class)],
            'pricing' => ['required', Rule::enum(AddOnPricing::class)],
            'amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/', function (string $attribute, mixed $value, Closure $fail): void {
                $cents = is_string($value) ? $this->cents($value) : null;

                if ($cents === null || $cents > 1_000_000) {
                    $fail('Enter a price from $0.00 to $10,000.00.');
                }
            }],
            'maxQuantity' => ['nullable', 'integer', 'between:1,100', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->pricing !== AddOnPricing::Each->value && $value !== null && $value !== '') {
                    $fail('Only an each-priced add-on has a maximum quantity.');
                }
            }],
            'active' => ['boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        $pricing = AddOnPricing::from((string) $validated['pricing']);
        $cents = $this->cents((string) $validated['amount']);

        return [
            'tenant_id' => $this->tenant()->id,
            'name' => $validated['name'],
            'description' => $validated['description'] !== '' ? $validated['description'] : null,
            'kind' => AddOnKind::from((string) $validated['kind']),
            'pricing' => $pricing,
            'amount_cents' => $cents ?? 0,
            'active' => $this->editingId === '' ? true : (bool) $validated['active'],
            'max_quantity' => $pricing === AddOnPricing::Each && $this->maxQuantity !== '' ? (int) $this->maxQuantity : null,
        ];
    }

    /**
     * @return array{name: string, kind: string, pricing: string, amount_cents: int, active: bool, max_quantity: int|null}
     */
    private function auditSnapshot(AddOn $addOn): array
    {
        return [
            'name' => $addOn->name,
            'kind' => $addOn->kind->value,
            'pricing' => $addOn->pricing->value,
            'amount_cents' => $addOn->amount_cents,
            'active' => $addOn->active,
            'max_quantity' => $addOn->max_quantity,
        ];
    }

    private function cents(string $amount): ?int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $match) !== 1) {
            return null;
        }

        $fraction = str_pad($match[2] ?? '', 2, '0');

        return ((int) $match[1] * 100) + (int) $fraction;
    }

    private function addOn(string $id): AddOn
    {
        $addOn = AddOn::query()->find($id);
        abort_unless($addOn instanceof AddOn, 404);

        return $addOn;
    }

    private function resetForm(): void
    {
        $this->reset('name', 'description', 'kind', 'pricing', 'amount', 'maxQuantity', 'active', 'editingId');
        $this->kind = AddOnKind::Addon->value;
        $this->pricing = AddOnPricing::Flat->value;
        $this->active = true;
    }

    private function rethrowDuplicateName(QueryException $exception): never
    {
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            $this->addError('name', 'An add-on with that name already exists.');
            throw ValidationException::withMessages([
                'name' => 'An add-on with that name already exists.',
            ]);
        }

        throw $exception;
    }
}
