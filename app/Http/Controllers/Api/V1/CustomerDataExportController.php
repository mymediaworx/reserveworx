<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerDataExport;
use App\Models\User;
use App\Services\Privacy\CustomerDataExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerDataExportController extends Controller
{
    public function __construct(private readonly CustomerDataExporter $exports) {}

    public function store(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('export', $customer);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $export = $this->exports->queue($customer, $actor, $request);

        return response()->json(['data' => $this->exports->payload($customer, $export)], 202);
    }

    public function show(Request $request, Customer $customer, CustomerDataExport $export): JsonResponse
    {
        $this->authorize('export', $customer);

        return response()->json(['data' => $this->exports->payload($customer, $export)]);
    }

    public function download(Customer $customer, CustomerDataExport $export): StreamedResponse
    {
        return $this->exports->download($customer, $export);
    }
}
