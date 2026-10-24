<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);

        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $term = $this->term($validated['q'] ?? null);

        $customers = Customer::query()
            ->withCount('bookings')
            ->orderBy('name')
            ->orderBy('id');

        if ($term !== null) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $customers->where(function (Builder $query) use ($like): void {
                $query->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            });
        }

        $rows = [];

        foreach ($customers->limit(50)->get() as $customer) {
            $rows[] = $this->payload($customer);
        }

        return response()->json(['data' => $rows]);
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);

        return response()->json(['data' => $this->payload($customer->loadCount('bookings'))]);
    }

    private function term(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $term = trim($value);

        return $term === '' ? null : $term;
    }

    /**
     * @return array{id: string, name: string, email: string|null, phone: string|null, bookings_count: int}
     */
    private function payload(Customer $customer): array
    {
        $count = $customer->getAttribute('bookings_count');

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'bookings_count' => is_numeric($count) ? (int) $count : 0,
        ];
    }
}
