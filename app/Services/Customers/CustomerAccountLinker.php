<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerAccount;

/**
 * Attaches guest customer rows to a verified account in the same workspace.
 */
final class CustomerAccountLinker
{
    public function linkVerifiedAccount(CustomerAccount $account): int
    {
        if ($account->email_verified_at === null) {
            return 0;
        }

        $email = strtolower($account->email);
        $linked = Customer::query()
            ->whereNull('anonymized_at')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where(function ($query) use ($account): void {
                $query->whereNull('customer_account_id')
                    ->orWhere('customer_account_id', $account->id);
            })
            ->update(['customer_account_id' => $account->id]);

        $this->copyName($account, $email);

        return $linked;
    }

    public function attachGuestCustomer(Customer $customer): void
    {
        if ($customer->customer_account_id !== null || $customer->anonymized_at !== null) {
            return;
        }

        $email = $customer->email;

        if (! is_string($email) || $email === '') {
            return;
        }

        $account = CustomerAccount::query()
            ->where('email', strtolower($email))
            ->whereNotNull('email_verified_at')
            ->first();

        if (! $account instanceof CustomerAccount) {
            return;
        }

        $customer->customer_account_id = $account->id;
        $customer->save();
    }

    private function copyName(CustomerAccount $account, string $email): void
    {
        if (is_string($account->name) && $account->name !== '') {
            return;
        }

        $name = Customer::query()
            ->whereNull('anonymized_at')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('customer_account_id', $account->id)
            ->orderBy('id')
            ->value('name');

        if (! is_string($name) || $name === '' || strtolower($name) === $email) {
            return;
        }

        $account->forceFill(['name' => $name])->save();
    }
}
