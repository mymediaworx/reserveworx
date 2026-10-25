<?php

namespace App\Livewire\Platform;

use App\Enums\Industry;
use App\Enums\TenantStatus;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\WorkspaceProvisioner;
use App\Tenancy\TenantScope;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every workspace on the platform. Mirrors POST /api/v1/platform/tenants.
 */
#[Layout('layouts.platform')]
#[Title('Workspaces')]
class Tenants extends Component
{
    use WithPagination;

    public string $name = '';

    public string $slug = '';

    public string $industry = 'boat_rentals';

    public string $search = '';

    public string $status = '';

    public string $industryFilter = '';

    public function mount(): void
    {
        $this->guard();
    }

    public function updatedName(string $value): void
    {
        $this->slug = Str::slug($value);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(string $value): void
    {
        if ($value !== '' && TenantStatus::tryFrom($value) === null) {
            $this->status = '';
        }

        $this->resetPage();
    }

    public function updatedIndustryFilter(string $value): void
    {
        if ($value !== '' && Industry::tryFrom($value) === null) {
            $this->industryFilter = '';
        }

        $this->resetPage();
    }

    public function filterStatus(string $status): void
    {
        $this->status = $this->status === $status ? '' : $status;
        $this->resetPage();
    }

    public function filterIndustry(string $industry): void
    {
        $this->industryFilter = $this->industryFilter === $industry ? '' : $industry;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->industryFilter = '';
        $this->resetPage();
    }

    public function create(WorkspaceProvisioner $workspaces): void
    {
        $actor = $this->guard();
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:tenants,slug'],
            'industry' => ['required', 'string', Rule::enum(Industry::class)],
        ]);

        $tenant = $workspaces->create($this->name, $this->slug, $actor, request(), Industry::from($this->industry));

        $this->redirect('/platform/tenants/'.$tenant->id, navigate: true);
    }

    public function render(): View
    {
        $term = trim($this->search);
        $status = TenantStatus::tryFrom($this->status);
        $industry = Industry::tryFrom($this->industryFilter);
        $tenants = Tenant::query()
            ->when($term !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('name', 'like', '%'.$term.'%')->orWhere('slug', 'like', '%'.$term.'%')))
            ->when($status instanceof TenantStatus, fn ($q) => $q->where('status', $status))
            ->when($industry instanceof Industry, fn ($q) => $q->where('industry', $industry))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.platform.tenants', [
            'tenants' => $tenants,
            'operatorCount' => Tenant::query()->count(),
            'filtering' => $term !== '' || $status instanceof TenantStatus || $industry instanceof Industry,
            'statuses' => TenantStatus::cases(),
            'industries' => Industry::cases(),
            'statusCounts' => $this->counts('status'),
            'industryCounts' => $this->counts('industry'),
            'primaryDomains' => Domain::withoutGlobalScope(TenantScope::class)
                ->whereIn('tenant_id', collect($tenants->items())->pluck('id'))
                ->orderByDesc('is_primary')
                ->get()
                ->groupBy('tenant_id')
                ->map(fn ($rows) => $rows->first()),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function counts(string $column): array
    {
        /** @var array<string, int|string> $rows */
        $rows = Tenant::query()
            ->selectRaw($column.', COUNT(*) as aggregate')
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->all();

        $counts = [];

        foreach ($rows as $key => $total) {
            $counts[(string) $key] = (int) $total;
        }

        return $counts;
    }

    private function guard(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isPlatformAdmin(), 403);

        return $user;
    }
}
