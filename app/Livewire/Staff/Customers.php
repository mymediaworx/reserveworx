<?php

namespace App\Livewire\Staff;

use App\Enums\CustomerDataExportStatus;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Customer;
use App\Models\CustomerDataExport;
use App\Services\Privacy\CustomerDataEraser;
use App\Services\Privacy\CustomerDataExporter;
use App\Tenancy\StaffLocationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Staff page for one customer's private download and anonymization.
 * Waiver retention stays an open question, so this page does not delete those rows.
 */
#[Layout('layouts.staff')]
#[Title('Customer records')]
class Customers extends Component
{
    use WithinTenant;

    #[Url(except: '')]
    public string $q = '';

    public bool $confirmWithoutFile = false;

    public ?string $flash = null;

    public string $flashTone = 'ok';

    public function mount(): void
    {
        $this->guardPrivacy();
    }

    public function exportCustomer(string $id, CustomerDataExporter $exporter): void
    {
        $customer = $this->customer($id);
        $this->authorize('export', $customer);

        $export = $exporter->queue($customer, $this->currentUser(), request());
        $ready = $exporter->payload($customer, $export)['download_url'];

        if (is_string($ready) && $ready !== '') {
            $this->flash = 'The download is ready. The link lasts 30 minutes.';
        } else {
            $this->flash = 'The download is being prepared.';
        }

        $this->flashTone = 'ok';
    }

    public function eraseCustomer(string $id, CustomerDataEraser $eraser): void
    {
        $customer = $this->customer($id);
        $this->authorize('erase', $customer);

        $result = $eraser->erase($customer, $this->currentUser(), $this->confirmWithoutFile, request());

        if ($result->refused) {
            $this->flash = 'Deletion is refused until a customer data export exists.';
            $this->flashTone = 'bad';

            return;
        }

        if ($result->idempotent) {
            $this->flash = 'This customer was already removed.';
            $this->flashTone = 'ok';

            return;
        }

        $this->flash = 'Personal details were removed. Bookings, payments, and signed waivers stay.';
        $this->flashTone = 'ok';
    }

    public function render(): View
    {
        $this->guardPrivacy();

        return view('livewire.staff.customers', [
            'rows' => $this->rows(),
            'searching' => $this->term() !== null,
        ]);
    }

    private function guardPrivacy(): void
    {
        $this->requirePermission('customers.privacy');
        abort_if(app(StaffLocationAccess::class)->isRestricted($this->currentUser()), 403);
    }

    private function customer(string $id): Customer
    {
        $this->guardPrivacy();

        $customer = Customer::query()->whereKey($id)->first();
        abort_unless($customer instanceof Customer, 404);

        return $customer;
    }

    private function term(): ?string
    {
        $term = trim($this->q);

        if ($term === '') {
            return null;
        }

        if (mb_strlen($term) > 255) {
            $term = mb_substr($term, 0, 255);
        }

        return $term;
    }

    /**
     * @return list<array{
     *     id: string,
     *     name: string,
     *     email: string|null,
     *     phone: string|null,
     *     anonymized: bool,
     *     status: string|null,
     *     downloadUrl: string|null
     * }>
     */
    private function rows(): array
    {
        $customers = $this->matchingCustomers();
        $latest = $this->latestExports($customers);
        $exporter = app(CustomerDataExporter::class);
        $rows = [];

        foreach ($customers as $customer) {
            $export = $latest->get($customer->id);
            $downloadUrl = null;
            $status = null;

            if ($export instanceof CustomerDataExport) {
                $status = $export->status->value;
                $url = $exporter->payload($customer, $export)['download_url'];
                $downloadUrl = is_string($url) && $url !== '' ? $url : null;
            }

            $rows[] = [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'anonymized' => $customer->anonymized_at !== null,
                'status' => $status,
                'downloadUrl' => $downloadUrl,
            ];
        }

        return $rows;
    }

    /**
     * @return Collection<int, Customer>
     */
    private function matchingCustomers(): Collection
    {
        $query = Customer::query()->orderBy('name')->orderBy('id');
        $term = $this->term();

        if ($term !== null) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            });
        }

        return $query->limit(50)->get();
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @return Collection<string, CustomerDataExport>
     */
    private function latestExports(Collection $customers): Collection
    {
        $ids = $customers->modelKeys();

        if ($ids === []) {
            return new Collection;
        }

        /** @var Collection<string, CustomerDataExport> $latest */
        $latest = new Collection;

        $exports = CustomerDataExport::query()
            ->whereIn('customer_id', $ids)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        foreach ($exports as $export) {
            if (! $latest->has($export->customer_id)) {
                $latest->put($export->customer_id, $export);
            }
        }

        return $latest;
    }

    public function statusIsFailed(?string $status): bool
    {
        return $status === CustomerDataExportStatus::Failed->value;
    }

    public function statusIsQueued(?string $status, ?string $downloadUrl): bool
    {
        return $downloadUrl === null && $status === CustomerDataExportStatus::Queued->value;
    }
}
