<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OperatorFeePricing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreOperatorFeeRequest;
use App\Http\Requests\Api\V1\UpdateOperatorFeeRequest;
use App\Http\Resources\OperatorFeeResource;
use App\Models\BookingOperatorFee;
use App\Models\OperatorFee;
use App\Models\Tenant;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class OperatorFeeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', OperatorFee::class);

        return OperatorFeeResource::collection(OperatorFee::query()->orderBy('name')->get());
    }

    public function store(StoreOperatorFeeRequest $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $pricing = $request->enum('pricing', OperatorFeePricing::class);
        abort_unless($pricing instanceof OperatorFeePricing, 422);

        try {
            $fee = OperatorFee::query()->create([
                'tenant_id' => $tenant->id,
                'service_id' => $this->nullableString($request->input('service_id')),
                'location_id' => $this->nullableString($request->input('location_id')),
                'name' => $request->string('name')->toString(),
                'description' => $this->description($request->input('description')),
                'pricing' => $pricing,
                'amount_cents' => $pricing === OperatorFeePricing::Percent ? null : $request->integer('amount_cents'),
                'percent_bps' => $pricing === OperatorFeePricing::Percent ? $request->integer('percent_bps') : null,
                'active' => $request->has('active') ? $request->boolean('active') : true,
            ]);
        } catch (QueryException $exception) {
            $this->rethrowDuplicateName($exception);
        }

        $this->audit->log('operator_fee.created', $fee, [
            'name' => $fee->name,
            'pricing' => $fee->pricing->value,
            'service_id' => $fee->service_id,
            'location_id' => $fee->location_id,
        ], request: $request);

        return (new OperatorFeeResource($fee))->response()->setStatusCode(201);
    }

    public function show(Request $request, OperatorFee $operator_fee): OperatorFeeResource
    {
        $this->authorize('view', $operator_fee);

        return new OperatorFeeResource($operator_fee);
    }

    public function update(UpdateOperatorFeeRequest $request, OperatorFee $operator_fee): OperatorFeeResource
    {
        $operator_fee->fill($request->safe()->only([
            'name',
            'description',
            'pricing',
            'amount_cents',
            'percent_bps',
            'service_id',
            'location_id',
            'active',
        ]));

        if ($request->exists('description')) {
            $operator_fee->description = $this->description($request->input('description'));
        }

        if ($operator_fee->pricing === OperatorFeePricing::Percent) {
            $operator_fee->amount_cents = null;
        } else {
            $operator_fee->percent_bps = null;
        }

        try {
            $operator_fee->save();
        } catch (QueryException $exception) {
            $this->rethrowDuplicateName($exception);
        }

        $this->audit->log('operator_fee.updated', $operator_fee, [
            'name' => $operator_fee->name,
            'active' => $operator_fee->active,
            'pricing' => $operator_fee->pricing->value,
        ], request: $request);

        return new OperatorFeeResource($operator_fee);
    }

    public function destroy(Request $request, OperatorFee $operator_fee): JsonResponse
    {
        $this->authorize('delete', $operator_fee);

        if (BookingOperatorFee::query()->where('operator_fee_id', $operator_fee->id)->exists()) {
            return response()->json([
                'message' => 'This fee is on a booking. Mark it inactive instead.',
            ], 409);
        }

        $this->audit->log('operator_fee.deleted', $operator_fee, ['name' => $operator_fee->name], request: $request);
        $operator_fee->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    private function description(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function rethrowDuplicateName(QueryException $exception): never
    {
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            throw ValidationException::withMessages([
                'name' => 'A fee with that name already exists.',
            ]);
        }

        throw $exception;
    }
}
