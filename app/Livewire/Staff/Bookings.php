<?php

namespace App\Livewire\Staff;

use App\Enums\BookingStatus;
use App\Livewire\Concerns\WithinTenant;
use App\Livewire\Staff\Concerns\LoadsBookingRows;
use App\Models\Booking;
use App\Models\BookingListView;
use App\Models\Location;
use App\Models\Service;
use App\Services\Booking\BookingListActions;
use App\Services\Booking\BookingListFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.staff')]
#[Title('Bookings')]
class Bookings extends Component
{
    use LoadsBookingRows, WithinTenant, WithPagination;

    private const VIEW_LIMIT = 30;

    #[Url]
    public string $status = '';

    #[Url]
    public string $when = 'upcoming';

    #[Url]
    public string $search = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $location = '';

    #[Url(except: '')]
    public string $item = '';

    #[Url(as: 'view', except: '')]
    public string $savedView = '';

    public string $viewName = '';

    /**
     * Booking ids checked on this page. Livewire hydrates this from the
     * request, so each value is checked before it is used.
     *
     * @var array<int, mixed>
     */
    public array $selected = [];

    public bool $confirmingCancel = false;

    public ?string $flash = null;

    public ?string $problem = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Booking::class);

        if ($this->savedView !== '') {
            $this->applySavedView();
        }
    }

    public function updated(string $name): void
    {
        if ($name === 'viewName' || $name === 'selected' || str_starts_with($name, 'selected.')) {
            return;
        }

        if ($name !== 'savedView') {
            $this->savedView = '';
        }

        $this->confirmingCancel = false;
        $this->selected = [];
        $this->flash = null;
        $this->problem = null;
        $this->resetPage();
    }

    public function updatedSelected(): void
    {
        $this->confirmingCancel = false;
    }

    public function updatedLocation(): void
    {
        if ($this->location === '' || $this->item === '') {
            return;
        }

        $atLocation = Service::query()->whereKey($this->item)->where('location_id', $this->location)->exists();

        if (! $atLocation) {
            $this->item = '';
        }
    }

    public function toggle(string $id): void
    {
        if (in_array($id, $this->selected, true)) {
            $this->selected = array_values(array_filter(
                $this->selected,
                fn (string $selected): bool => $selected !== $id,
            ));
        } else {
            $this->selected[] = $id;
        }

        $this->confirmingCancel = false;
    }

    public function updatedSavedView(): void
    {
        if ($this->savedView === '') {
            return;
        }

        $this->applySavedView();
    }

    public function selectPage(): void
    {
        $ids = $this->selected;

        foreach ($this->filteredQuery()->paginate(25)->getCollection() as $booking) {
            $ids[] = $booking->id;
        }

        $this->selected = array_values(array_unique($ids));
        $this->confirmingCancel = false;
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->confirmingCancel = false;
    }

    public function saveView(): void
    {
        $this->authorize('create', BookingListView::class);
        $this->clearNotice();
        $filters = $this->filters();
        $name = trim($this->viewName);

        if ($name === '' || mb_strlen($name) > 60) {
            $this->addError('viewName', 'Name the view in 60 characters or fewer.');

            return;
        }

        if ($filters->problem() !== null) {
            $this->problem = $filters->problem();

            return;
        }

        $user = $this->currentUser();
        $existing = BookingListView::query()->where('user_id', $user->id)->where('name', $name)->first();

        if (! $existing instanceof BookingListView && BookingListView::query()->where('user_id', $user->id)->count() >= self::VIEW_LIMIT) {
            $this->addError('viewName', 'You can save 30 views. Delete one first.');

            return;
        }

        $payload = $filters->stored();

        if ($existing instanceof BookingListView) {
            $this->authorize('update', $existing);
            $existing->filters = $payload;
            $existing->save();
            $saved = $existing;
            $this->audit('booking_list_view.updated', $saved, ['name' => $name]);
        } else {
            $saved = BookingListView::query()->create([
                'tenant_id' => $this->tenant()->id,
                'user_id' => $user->id,
                'name' => $name,
                'filters' => $payload,
            ]);
            $this->audit('booking_list_view.saved', $saved, ['name' => $name]);
        }

        $this->fillFilters($payload);
        $this->savedView = $saved->id;
        $this->viewName = '';
        $this->flash = 'Saved "'.$name.'".';
    }

    public function deleteView(): void
    {
        $this->clearNotice();
        $saved = BookingListView::query()->where('user_id', $this->currentUser()->id)->find($this->savedView);

        if (! $saved instanceof BookingListView) {
            $this->problem = 'That view is not available.';
            $this->savedView = '';

            return;
        }

        $this->authorize('delete', $saved);
        $name = $saved->name;
        $saved->delete();
        $this->audit('booking_list_view.deleted', null, ['name' => $name]);
        $this->savedView = '';
        $this->flash = 'Deleted "'.$name.'".';
    }

    public function checkInSelected(BookingListActions $actions): void
    {
        $this->clearNotice();
        $this->authorize('checkInAny', Booking::class);
        $ids = $this->guardIds();

        if ($ids === null) {
            return;
        }

        $result = $actions->checkIn($ids, $this->currentUser());
        $this->selected = [];
        $done = $result['checked_in'];
        $notes = [];

        if ($result['already'] > 0) {
            $notes[] = $this->countPhrase($result['already'], 'was already checked in', 'were already checked in');
        }

        if ($result['blocked'] > 0) {
            $notes[] = $this->countPhrase($result['blocked'], 'needs a waiver before check-in', 'need a waiver before check-in');
        }

        if ($result['refused'] > 0) {
            $notes[] = $this->countPhrase($result['refused'], 'is not confirmed', 'are not confirmed');
        }

        if ($done === 0 && $notes === []) {
            $this->problem = 'Nothing was changed.';

            return;
        }

        if ($done > 0) {
            $this->flash = 'Checked in '.$done.'.';
        }

        if ($notes !== []) {
            $this->problem = implode(' ', array_map(fn (string $note): string => $note.'.', $notes));
        }
    }

    public function returnSelected(BookingListActions $actions): void
    {
        $this->clearNotice();
        $this->requirePermission('bookings.manage');
        $ids = $this->guardIds();

        if ($ids === null) {
            return;
        }

        $result = $actions->markReturned($ids, $this->currentUser());
        $this->selected = [];

        if ($result['returned'] === 0 && $result['not_out'] === 0) {
            $this->problem = 'Nothing was changed.';

            return;
        }

        if ($result['returned'] > 0) {
            $this->flash = 'Marked '.$result['returned'].' returned.';
        }

        if ($result['not_out'] > 0) {
            $this->problem = $this->countPhrase($result['not_out'], 'is not checked out', 'are not checked out').'.';
        }
    }

    public function askCancel(): void
    {
        $this->clearNotice();
        $this->requirePermission('bookings.manage');

        if ($this->guardIds() === null) {
            return;
        }

        $this->confirmingCancel = true;
    }

    public function dismissCancel(): void
    {
        $this->confirmingCancel = false;
    }

    public function cancelSelected(BookingListActions $actions): void
    {
        $this->clearNotice();
        $this->requirePermission('bookings.manage');

        if (! $this->confirmingCancel) {
            $this->problem = 'Confirm the cancellation first.';

            return;
        }

        $ids = $this->guardIds();
        $this->confirmingCancel = false;

        if ($ids === null) {
            return;
        }

        $result = $actions->cancel($ids, $this->currentUser());
        $this->selected = [];
        $parts = [];

        if ($result['cancelled'] > 0) {
            $parts[] = 'Cancelled '.$result['cancelled'].'.';
        }

        if ($result['refunded'] > 0) {
            $parts[] = 'Refunded '.$result['refunded'].' '.($result['refunded'] === 1 ? 'card payment' : 'card payments').'.';
        }

        if ($parts !== []) {
            $this->flash = implode(' ', $parts);
        }

        if ($result['problems'] !== []) {
            $this->problem = implode(' ', $result['problems']);
        } elseif ($result['cancelled'] === 0) {
            $this->problem = 'Nothing was changed.';
        }
    }

    public function exportSelected(BookingListActions $actions): ?StreamedResponse
    {
        $this->clearNotice();
        $this->authorize('viewAny', Booking::class);
        $ids = $this->guardIds();

        if ($ids === null) {
            return null;
        }

        $csv = $actions->csv($ids, $this->currentUser());

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, 'bookings.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function render(): View
    {
        $filters = $this->filters();
        $bookings = $this->filteredQuery()->paginate(25);
        $locationId = $this->location;

        return view('livewire.staff.bookings', [
            'bookings' => $bookings,
            'customers' => $this->customersFor(collect($bookings->items())),
            'zones' => $this->zones(),
            'zone' => $this->defaultTimezone(),
            'statuses' => BookingStatus::cases(),
            'locations' => Location::query()->orderBy('name')->orderBy('id')->get(),
            'services' => Service::query()
                ->when($locationId !== '', fn (Builder $query) => $query->where('location_id', $locationId))
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
            'views' => BookingListView::query()->where('user_id', $this->currentUser()->id)->orderBy('name')->orderBy('id')->get(),
            'dateProblem' => $filters->problem(),
            'canCheckIn' => $this->allows('bookings.check_in'),
            'canManage' => $this->allows('bookings.manage'),
            'selectedCount' => count($this->selectedIds()),
        ]);
    }

    /**
     * @return Builder<Booking>
     */
    private function filteredQuery(): Builder
    {
        return $this->filters()->apply(Booking::query()->with(['service', 'location']));
    }

    private function filters(): BookingListFilters
    {
        return BookingListFilters::fromRequest(
            $this->status,
            $this->from,
            $this->to,
            $this->location,
            $this->item,
            $this->when,
            $this->search,
        );
    }

    private function applySavedView(): void
    {
        $saved = BookingListView::query()->where('user_id', $this->currentUser()->id)->find($this->savedView);

        if (! $saved instanceof BookingListView) {
            $this->savedView = '';
            $this->problem = 'That view is not available.';

            return;
        }

        $this->authorize('view', $saved);
        $payload = $saved->filters;
        $this->fillFilters([
            'status' => $this->filterString($payload, 'status'),
            'from' => $this->filterString($payload, 'from'),
            'to' => $this->filterString($payload, 'to'),
            'location' => $this->filterString($payload, 'location'),
            'item' => $this->filterString($payload, 'item'),
            'when' => $this->filterString($payload, 'when', 'upcoming'),
            'search' => $this->filterString($payload, 'search'),
        ]);
    }

    /**
     * @param  array{status: string, from: string, to: string, location: string, item: string, when: string, search: string}  $payload
     */
    private function fillFilters(array $payload): void
    {
        $this->status = $payload['status'];
        $this->from = $payload['from'];
        $this->to = $payload['to'];
        $this->location = $payload['location'];
        $this->item = $payload['item'];
        $this->when = $payload['when'] !== '' ? $payload['when'] : 'upcoming';
        $this->search = $payload['search'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function filterString(array $payload, string $key, string $default = ''): string
    {
        $value = $payload[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * @return list<string>|null
     */
    private function guardIds(): ?array
    {
        $ids = $this->selectedIds();

        if ($ids === []) {
            $this->problem = 'Select at least one booking.';

            return null;
        }

        if (count($ids) > 100) {
            $this->problem = 'Select 100 bookings or fewer.';

            return null;
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function selectedIds(): array
    {
        $ids = [];

        foreach ($this->selected as $id) {
            if (is_string($id) && $id !== '') {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function countPhrase(int $count, string $singular, string $plural): string
    {
        return $count.' '.($count === 1 ? $singular : $plural);
    }

    private function clearNotice(): void
    {
        $this->reset('flash', 'problem');
        $this->resetErrorBag();
    }
}
