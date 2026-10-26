<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\StaffApiHost;
use App\Tenancy\TenancyException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StaffApiHost $hosts,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $tenant = $this->hosts->tenant($request);
        $email = strtolower($request->string('email')->toString());
        $user = User::query()->where('email', $email)->first();
        $passwordMatches = $user instanceof User && Hash::check($request->string('password')->toString(), $user->password);
        $membership = $tenant instanceof Tenant && $user instanceof User
            ? $this->hosts->membership($user, $tenant)
            : null;
        $allowed = $passwordMatches && (! $tenant instanceof Tenant || $membership !== null);

        if (! $user instanceof User || ! $allowed) {
            $this->audit->log(
                action: 'auth.login_failed',
                properties: ['email' => $email, 'channel' => $tenant instanceof Tenant ? 'api' : 'platform'],
                tenantId: $tenant?->id,
                request: $request,
                useContextTenant: false,
            );

            throw ValidationException::withMessages([
                'email' => $tenant instanceof Tenant
                    ? StaffApiHost::TENANT_LOGIN_FAILED
                    : 'These credentials do not match our records.',
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;
        $this->limitMemberships($user, $tenant);

        $this->audit->log(
            action: 'auth.login',
            subject: $user,
            properties: ['channel' => $tenant instanceof Tenant ? 'api' : 'platform'],
            tenantId: $tenant?->id,
            actor: $user,
            request: $request,
            useContextTenant: false,
        );

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => (new UserResource($user))->resolve($request),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $tenant = $this->hosts->tenant($request);
        $this->assertMember($user, $tenant);
        $user->currentAccessToken()->delete();
        $this->audit->log(
            action: 'auth.logout',
            subject: $user,
            properties: ['channel' => $tenant instanceof Tenant ? 'api' : 'platform'],
            tenantId: $tenant?->id,
            actor: $user,
            request: $request,
            useContextTenant: false,
        );

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): UserResource
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $tenant = $this->hosts->tenant($request);
        $this->assertMember($user, $tenant);
        $this->limitMemberships($user, $tenant);

        return new UserResource($user);
    }

    private function assertMember(User $user, ?Tenant $tenant): void
    {
        if ($tenant instanceof Tenant && $this->hosts->membership($user, $tenant) === null) {
            throw TenancyException::forbidden();
        }
    }

    private function limitMemberships(User $user, ?Tenant $tenant): void
    {
        if (! $tenant instanceof Tenant) {
            $user->load('memberships.role');

            return;
        }

        $membership = $this->hosts->membership($user, $tenant);
        $user->setRelation('memberships', Collection::make($membership === null ? [] : [$membership]));
    }
}
