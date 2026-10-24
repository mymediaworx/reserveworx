<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AddOnKind;
use App\Enums\AddOnPricing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAddOnRequest;
use App\Http\Requests\Api\V1\UpdateAddOnRequest;
use App\Http\Resources\AddOnResource;
use App\Models\AddOn;
use App\Models\BookingAddOn;
use App\Models\Tenant;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class AddOnController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AddOn::class);

        return AddOnResource::collection(AddOn::query()->orderBy('name')->get());
    }

    public function store(StoreAddOnRequest $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $kind = $request->enum('kind', AddOnKind::class);
        $pricing = $request->enum('pricing', AddOnPricing::class);
        abort_unless($kind instanceof AddOnKind && $pricing instanceof AddOnPricing, 422);

        try {
            $addOn = AddOn::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $request->string('name')->toString(),
                'description' => $this->description($request->input('description')),
                'kind' => $kind,
                'pricing' => $pricing,
                'amount_cents' => $request->integer('amount_cents'),
                'active' => $request->has('active') ? $request->boolean('active') : true,
                'max_quantity' => $pricing === AddOnPricing::Each && $request->filled('max_quantity') ? $request->integer('max_quantity') : null,
            ]);
        } catch (QueryException $exception) {
            $this->rethrowDuplicateName($exception);
        }

        $this->audit->log('add_on.created', $addOn, [
            'name' => $addOn->name,
            'kind' => $addOn->kind->value,
            'amount_cents' => $addOn->amount_cents,
        ], request: $request);

        return (new AddOnResource($addOn))->response()->setStatusCode(201);
    }

    public function show(Request $request, AddOn $add_on): AddOnResource
    {
        $this->authorize('view', $add_on);

        return new AddOnResource($add_on);
    }

    public function update(UpdateAddOnRequest $request, AddOn $add_on): AddOnResource
    {
        $add_on->fill($request->safe()->only([
            'name',
            'description',
            'kind',
            'pricing',
            'amount_cents',
            'active',
            'max_quantity',
        ]));

        if ($request->exists('description')) {
            $add_on->description = $this->description($request->input('description'));
        }

        if ($add_on->pricing !== AddOnPricing::Each) {
            $add_on->max_quantity = null;
        }

        try {
            $add_on->save();
        } catch (QueryException $exception) {
            $this->rethrowDuplicateName($exception);
        }

        $this->audit->log('add_on.updated', $add_on, [
            'name' => $add_on->name,
            'active' => $add_on->active,
            'amount_cents' => $add_on->amount_cents,
        ], request: $request);

        return new AddOnResource($add_on);
    }

    public function destroy(Request $request, AddOn $add_on): JsonResponse
    {
        $this->authorize('delete', $add_on);

        if (BookingAddOn::query()->where('add_on_id', $add_on->id)->exists()) {
            return response()->json([
                'message' => 'This add-on is on a booking. Mark it inactive instead.',
            ], 409);
        }

        $this->audit->log('add_on.deleted', $add_on, ['name' => $add_on->name], request: $request);
        $add_on->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    private function description(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function rethrowDuplicateName(QueryException $exception): never
    {
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            throw ValidationException::withMessages([
                'name' => 'An add-on with that name already exists.',
            ]);
        }

        throw $exception;
    }
}
