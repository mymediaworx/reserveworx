<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $email = strtolower($request->string('email')->toString());
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
            $this->audit->log(
                action: 'auth.login_failed',
                properties: ['email' => $email],
                request: $request,
                useContextTenant: false,
            );

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        $this->audit->log(
            action: 'auth.login',
            subject: $user,
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

        if ($user instanceof User) {
            $user->currentAccessToken()->delete();
            $this->audit->log(
                action: 'auth.logout',
                subject: $user,
                actor: $user,
                request: $request,
                useContextTenant: false,
            );
        }

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): UserResource
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $user->load('memberships.role');

        return new UserResource($user);
    }
}
