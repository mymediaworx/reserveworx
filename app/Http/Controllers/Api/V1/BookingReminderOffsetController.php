<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateBookingReminderOffsetsRequest;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingReminderOffsetController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request): JsonResponse
    {
        $setting = TenantSetting::query()->firstOrFail();
        $this->authorize('manageReminders', $setting);

        return response()->json([
            'data' => $this->payload($setting),
        ]);
    }

    public function update(UpdateBookingReminderOffsetsRequest $request): JsonResponse
    {
        $setting = TenantSetting::query()->firstOrFail();
        $before = $setting->reminderOffsets();
        $offsets = [];
        $raw = $request->validated('offsets_minutes');

        if (is_array($raw)) {
            foreach ($raw as $offset) {
                if (is_int($offset)) {
                    $offsets[] = $offset;
                }
            }
        }

        rsort($offsets);

        $setting->reminder_offsets_minutes = $offsets;
        $setting->save();

        $actor = $request->user();

        $this->audit->log(
            action: 'reminders.updated',
            subject: $setting,
            properties: [
                'before' => $before,
                'after' => $setting->reminderOffsets(),
            ],
            actor: $actor instanceof User ? $actor : null,
            request: $request,
        );

        return response()->json([
            'data' => $this->payload($setting),
        ]);
    }

    /**
     * @return array{offsets_minutes: list<int>, is_default: bool}
     */
    private function payload(TenantSetting $setting): array
    {
        return [
            'offsets_minutes' => $setting->reminderOffsets(),
            'is_default' => $setting->reminder_offsets_minutes === null,
        ];
    }
}
