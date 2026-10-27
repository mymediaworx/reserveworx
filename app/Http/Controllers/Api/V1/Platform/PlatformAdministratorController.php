<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Platform\PlatformAdminRevokeException;
use App\Services\Platform\PlatformAdminRevoker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformAdministratorController extends Controller
{
    public function __construct(private readonly PlatformAdminRevoker $revoker) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->revoker->list($this->actor($request)),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:255'],
        ], [
            'email.required' => 'Enter a valid email.',
            'email.string' => 'Enter a valid email.',
            'email.max' => 'Enter a valid email.',
        ]);

        $email = $validated['email'] ?? null;

        if (! is_string($email)) {
            return response()->json(['message' => 'Enter a valid email.'], 422);
        }

        try {
            $user = $this->revoker->revoke($email, $this->actor($request));
        } catch (PlatformAdminRevokeException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }

        return response()->json([
            'data' => [
                'email' => $user->email,
                'removed' => true,
            ],
        ]);
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
