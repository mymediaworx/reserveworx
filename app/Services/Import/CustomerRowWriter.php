<?php

namespace App\Services\Import;

use App\Models\Customer;
use App\Models\User;
use App\Services\Customers\CustomerAccountLinker;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;

/**
 * Customers match on the lowercased email inside this workspace. The oldest row is updated.
 */
final class CustomerRowWriter implements RowWriter
{
    private string $tenantId = '';

    public function __construct(private readonly CustomerAccountLinker $accounts) {}

    public function prepare(string $tenantId, User $actor): void
    {
        $this->guardTenant($tenantId);
        $this->tenantId = $tenantId;

        if (app(StaffLocationAccess::class)->isRestricted($actor)) {
            throw new ImportException('Customer import needs access to every location.');
        }
    }

    public function inspect(array $values): array
    {
        $parsed = $this->parse($values);

        return [
            'errors' => $parsed['errors'],
            'notice' => $parsed['notice'],
        ];
    }

    public function apply(array $values): array
    {
        $parsed = $this->parse($values);

        if ($parsed['errors'] !== []) {
            return [
                'outcome' => 'skipped',
                'reason' => implode(' ', $parsed['errors']),
            ];
        }

        if ($parsed['customer'] instanceof Customer) {
            $customer = $parsed['customer'];
            $customer->name = $parsed['name'];
            $customer->email = $parsed['email'];

            if ($parsed['has_phone']) {
                $customer->phone = $parsed['phone'];
            }

            if ($customer->isDirty()) {
                $customer->save();
            }

            $this->accounts->attachGuestCustomer($customer);

            return ['outcome' => 'updated', 'reason' => ''];
        }

        $customer = Customer::query()->create([
            'tenant_id' => $this->tenantId,
            'name' => $parsed['name'],
            'email' => $parsed['email'],
            'phone' => $parsed['has_phone'] ? $parsed['phone'] : null,
        ]);
        $this->accounts->attachGuestCustomer($customer);

        return ['outcome' => 'created', 'reason' => ''];
    }

    /**
     * @param  array<string, string>  $values
     * @return array{errors: list<string>, notice: string, name: string, email: string, has_phone: bool, phone: string, customer: Customer|null}
     */
    private function parse(array $values): array
    {
        $errors = [];
        $name = trim($values['name'] ?? '');
        $email = strtolower(trim($values['email'] ?? ''));
        $hasPhone = array_key_exists('phone', $values) && trim($values['phone']) !== '';
        $phone = $hasPhone ? trim($values['phone']) : '';

        if ($name === '') {
            $errors[] = 'Name is required.';
        } elseif (mb_strlen($name) > 255) {
            $errors[] = 'Name must be 255 characters or fewer.';
        }

        if ($email === '') {
            $errors[] = 'Email is required.';
        } elseif (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Email is not a valid address.';
        }

        if ($hasPhone && (strlen($phone) > 40 || preg_match('/^[0-9+().\-\s]+$/', $phone) !== 1)) {
            $errors[] = 'Phone can only contain numbers and + ( ) . - and must be 40 characters or fewer.';
        }

        $customer = null;
        $notice = '';

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $customer = Customer::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->orderBy('id')
                ->first();

            if ($customer instanceof Customer && $customer->anonymized_at !== null) {
                $errors[] = 'That customer was anonymized and was left unchanged.';
                $customer = null;
            } elseif ($customer instanceof Customer && $errors === []) {
                $notice = 'This email already belongs to a customer. The import will update that customer.';
            } elseif (! $customer instanceof Customer) {
                $customer = null;
            }
        }

        return [
            'errors' => $errors,
            'notice' => $notice,
            'name' => $name,
            'email' => $email,
            'has_phone' => $hasPhone,
            'phone' => $phone,
            'customer' => $customer,
        ];
    }

    private function guardTenant(string $tenantId): void
    {
        if (app(TenantManager::class)->id() !== $tenantId) {
            throw new ImportException('The import lost its workspace.');
        }
    }
}
