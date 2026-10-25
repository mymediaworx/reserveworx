<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\WithinTenant;
use App\Models\Location;
use App\Models\LocationTaxRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Tax rates')]
class LocationTax extends Component
{
    use WithinTenant;

    #[Locked]
    public string $locationId = '';

    public string $name = '';

    public string $percent = '';

    public bool $appliesToRentals = true;

    public bool $appliesToAddOns = false;

    public bool $appliesToFees = false;

    public string $editingId = '';

    public ?string $flash = null;

    public function mount(Location $location): void
    {
        $this->authorize('viewAny', LocationTaxRule::class);
        abort_unless($location->tenant_id === $this->tenant()->id, 404);
        $this->locationId = $location->id;
    }

    public function create(): void
    {
        $this->authorize('create', LocationTaxRule::class);
        $rule = LocationTaxRule::query()->create($this->attributes());
        $this->audit('location_tax_rule.created', $rule, $this->auditSnapshot($rule));
        $this->resetForm();
        $this->flash = $rule->name.' added.';
    }

    public function edit(string $id): void
    {
        $rule = $this->rule($id);
        $this->authorize('update', $rule);
        $this->editingId = $rule->id;
        $this->name = $rule->name;
        $this->percent = $rule->percentLabel();
        $this->appliesToRentals = $rule->applies_to_rentals;
        $this->appliesToAddOns = $rule->applies_to_add_ons;
        $this->appliesToFees = $rule->applies_to_fees;
        $this->flash = null;
    }

    public function save(): void
    {
        $rule = $this->rule($this->editingId);
        $this->authorize('update', $rule);
        $before = $this->auditSnapshot($rule);
        $rule->fill($this->attributes())->save();
        $this->audit('location_tax_rule.updated', $rule, [
            'before' => $before,
            'after' => $this->auditSnapshot($rule),
        ]);
        $this->resetForm();
        $this->flash = 'Tax rate saved.';
    }

    public function remove(string $id): void
    {
        $rule = $this->rule($id);
        $this->authorize('delete', $rule);
        $snapshot = $this->auditSnapshot($rule);
        $rule->delete();
        $this->audit('location_tax_rule.deleted', $rule, $snapshot);

        if ($this->editingId === $id) {
            $this->resetForm();
        }

        $this->flash = $snapshot['name'].' removed.';
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function render(): View
    {
        $location = $this->location();

        return view('livewire.staff.location-tax', [
            'location' => $location,
            'rules' => LocationTaxRule::query()
                ->where('location_id', $location->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(),
        ])->title('Tax · '.$location->name);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(): array
    {
        $validated = $this->validate($this->rules());
        $basisPoints = $this->basisPoints(is_string($validated['percent']) ? $validated['percent'] : '');

        if ($basisPoints === null) {
            throw ValidationException::withMessages([
                'percent' => 'Enter a percent from 0.01 to 100, with up to two decimal places.',
            ]);
        }

        if (! $this->appliesToRentals && ! $this->appliesToAddOns && ! $this->appliesToFees) {
            throw ValidationException::withMessages([
                'appliesToRentals' => 'Choose at least one of rentals, add-ons, or fees.',
            ]);
        }

        return [
            'tenant_id' => $this->tenant()->id,
            'location_id' => $this->location()->id,
            'name' => trim($this->name),
            'percent_bps' => $basisPoints,
            'applies_to_rentals' => $this->appliesToRentals,
            'applies_to_add_ons' => $this->appliesToAddOns,
            'applies_to_fees' => $this->appliesToFees,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $this->name = trim($this->name);
        $this->percent = trim($this->percent);

        $unique = Rule::unique('location_tax_rules', 'name')->where(
            fn ($query) => $query->where('location_id', $this->locationId),
        );

        if ($this->editingId !== '') {
            $unique = $unique->ignore($this->editingId);
        }

        return [
            'name' => ['required', 'string', 'max:80', $unique],
            'percent' => ['required', 'string', 'max:6', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || $this->basisPoints($value) === null) {
                    $fail('Enter a percent from 0.01 to 100, with up to two decimal places.');
                }
            }],
            'appliesToRentals' => ['boolean'],
            'appliesToAddOns' => ['boolean'],
            'appliesToFees' => ['boolean'],
        ];
    }

    private function basisPoints(string $percent): ?int
    {
        if (preg_match('/^(?:100(?:\.0{1,2})?|\d{1,2}(?:\.\d{1,2})?)$/', $percent) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $percent, 2), 2, '0');
        $basisPoints = ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        if ($basisPoints < 1 || $basisPoints > 10_000) {
            return null;
        }

        return $basisPoints;
    }

    /**
     * @return array{name: string, percent_bps: int, applies_to_rentals: bool, applies_to_add_ons: bool, applies_to_fees: bool}
     */
    private function auditSnapshot(LocationTaxRule $rule): array
    {
        return [
            'name' => $rule->name,
            'percent_bps' => $rule->percent_bps,
            'applies_to_rentals' => $rule->applies_to_rentals,
            'applies_to_add_ons' => $rule->applies_to_add_ons,
            'applies_to_fees' => $rule->applies_to_fees,
        ];
    }

    private function location(): Location
    {
        $location = Location::query()->find($this->locationId);
        abort_unless($location instanceof Location, 404);

        return $location;
    }

    private function rule(string $id): LocationTaxRule
    {
        $rule = LocationTaxRule::query()
            ->where('location_id', $this->locationId)
            ->find($id);
        abort_unless($rule instanceof LocationTaxRule, 404);

        return $rule;
    }

    private function resetForm(): void
    {
        $this->reset('name', 'percent', 'editingId');
        $this->appliesToRentals = true;
        $this->appliesToAddOns = false;
        $this->appliesToFees = false;
    }
}
