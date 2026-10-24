<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CustomerAccount;
use App\Services\Customers\CustomerReceipts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class CustomerBookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $validated = $request->validate([
            'when' => ['sometimes', 'string', Rule::in(['upcoming', 'past'])],
        ]);
        $when = $validated['when'] ?? null;
        $upcoming = [];
        $past = [];

        if ($when !== 'past') {
            $upcoming = $this->rows(
                $this->owned($account)
                    ->where('ends_at', '>=', now())
                    ->orderBy('starts_at')
                    ->orderBy('id')
                    ->limit(100)
                    ->get(),
                'upcoming',
            );
        }

        if ($when !== 'upcoming') {
            $past = $this->rows(
                $this->owned($account)
                    ->where('ends_at', '<', now())
                    ->orderByDesc('starts_at')
                    ->orderByDesc('id')
                    ->limit(100)
                    ->get(),
                'past',
            );
        }

        return response()->json([
            'upcoming' => $upcoming,
            'past' => $past,
        ]);
    }

    public function show(Request $request, string $booking): JsonResponse
    {
        $found = $this->findOwned($this->account($request), $booking);

        return response()->json([
            'data' => $this->row($found, $this->when($found)),
        ]);
    }

    public function receipt(Request $request, CustomerReceipts $receipts, string $booking): JsonResponse
    {
        return response()->json([
            'data' => $receipts->forBooking($this->findOwned($this->account($request), $booking)),
        ]);
    }

    public function receipts(Request $request, CustomerReceipts $receipts): JsonResponse
    {
        $rows = [];

        foreach ($this->owned($this->account($request))->orderByDesc('starts_at')->orderByDesc('id')->limit(100)->get() as $booking) {
            $rows[] = $receipts->forBooking($booking);
        }

        return response()->json(['data' => $rows]);
    }

    private function account(Request $request): CustomerAccount
    {
        $account = $request->user();
        abort_unless($account instanceof CustomerAccount, 401);

        return $account;
    }

    /**
     * @return Builder<Booking>
     */
    private function owned(CustomerAccount $account): Builder
    {
        return Booking::query()
            ->with(['service', 'location'])
            ->whereHas('customer', function (Builder $query) use ($account): void {
                $query->where('customer_account_id', $account->id);
            });
    }

    private function findOwned(CustomerAccount $account, string $bookingId): Booking
    {
        $booking = $this->owned($account)->whereKey($bookingId)->first();
        abort_unless($booking instanceof Booking, 404);

        return $booking;
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return list<array{id: string, reference: string, service_name: string|null, location_name: string|null, starts_at: string|null, ends_at: string|null, status: string, payment_status: string, operational_status: string, participants: int, when: string}>
     */
    private function rows(Collection $bookings, string $when): array
    {
        $rows = [];

        foreach ($bookings as $booking) {
            $rows[] = $this->row($booking, $when);
        }

        return $rows;
    }

    /**
     * @return array{id: string, reference: string, service_name: string|null, location_name: string|null, starts_at: string|null, ends_at: string|null, status: string, payment_status: string, operational_status: string, participants: int, when: string}
     */
    private function row(Booking $booking, string $when): array
    {
        return [
            'id' => $booking->id,
            'reference' => $booking->id,
            'service_name' => $booking->service?->name,
            'location_name' => $booking->location?->name,
            'starts_at' => $this->iso($booking->starts_at),
            'ends_at' => $this->iso($booking->ends_at),
            'status' => $booking->status->value,
            'payment_status' => $booking->payment_status->value,
            'operational_status' => $booking->operational_status->value,
            'participants' => $booking->participants,
            'when' => $when,
        ];
    }

    private function when(Booking $booking): string
    {
        $ends = $booking->ends_at;

        return $ends instanceof Carbon && $ends->greaterThanOrEqualTo(now()) ? 'upcoming' : 'past';
    }

    private function iso(mixed $value): ?string
    {
        return $value instanceof Carbon ? $value->toIso8601String() : null;
    }
}
