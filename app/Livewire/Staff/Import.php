<?php

namespace App\Livewire\Staff;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Livewire\Concerns\WithinTenant;
use App\Models\CsvImport;
use App\Services\Import\CsvImporter;
use App\Services\Import\ImportCatalog;
use App\Services\Import\ImportException;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Upload a CSV, map its columns, preview the first rows, then import in queued chunks.
 */
#[Layout('layouts.staff')]
#[Title('Import')]
class Import extends Component
{
    use WithFileUploads, WithinTenant;

    public string $type = 'customers';

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    #[Locked]
    public string $importId = '';

    /** @var array<string, string> */
    public array $mapping = [];

    /**
     * @var list<array{row: int, values: array<string, string>, errors: list<string>, notice: string}>
     */
    public array $previewRows = [];

    public string $step = 'upload';

    public int $uploadKey = 0;

    public function mount(): void
    {
        $this->authorize('viewAny', CsvImport::class);
        $remembered = session($this->sessionKey());

        if (! is_string($remembered) || $remembered === '') {
            return;
        }

        $import = CsvImport::query()->find($remembered);

        if (! $import instanceof CsvImport) {
            session()->forget($this->sessionKey());

            return;
        }

        $this->authorize('view', $import);
        $this->restore($import);
    }

    public function stageFile(CsvImporter $importer): void
    {
        $this->authorize('create', CsvImport::class);
        $maxKilobytes = $this->maxKilobytes();
        $this->validate([
            'type' => ['required', 'in:customers,inventory'],
            'file' => ['required', 'file', 'extensions:csv,txt', 'max:'.$maxKilobytes],
        ], [
            'file.required' => 'Choose a CSV file.',
            'file.extensions' => 'Choose a CSV file.',
            'file.max' => 'That file is larger than '.number_format($maxKilobytes / 1024).' MB.',
        ]);

        $type = ImportType::from($this->type);
        $upload = $this->file;

        if (! $upload instanceof TemporaryUploadedFile) {
            $this->addError('file', 'Choose a CSV file.');

            return;
        }

        $this->discardOpenUpload();

        try {
            $import = $importer->store($this->tenant(), $this->currentUser(), $type, $upload);
        } catch (ImportException $exception) {
            $this->addError('file', $exception->getMessage());

            return;
        }

        $this->file = null;
        $this->previewRows = [];
        $this->restore($import);
        $this->step = 'map';
        $this->remember($import);
    }

    public function preview(CsvImporter $importer): void
    {
        $import = $this->currentImport();
        $this->authorize('update', $import);

        if ($import->status !== ImportStatus::Uploaded) {
            $this->step = 'result';

            return;
        }

        $mapping = $importer->normalizeMapping($import, $this->mapping);
        $import->mapping = $mapping;
        $import->save();

        try {
            $this->previewRows = $importer->preview($import, $mapping);
        } catch (ImportException $exception) {
            $this->addError('file', $exception->getMessage());

            return;
        }

        $this->step = 'preview';
        $this->remember($import);
    }

    public function runImport(CsvImporter $importer): void
    {
        $import = $this->currentImport();
        $this->authorize('update', $import);

        if ($import->row_count < 1) {
            $this->addError('file', 'This file has no data rows.');

            return;
        }

        $mapping = $importer->normalizeMapping($import, $this->mapping);

        try {
            $importer->queue($import, $mapping, $this->currentUser());
        } catch (ImportException $exception) {
            $this->addError('file', $exception->getMessage());

            return;
        }

        $this->step = 'result';
        $this->remember($import);
    }

    public function refreshImport(): void
    {
        if ($this->importId === '') {
            return;
        }

        $this->currentImport();
    }

    public function backToMap(): void
    {
        $import = $this->currentImport();
        $this->authorize('view', $import);

        if ($import->status === ImportStatus::Uploaded) {
            $this->step = 'map';
        }
    }

    public function startOver(): void
    {
        $this->authorize('viewAny', CsvImport::class);
        $this->discardOpenUpload();
        session()->forget($this->sessionKey());
        $this->reset('importId', 'mapping', 'previewRows', 'file', 'step', 'type');
        $this->type = 'customers';
        $this->step = 'upload';
        $this->uploadKey++;
        $this->resetValidation();
    }

    public function render(ImportCatalog $catalog): View
    {
        $import = $this->importId === '' ? null : CsvImport::query()->find($this->importId);

        if ($this->importId !== '' && ! $import instanceof CsvImport) {
            abort(404);
        }

        $type = $import instanceof CsvImport ? $import->type : ImportType::tryFrom($this->type);
        $fields = $type instanceof ImportType ? $catalog->fields($type) : [];
        $shown = $import instanceof CsvImport ? count($import->skipped ?? []) : 0;

        return view('livewire.staff.import', [
            'import' => $import,
            'fields' => $fields,
            'skippedMore' => $import instanceof CsvImport ? max(0, $import->skipped_count - $shown) : 0,
        ]);
    }

    private function restore(CsvImport $import): void
    {
        $this->importId = $import->id;
        $this->type = $import->type->value;
        $this->mapping = [];

        foreach ($import->mapping ?? [] as $field => $index) {
            $this->mapping[$field] = is_int($index) ? (string) $index : '';
        }

        $this->step = match ($import->status) {
            ImportStatus::Uploaded => $this->step === 'preview' ? 'preview' : 'map',
            ImportStatus::Processing, ImportStatus::Completed, ImportStatus::Failed => 'result',
        };
    }

    private function currentImport(): CsvImport
    {
        $import = CsvImport::query()->find($this->importId);
        abort_unless($import instanceof CsvImport, 404);
        $this->authorize('view', $import);

        return $import;
    }

    private function discardOpenUpload(): void
    {
        if ($this->importId === '') {
            return;
        }

        $existing = CsvImport::query()->find($this->importId);

        if (! $existing instanceof CsvImport || $existing->status !== ImportStatus::Uploaded) {
            return;
        }

        $this->authorize('update', $existing);

        if ($existing->disk === 'local' && ! str_contains($existing->path, '..')) {
            Storage::disk('local')->delete($existing->path);
        }

        $existing->delete();
        $this->importId = '';
    }

    private function remember(CsvImport $import): void
    {
        session()->put($this->sessionKey(), $import->id);
    }

    private function sessionKey(): string
    {
        return 'csv-import.'.$this->tenant()->id;
    }

    private function maxKilobytes(): int
    {
        $value = config('imports.max_kilobytes');

        return is_numeric($value) ? (int) $value : 8192;
    }
}
