<?php

namespace App\Livewire\Staff\Setup;

use App\Enums\StaffUndoKind;
use App\Livewire\Concerns\PresentsUndoToast;
use App\Livewire\Concerns\WithinTenant;
use App\Models\PromoCode;
use App\Services\Staff\StaffUndo;
use DateTimeInterface;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Promo codes')]
class PromoCodes extends Component
{
    use PresentsUndoToast, WithinTenant;

    public string $code = '';

    public string $discountType = 'percent';

    public string $discountValue = '10';

    public string $startsOn = '';

    public string $endsOn = '';

    public string $usageLimit = '';

    public bool $active = true;

    public string $editingId = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize('viewAny', PromoCode::class);
    }

    public function create(): void
    {
        $this->authorize('create', PromoCode::class);
        $promo = PromoCode::query()->create($this->attributes($this->validate($this->rules())));
        $this->audit('promo_code.created', $promo, $this->auditSnapshot($promo));
        $this->resetForm();
        $this->flash = $promo->code.' added.';
    }

    public function edit(string $id): void
    {
        $promo = $this->promo($id);
        $this->authorize('update', $promo);
        $this->editingId = $promo->id;
        $this->code = $promo->code;
        $this->discountType = $promo->percent_off !== null ? 'percent' : 'amount';
        $this->discountValue = $promo->percent_off !== null
            ? (string) $promo->percent_off
            : number_format(((int) $promo->amount_off_cents) / 100, 2, '.', '');
        $this->startsOn = $this->dateValue($promo->starts_on);
        $this->endsOn = $this->dateValue($promo->ends_on);
        $this->usageLimit = $promo->usage_limit === null ? '' : (string) $promo->usage_limit;
        $this->active = $promo->active;
        $this->flash = null;
    }

    public function save(): void
    {
        $promo = $this->promo($this->editingId);
        $this->authorize('update', $promo);
        $before = $this->auditSnapshot($promo);
        $promo->fill($this->attributes($this->validate($this->rules())))->save();
        $this->audit('promo_code.updated', $promo, [
            'before' => $before,
            'after' => $this->auditSnapshot($promo),
        ]);
        $this->resetForm();
        $this->flash = 'Promo code saved.';
    }

    public function deactivate(string $id): void
    {
        $promo = $this->promo($id);
        $this->authorize('update', $promo);

        if (! $promo->active) {
            return;
        }

        $promo->active = false;
        $promo->save();
        $this->audit('promo_code.deactivated', $promo, ['code' => $promo->code]);

        if ($this->editingId === $promo->id) {
            $this->active = false;
        }

        $this->flash = $promo->code.' is off.';
    }

    public function delete(string $id, StaffUndo $undo): void
    {
        $promo = $this->promo($id);
        $this->authorize('delete', $promo);
        $code = $promo->code;
        $promo->delete();
        $this->audit('promo_code.deleted', $promo, ['code' => $code]);

        if ($this->editingId === $promo->id) {
            $this->resetForm();
        }

        $this->rememberUndo($undo->offer(
            $this->currentUser(),
            StaffUndoKind::PromoDelete,
            $code.' removed.',
            null,
            $promo,
            [
                'code' => $code,
                'percent_off' => $promo->percent_off,
                'amount_off_cents' => $promo->amount_off_cents,
                'active' => $promo->active,
            ],
        ));
        $this->flash = $code.' removed.';
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function render(): View
    {
        return view('livewire.staff.setup.promo-codes', [
            'promos' => PromoCode::query()->orderBy('code')->get(),
            'canManage' => $this->allows('inventory.manage'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $this->code = strtoupper(trim($this->code));
        $this->startsOn = trim($this->startsOn);
        $this->endsOn = trim($this->endsOn);
        $this->usageLimit = trim($this->usageLimit);
        $this->discountValue = trim($this->discountValue);

        $unique = Rule::unique('promo_codes', 'code')->where(
            fn ($query) => $query->where('tenant_id', $this->tenant()->id),
        );

        if ($this->editingId !== '') {
            $unique = $unique->ignore($this->editingId);
        }

        $ends = ['nullable', 'date_format:Y-m-d'];

        if ($this->startsOn !== '' && $this->endsOn !== '') {
            $ends[] = 'after_or_equal:startsOn';
        }

        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9-]+$/', $unique],
            'discountType' => ['required', 'in:percent,amount'],
            'discountValue' => $this->discountType === 'percent'
                ? ['required', 'integer', 'between:1,100']
                : ['required', 'numeric', 'min:0.01', 'max:100000'],
            'startsOn' => $this->startsOn === '' ? ['nullable'] : ['date_format:Y-m-d'],
            'endsOn' => $this->endsOn === '' ? ['nullable'] : $ends,
            'usageLimit' => $this->usageLimit === '' ? ['nullable'] : ['integer', 'min:1', 'max:1000000'],
            'active' => ['boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        $percent = $validated['discountType'] === 'percent';
        $cents = (int) round(((float) $validated['discountValue']) * 100);

        return [
            'tenant_id' => $this->tenant()->id,
            'code' => $validated['code'],
            'percent_off' => $percent ? (int) $validated['discountValue'] : null,
            'amount_off_cents' => $percent ? null : $cents,
            'starts_on' => $this->startsOn !== '' ? $this->startsOn : null,
            'ends_on' => $this->endsOn !== '' ? $this->endsOn : null,
            'usage_limit' => $this->usageLimit !== '' ? (int) $this->usageLimit : null,
            'active' => $this->editingId === '' ? true : $this->active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(PromoCode $promo): array
    {
        return [
            'code' => $promo->code,
            'percent_off' => $promo->percent_off,
            'amount_off_cents' => $promo->amount_off_cents,
            'starts_on' => $this->dateValue($promo->starts_on),
            'ends_on' => $this->dateValue($promo->ends_on),
            'usage_limit' => $promo->usage_limit,
            'active' => $promo->active,
        ];
    }

    private function dateValue(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : '';
    }

    private function promo(string $id): PromoCode
    {
        $promo = PromoCode::query()->find($id);
        abort_unless($promo instanceof PromoCode, 404);

        return $promo;
    }

    private function resetForm(): void
    {
        $this->reset('code', 'startsOn', 'endsOn', 'usageLimit', 'editingId');
        $this->discountType = 'percent';
        $this->discountValue = '10';
        $this->active = true;
    }
}
