<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CorporateAccount;
use App\Models\Tenant;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CorporateAccountController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CorporateAccount::class);

        $accounts = CorporateAccount::query()->orderBy('name')->get();

        return response()->json([
            'data' => $accounts->map(fn (CorporateAccount $account): array => $this->payload($account))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', CorporateAccount::class);
        $tenant = $this->tenant($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('corporate_accounts', 'name')->where('tenant_id', $tenant->id)],
        ]);

        $account = CorporateAccount::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'active' => true,
        ]);
        $this->audit->log('corporate_account.created', $account, ['name' => $account->name], request: $request);

        return response()->json(['data' => $this->payload($account)], 201);
    }

    public function update(Request $request, CorporateAccount $corporate_account): JsonResponse
    {
        $this->authorize('update', $corporate_account);
        $tenant = $this->tenant($request);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('corporate_accounts', 'name')->where('tenant_id', $tenant->id)->ignore($corporate_account->id)],
            'active' => ['sometimes', 'boolean'],
        ]);

        if ($validated === []) {
            throw ValidationException::withMessages([
                'name' => 'Send a name or whether the account is active.',
            ]);
        }

        $corporate_account->fill($validated)->save();
        $this->audit->log('corporate_account.updated', $corporate_account, [
            'name' => $corporate_account->name,
            'active' => $corporate_account->active,
        ], request: $request);

        return response()->json(['data' => $this->payload($corporate_account)]);
    }

    /**
     * @return array{id: string, name: string, active: bool}
     */
    private function payload(CorporateAccount $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'active' => $account->active,
        ];
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }
}
