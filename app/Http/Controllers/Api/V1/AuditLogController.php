<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);

        $validated = $request->validate([
            'action' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subject_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);

        $action = $this->filter($validated['action'] ?? null);
        $subjectId = $this->filter($validated['subject_id'] ?? null);

        $logs = AuditLog::query()
            ->when($action !== null, fn ($query) => $query->where('action', $action))
            ->when($subjectId !== null, fn ($query) => $query->where('subject_id', $subjectId))
            ->orderByDesc('created_at')
            ->paginate(25);

        return AuditLogResource::collection($logs);
    }

    private function filter(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
