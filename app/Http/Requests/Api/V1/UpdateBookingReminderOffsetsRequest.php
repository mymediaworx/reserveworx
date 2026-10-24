<?php

namespace App\Http\Requests\Api\V1;

use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBookingReminderOffsetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $setting = TenantSetting::query()->first();

        return $user instanceof User
            && $setting instanceof TenantSetting
            && $user->can('manageReminders', $setting);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'offsets_minutes' => ['present', 'array', 'max:8'],
            'offsets_minutes.*' => ['integer', 'distinct', 'between:1,10080'],
        ];
    }
}
