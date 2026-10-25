<?php

namespace App\Services\Import;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Jobs\ProcessCsvImport;
use App\Models\CsvImport;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tenancy\TenantContextException;
use App\Tenancy\TenantManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Stores an uploaded CSV, previews its first rows, and writes one chunk per queued job.
 */
final class CsvImporter
{
    private ?string $pendingDelete = null;

    public function __construct(
        private readonly CsvTable $table,
        private readonly ImportCatalog $catalog,
        private readonly CustomerRowWriter $customers,
        private readonly InventoryRowWriter $inventory,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Tenant $tenant, User $user, ImportType $type, UploadedFile $upload): CsvImport
    {
        $import = new CsvImport;
        $import->id = $import->newUniqueId();
        $directory = 'imports/'.$tenant->id;
        $stored = $upload->storeAs($directory, $import->id.'.csv', 'local');

        if (! is_string($stored)) {
            throw new ImportException('That file could not be stored.');
        }

        try {
            $described = $this->table->describe(
                Storage::disk('local')->path($stored),
                $this->limit('max_rows', 100_000),
                $this->limit('max_columns', 50),
            );
        } catch (ImportException $exception) {
            Storage::disk('local')->delete($stored);

            throw $exception;
        }

        $suggested = $this->catalog->suggest($type, $described['headers']);
        $mapping = [];

        foreach ($suggested as $field => $index) {
            $mapping[$field] = $index === '' ? null : (int) $index;
        }

        $import->forceFill([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => $type,
            'status' => ImportStatus::Uploaded,
            'disk' => 'local',
            'path' => $stored,
            'original_name' => $this->originalName($upload),
            'delimiter' => $described['delimiter'],
            'headers' => $described['headers'],
            'mapping' => $mapping,
            'row_count' => $described['row_count'],
            'next_row' => 2,
            'data_offset' => $described['data_offset'],
            'byte_offset' => $described['data_offset'],
            'skipped' => [],
        ])->save();

        return $import;
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return array<string, int|null>
     */
    public function normalizeMapping(CsvImport $import, array $mapping): array
    {
        $normalized = [];
        $errors = [];
        $headerCount = count($import->headers);

        foreach ($this->catalog->fields($import->type) as $key => $field) {
            $chosen = $mapping[$key] ?? '';
            $index = is_int($chosen) || (is_string($chosen) && $chosen !== '') ? (string) $chosen : '';

            if ($index === '') {
                if ($field['required']) {
                    $errors['mapping.'.$key] = 'Choose a column for '.$field['label'].'.';
                }

                $normalized[$key] = null;

                continue;
            }

            if (! ctype_digit($index) || (int) $index >= $headerCount) {
                $errors['mapping.'.$key] = 'Choose a column from this file.';

                continue;
            }

            $normalized[$key] = (int) $index;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * @param  array<string, int|null>  $mapping
     * @return list<array{row: int, values: array<string, string>, errors: list<string>, notice: string}>
     */
    public function preview(CsvImport $import, array $mapping): array
    {
        if (! $this->pathIsSafe($import) || ! Storage::disk('local')->exists($import->path)) {
            throw new ImportException('The import file could not be read.');
        }

        $chunk = $this->table->read(
            Storage::disk('local')->path($import->path),
            $import->delimiter,
            $import->data_offset,
            2,
            $this->limit('preview_rows', 20),
        );
        $writer = $this->writer($import->type);
        $writer->prepare($import->tenant_id, $this->requiredActor($import));
        $rows = [];

        foreach ($chunk['rows'] as $row) {
            $values = $this->values($mapping, $row['cells']);
            $inspection = $writer->inspect($values);
            $rows[] = [
                'row' => $row['row'],
                'values' => $values,
                'errors' => $inspection['errors'],
                'notice' => $inspection['notice'],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, int|null>  $mapping
     */
    public function queue(CsvImport $import, array $mapping, User $user): void
    {
        if (! $user->can('update', $import)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $this->writer($import->type)->prepare($import->tenant_id, $user);

        DB::transaction(function () use ($import, $mapping, $user): void {
            $locked = CsvImport::query()->whereKey($import->id)->lockForUpdate()->first();

            if (! $locked instanceof CsvImport || $locked->status !== ImportStatus::Uploaded) {
                throw ValidationException::withMessages([
                    'file' => 'This file is already importing.',
                ]);
            }

            $locked->mapping = $mapping;
            $locked->status = ImportStatus::Processing;
            $locked->user_id = $user->id;
            $locked->save();
            $this->audit->log('import.queued', $locked, [
                'type' => $locked->type->value,
                'rows' => $locked->row_count,
            ], $locked->tenant_id, $user, request());
            ProcessCsvImport::dispatch($locked->tenant_id, $locked->id);
        });
    }

    public function processChunk(string $importId): void
    {
        $this->pendingDelete = null;
        $tenantId = app(TenantManager::class)->id();

        if ($tenantId === null) {
            throw new TenantContextException('Refusing to import a file without a workspace.');
        }

        DB::transaction(function () use ($importId, $tenantId): void {
            $import = CsvImport::query()->whereKey($importId)->lockForUpdate()->first();

            if (! $import instanceof CsvImport || $import->tenant_id !== $tenantId) {
                throw new TenantContextException('Refusing to import a file for a different workspace.');
            }

            if ($import->status !== ImportStatus::Processing) {
                return;
            }

            $actor = $this->actor($import);

            if (! $actor instanceof User || ! $actor->can('update', $import)) {
                $this->failImport($import, 'The person who started this import is no longer an owner or manager.');

                return;
            }

            if (! $this->pathIsSafe($import) || ! Storage::disk('local')->exists($import->path)) {
                $this->failImport($import, 'The import file could not be read.');

                return;
            }

            $chunk = $this->table->read(
                Storage::disk('local')->path($import->path),
                $import->delimiter,
                $import->byte_offset,
                $import->next_row,
                $this->chunkSize(),
            );

            if ($chunk['rows'] !== [] && $chunk['byte_offset'] <= $import->byte_offset) {
                $this->failImport($import, 'The import stopped before it finished. Run it again.');

                return;
            }

            $writer = $this->writer($import->type);

            try {
                $writer->prepare($import->tenant_id, $actor);
            } catch (ImportException $exception) {
                $this->failImport($import, $exception->getMessage());

                return;
            }

            foreach ($chunk['rows'] as $row) {
                $outcome = $writer->apply($this->values($import->mapping ?? [], $row['cells']));
                $import->processed_rows++;

                if ($outcome['outcome'] === 'created') {
                    $import->created_count++;
                } elseif ($outcome['outcome'] === 'updated') {
                    $import->updated_count++;
                } else {
                    $this->rememberSkip($import, $row['row'], $outcome['reason']);
                }
            }

            $import->byte_offset = $chunk['byte_offset'];
            $import->next_row = $chunk['next_row'];
            $done = $chunk['rows'] === [] || $import->processed_rows >= $import->row_count;

            if (! $done) {
                $import->save();
                ProcessCsvImport::dispatch($import->tenant_id, $import->id);

                return;
            }

            $import->status = ImportStatus::Completed;
            $import->completed_at = now();
            $import->save();
            $this->audit->log('import.completed', $import, $this->summary($import), $import->tenant_id, $actor, null);
            $this->pendingDelete = $import->path;
        });

        $this->flushDelete();
    }

    public function markFailed(CsvImport $import, string $reason): void
    {
        if ($import->status !== ImportStatus::Processing) {
            return;
        }

        $this->failImport($import, $reason);
        $this->flushDelete();
    }

    private function failImport(CsvImport $import, string $reason): void
    {
        $import->status = ImportStatus::Failed;
        $import->failure = $reason;
        $import->failed_at = now();
        $import->save();
        $this->audit->log('import.failed', $import, [
            'type' => $import->type->value,
            'reason' => $reason,
        ], $import->tenant_id, $this->actor($import), null);
        $this->pendingDelete = $import->path;
    }

    private function flushDelete(): void
    {
        if ($this->pendingDelete === null) {
            return;
        }

        $path = $this->pendingDelete;
        $this->pendingDelete = null;

        if (str_contains($path, '..')) {
            return;
        }

        Storage::disk('local')->delete($path);
    }

    private function pathIsSafe(CsvImport $import): bool
    {
        $prefix = 'imports/'.$import->tenant_id.'/';

        return $import->disk === 'local'
            && str_starts_with($import->path, $prefix)
            && ! str_contains($import->path, '..');
    }

    /**
     * @param  array<string, int|null>  $mapping
     * @param  list<string>  $cells
     * @return array<string, string>
     */
    private function values(array $mapping, array $cells): array
    {
        $values = [];

        foreach ($mapping as $field => $index) {
            if (! is_int($index)) {
                continue;
            }

            $values[$field] = $cells[$index] ?? '';
        }

        return $values;
    }

    private function rememberSkip(CsvImport $import, int $row, string $reason): void
    {
        $import->skipped_count++;
        $skipped = $import->skipped ?? [];

        if (count($skipped) < $this->limit('skipped_limit', 100)) {
            $skipped[] = [
                'row' => $row,
                'reason' => mb_substr($reason, 0, 500),
            ];
            $import->skipped = $skipped;
        }
    }

    /**
     * @return array{type: string, created: int, updated: int, skipped: int, rows: int}
     */
    private function summary(CsvImport $import): array
    {
        return [
            'type' => $import->type->value,
            'created' => $import->created_count,
            'updated' => $import->updated_count,
            'skipped' => $import->skipped_count,
            'rows' => $import->row_count,
        ];
    }

    private function requiredActor(CsvImport $import): User
    {
        $actor = $this->actor($import);

        if (! $actor instanceof User) {
            throw new ImportException('The person who started this import is no longer an owner or manager.');
        }

        return $actor;
    }

    private function actor(CsvImport $import): ?User
    {
        if ($import->user_id === null) {
            return null;
        }

        $user = User::query()->find($import->user_id);

        return $user instanceof User ? $user : null;
    }

    private function writer(ImportType $type): RowWriter
    {
        return match ($type) {
            ImportType::Customers => $this->customers,
            ImportType::Inventory => $this->inventory,
        };
    }

    private function chunkSize(): int
    {
        return max(1, min(500, $this->limit('chunk', 100)));
    }

    private function limit(string $key, int $default): int
    {
        $value = config('imports.'.$key);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function originalName(UploadedFile $upload): string
    {
        $name = basename(str_replace('\\', '/', $upload->getClientOriginalName()));
        $name = trim(str_replace("\0", '', $name));

        if ($name === '' || $name === '.' || $name === '..') {
            return 'import.csv';
        }

        return mb_substr($name, 0, 180);
    }
}
