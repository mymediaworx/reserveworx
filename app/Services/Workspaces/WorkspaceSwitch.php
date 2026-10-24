<?php

namespace App\Services\Workspaces;

use App\Models\StaffEntryToken;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Access;
use Illuminate\Support\Facades\DB;

/**
 * A host-only session cannot follow the owner to another subdomain. This mints
 * a two-minute single-use token that the destination host exchanges for its own
 * session. The plain token is never stored.
 */
final class WorkspaceSwitch
{
    public function __construct(private readonly Access $access) {}

    public function mint(User $user, Tenant $tenant): string
    {
        $plain = bin2hex(random_bytes(32));

        StaffEntryToken::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addMinutes(2),
        ]);

        return $plain;
    }

    public function consume(string $plain, Tenant $tenant): ?User
    {
        if (preg_match('/^[a-f0-9]{64}$/', $plain) !== 1) {
            return null;
        }

        return DB::transaction(function () use ($plain, $tenant): ?User {
            $row = StaffEntryToken::query()
                ->where('token_hash', hash('sha256', $plain))
                ->lockForUpdate()
                ->first();

            if (! $row instanceof StaffEntryToken || $row->consumed_at !== null || $row->expires_at->isPast()) {
                return null;
            }

            if ($row->tenant_id !== $tenant->id) {
                return null;
            }

            $user = User::query()->find($row->user_id);

            if (! $user instanceof User || ! $this->access->hasActiveMembership($user, $tenant->id)) {
                return null;
            }

            $row->consumed_at = now();
            $row->save();

            return $user;
        });
    }
}
