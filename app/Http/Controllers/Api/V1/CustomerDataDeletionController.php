<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerDataDeletion;
use App\Models\User;
use App\Services\Privacy\CustomerDataEraser;
use App\Tenancy\StaffLocationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerDataDeletionController extends Controller
{
    public function __construct(private readonly CustomerDataEraser $eraser) {}

    public function store(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('erase', $customer);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        abort_if(app(StaffLocationAccess::class)->isRestricted($actor), 403);

        $validated = $request->validate([
            'confirm' => ['sometimes', 'boolean'],
        ]);
        $confirm = array_key_exists('confirm', $validated) && $request->boolean('confirm');

        $result = $this->eraser->erase($customer, $actor, $confirm, $request);

        if ($result->refused || ! $result->deletion instanceof CustomerDataDeletion || ! $result->customer instanceof Customer) {
            return response()->json([
                'message' => 'Deletion is refused until a customer data export exists.',
            ], 409);
        }

        $anonymizedAt = $result->customer->anonymized_at;

        return response()->json([
            'data' => [
                'id' => $result->deletion->id,
                'customer_id' => $result->customer->id,
                'anonymized_at' => $anonymizedAt?->toIso8601String(),
                'idempotent' => $result->idempotent,
            ],
        ]);
    }
}
