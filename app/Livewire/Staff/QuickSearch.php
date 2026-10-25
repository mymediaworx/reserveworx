<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\WithinTenant;
use App\Services\Search\QuickSearchGroup;
use App\Services\Search\QuickSearchHit;
use App\Services\Search\StaffQuickSearch;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class QuickSearch extends Component
{
    use WithinTenant;

    public string $query = '';

    public bool $open = false;

    public bool $help = false;

    public int $selected = 0;

    public int $bookingsPage = 1;

    public int $customersPage = 1;

    public int $inventoryPage = 1;

    /**
     * @var list<QuickSearchGroup>|null
     */
    private ?array $groupCache = null;

    #[On('staff-search-toggle')]
    public function toggle(): void
    {
        $this->help = false;
        $this->open = ! $this->open;

        if ($this->open) {
            $this->selected = 0;
        }
    }

    #[On('staff-shortcuts-help')]
    public function showHelp(): void
    {
        $this->open = false;
        $this->help = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->help = false;
    }

    public function updatedQuery(): void
    {
        $this->query = mb_substr(trim($this->query), 0, 80);
        $this->bookingsPage = 1;
        $this->customersPage = 1;
        $this->inventoryPage = 1;
        $this->selected = 0;
    }

    public function pageFor(string $type, int $page): void
    {
        $page = max(1, min(50, $page));

        match ($type) {
            'bookings' => $this->bookingsPage = $page,
            'customers' => $this->customersPage = $page,
            'inventory' => $this->inventoryPage = $page,
            default => null,
        };

        $this->selected = 0;
    }

    public function highlight(int $delta): void
    {
        $count = count($this->hits());

        if ($count === 0) {
            $this->selected = 0;

            return;
        }

        $this->selected = ($this->selected + $delta + $count) % $count;
    }

    public function openSelected(): void
    {
        $hit = $this->hits()[$this->selected] ?? null;

        if (! $hit instanceof QuickSearchHit) {
            return;
        }

        $this->redirect($hit->href, navigate: true);
    }

    public function render(): View
    {
        $groups = $this->cachedGroups();

        return view('livewire.staff.quick-search', [
            'groups' => $groups,
            'searching' => app(StaffQuickSearch::class)->hasTerms($this->query),
            'canCreate' => $this->allows('bookings.manage'),
            'hitCount' => count($this->hits()),
        ]);
    }

    /**
     * @return list<QuickSearchGroup>
     */
    private function cachedGroups(): array
    {
        if ($this->groupCache !== null) {
            return $this->groupCache;
        }

        return $this->groupCache = app(StaffQuickSearch::class)->groups(
            $this->query,
            $this->bookingsPage,
            $this->customersPage,
            $this->inventoryPage,
            $this->allows('bookings.view'),
            $this->allows('inventory.view'),
        );
    }

    /**
     * @return list<QuickSearchHit>
     */
    private function hits(): array
    {
        $hits = [];

        foreach ($this->cachedGroups() as $group) {
            foreach ($group->hits as $hit) {
                $hits[] = $hit;
            }
        }

        return $hits;
    }
}
