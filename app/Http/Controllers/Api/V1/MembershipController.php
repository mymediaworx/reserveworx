<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMembershipRequest;
use App\Http\Requests\Api\V1\UpdateMembershipRequest;
use App\Http\Resources\MembershipResource;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MembershipController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Membership::class);

        $memberships = Membership::query()->with(['user', 'role'])->orderBy('created_at')->get();

        return MembershipResource::collection($memberships);
    }

    public function show(Request $request, Membership $membership): MembershipResource
    {
        $this->authorize('view', $membership);
        $membership->load(['user', 'role']);

        return new MembershipResource($membership);
    }

    public function store(StoreMembershipRequest $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $actor = $request->user();

        if ($tenant === null || ! $actor instanceof User) {
            abort(403);
        }

        $email = strtolower($request->string('email')->toString());
        $existing = User::query()->where('email', $email)->first();

        if ($existing === null && ! $request->filled('password')) {
            throw ValidationException::withMessages([
                'password' => 'A password of at least 12 characters is required for a new user.',
            ]);
        }

        $role = Role::query()->where('slug', $request->string('role')->toString())->firstOrFail();

        $membership = DB::transaction(function () use ($request, $tenant, $email, $existing, $role, $actor): Membership {
            $user = $existing ?? User::query()->create([
                'name' => $request->string('name')->toString(),
                'email' => $email,
                'password' => $request->string('password')->toString(),
            ]);

            $already = Membership::query()
                ->where('user_id', $user->id)
                ->where('status', MembershipStatus::Active)
                ->exists();

            if ($already) {
                throw ValidationException::withMessages([
                    'email' => 'That person is already a member of this workspace.',
                ]);
            }

            $revoked = Membership::query()->where('user_id', $user->id)->first();

            if ($revoked !== null) {
                $revoked->role_id = $role->id;
                $revoked->status = MembershipStatus::Active;
                $revoked->save();
                $membership = $revoked;
            } else {
                $membership = Membership::query()->create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'status' => MembershipStatus::Active,
                ]);
            }

            $this->audit->log(
                action: 'membership.created',
                subject: $membership,
                properties: [
                    'email' => $email,
                    'role' => $role->slug,
                    'existing_user' => $existing !== null,
                ],
                actor: $actor,
                request: $request,
            );

            return $membership->load(['user', 'role']);
        });

        return (new MembershipResource($membership))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateMembershipRequest $request, Membership $membership): MembershipResource
    {
        $actor = $request->user();
        $status = MembershipStatus::from($request->string('status')->toString());

        $removesLastOwner = $this->isLastActiveOwner($membership) && (
            $status === MembershipStatus::Revoked
            || ($request->filled('role') && $request->string('role')->toString() !== 'owner')
        );

        if ($removesLastOwner) {
            throw ValidationException::withMessages([
                'status' => 'The last owner of a workspace cannot be removed.',
            ]);
        }

        $before = $membership->status->value;
        $membership->status = $status;

        if ($request->filled('role')) {
            $role = Role::query()->where('slug', $request->string('role')->toString())->firstOrFail();
            $membership->role_id = $role->id;
        }

        $membership->save();

        $this->audit->log(
            action: $status === MembershipStatus::Revoked ? 'membership.revoked' : 'membership.updated',
            subject: $membership,
            properties: [
                'before_status' => $before,
                'after_status' => $membership->status->value,
            ],
            actor: $actor instanceof User ? $actor : null,
            request: $request,
        );

        return new MembershipResource($membership->load(['user', 'role']));
    }

    private function isLastActiveOwner(Membership $membership): bool
    {
        $membership->loadMissing('role');

        if ($membership->role?->slug !== 'owner' || $membership->status !== MembershipStatus::Active) {
            return false;
        }

        return Membership::query()
            ->where('status', MembershipStatus::Active)
            ->whereHas('role', fn ($query) => $query->where('slug', 'owner'))
            ->count() <= 1;
    }
}
