<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\NotificationHook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationHookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);
        $query = NotificationHook::query()->orderByDesc('created_at')->orderByDesc('id');
        $bookingId = $request->query('booking_id');

        if (is_string($bookingId) && $bookingId !== '') {
            $booking = Booking::query()->find($bookingId);

            if (! $booking instanceof Booking) {
                abort(404);
            }

            $this->authorize('view', $booking);
            $query->where('booking_id', $booking->id);
        }

        $rows = [];

        foreach ($query->limit(100)->get() as $hook) {
            $rows[] = [
                'id' => $hook->id,
                'booking_id' => $hook->booking_id,
                'kind' => $hook->kind,
                'delivery_status' => $hook->delivery_status,
                'delivery_error' => $hook->delivery_error,
                'payload' => $hook->payload,
                'created_at' => $hook->created_at->toIso8601String(),
            ];
        }

        return response()->json(['data' => $rows]);
    }
}
