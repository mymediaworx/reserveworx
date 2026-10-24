<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Choosing pay at pickup confirmed the booking before any money was collected.
 * Those rows were stored as offline_paid with a paid payment. Move them to due_at_pickup
 * and remove the commitment payment when nothing else was collected.
 */
return new class extends Migration
{
    public function up(): void
    {
        $commitments = DB::table('payments')
            ->where('idempotency_key', 'like', 'widget-pickup-%')
            ->get(['id', 'booking_id']);

        foreach ($commitments as $commitment) {
            $otherPaid = DB::table('payments')
                ->where('booking_id', $commitment->booking_id)
                ->where('id', '!=', $commitment->id)
                ->where('status', 'paid')
                ->exists();

            if (! $otherPaid) {
                DB::table('bookings')
                    ->where('id', $commitment->booking_id)
                    ->where('payment_status', 'offline_paid')
                    ->update(['payment_status' => 'due_at_pickup']);
            }

            DB::table('payments')->where('id', $commitment->id)->delete();
        }
    }

    public function down(): void
    {
        DB::table('bookings')
            ->where('payment_status', 'due_at_pickup')
            ->update(['payment_status' => 'offline_paid']);
    }
};
