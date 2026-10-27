<?php

namespace App\Services\Platform;

use App\Models\PlatformAdministrator;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class PlatformAdminRevoker
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return list<array{id: string, name: string, email: string, self: bool}>
     */
    public function list(?User $actor): array
    {
        return PlatformAdministrator::query()
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(function (PlatformAdministrator $administrator) use ($actor): array {
                $user = $administrator->user;

                return [
                    'id' => $administrator->id,
                    'name' => $user instanceof User ? $user->name : '',
                    'email' => $user instanceof User ? $user->email : '',
                    'self' => $actor instanceof User && $user instanceof User && $actor->id === $user->id,
                ];
            })
            ->all();
    }

    public function revoke(string $email, ?User $actor): User
    {
        $email = strtolower(trim($email));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new PlatformAdminRevokeException('Enter a valid email.', 422);
        }

        return DB::transaction(function () use ($email, $actor): User {
            $admins = PlatformAdministrator::query()->orderBy('id')->lockForUpdate()->get();
            $user = User::query()->where('email', $email)->first();
            $admin = $user instanceof User ? $admins->firstWhere('user_id', $user->id) : null;

            if (! $user instanceof User || ! $admin instanceof PlatformAdministrator) {
                throw new PlatformAdminRevokeException('That person is not a platform administrator.', 404);
            }

            if ($admins->count() < 2) {
                throw new PlatformAdminRevokeException('The last platform administrator stays.', 409);
            }

            if ($actor instanceof User && $actor->id === $user->id) {
                throw new PlatformAdminRevokeException('Another platform administrator has to remove your access.', 409);
            }

            $admin->delete();

            $this->audit->log(
                action: 'platform.admin_revoked',
                subject: $user,
                properties: ['email' => $user->email],
                actor: $actor,
                useContextTenant: false,
            );

            return $user;
        });
    }
}
