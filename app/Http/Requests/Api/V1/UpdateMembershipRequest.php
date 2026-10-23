<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $membership = $this->route('membership');

        return $user instanceof User
            && $membership instanceof Membership
            && $user->can('update', $membership);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([MembershipStatus::Active->value, MembershipStatus::Revoked->value])],
            'role' => ['sometimes', Rule::in(['owner', 'manager', 'staff'])],
        ];
    }
}
