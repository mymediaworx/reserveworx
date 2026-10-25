<div class="page import-page">
    <header class="page-head">
        <div>
            <h1>Import</h1>
            <p class="lede">Upload a CSV of customers or inventory. Match the columns, check the first 20 rows, then import. A large file keeps going in the background.</p>
        </div>
    </header>

    <ol class="import-steps" aria-label="Import steps">
        <li @class(['is-current' => $step === 'upload'])>1. Upload</li>
        <li @class(['is-current' => $step === 'map'])>2. Map columns</li>
        <li @class(['is-current' => $step === 'preview'])>3. Preview</li>
        <li @class(['is-current' => $step === 'result'])>4. Result</li>
    </ol>

    @if ($step === 'upload')
        <section class="panel">
            <h2>Upload a CSV</h2>
            <p class="muted">Customers match on email. An email already in this workspace is updated, not added again. Inventory matches an item name at a location. Kind is <code>asset</code> for one tracked item or <code>pool</code> for a count of the same item. Location and category names must already exist here.</p>
            <div class="choice-picks top-gap">
                <a class="button" href="/staff/import/templates/customers">Customer template</a>
                <a class="button" href="/staff/import/templates/inventory">Inventory template</a>
            </div>
            <form wire:submit="stageFile" class="stack top-gap">
                <label class="field">
                    <span>What are you importing?</span>
                    <select wire:model="type">
                        <option value="customers">Customers</option>
                        <option value="inventory">Inventory items</option>
                    </select>
                </label>
                <label class="field">
                    <span>CSV file</span>
                    <input wire:key="file-{{ $uploadKey }}" type="file" wire:model="file" accept=".csv,text/csv">
                </label>
                <p class="muted">CSV or TXT, up to 8 MB and 100,000 rows. Save it as UTF-8.</p>
                <div wire:loading wire:target="file" class="muted">Uploading the file…</div>
                @error('file')<p class="error">{{ $message }}</p>@enderror
                @error('type')<p class="error">{{ $message }}</p>@enderror
                <button type="submit" class="button primary" wire:loading.attr="disabled" wire:target="stageFile,file">Upload CSV</button>
            </form>
        </section>
    @endif

    @if ($step === 'map' && $import)
        <section class="panel">
            <h2>Match the columns</h2>
            <p class="muted">{{ $import->original_name }} · {{ number_format($import->row_count) }} {{ $import->row_count === 1 ? 'data row' : 'data rows' }}</p>
            <form wire:submit="preview" class="stack top-gap">
                @foreach ($fields as $key => $field)
                    <label class="field">
                        <span>{{ $field['label'] }}@if ($field['required']) (required)@endif</span>
                        <select wire:model="mapping.{{ $key }}">
                            <option value="">Don't import</option>
                            @foreach ($import->headers as $index => $header)
                                <option value="{{ $index }}">{{ $header !== '' ? $header : 'Column '.($index + 1) }} (column {{ $index + 1 }})</option>
                            @endforeach
                        </select>
                    </label>
                    @error('mapping.'.$key)<p class="error">{{ $message }}</p>@enderror
                @endforeach
                @error('file')<p class="error">{{ $message }}</p>@enderror
                <div class="import-actions">
                    <button type="submit" class="button primary">Preview first 20 rows</button>
                    <button type="button" class="button" wire:click="startOver">Choose a different file</button>
                </div>
            </form>
        </section>
    @endif

    @if ($step === 'preview' && $import)
        <section class="panel">
            <h2>First {{ count($previewRows) }} {{ count($previewRows) === 1 ? 'row' : 'rows' }}</h2>
            @if ($import->row_count > count($previewRows))
                <p class="muted">The rest of the file is imported with the same checks. Nothing is saved until you import.</p>
            @endif
            @if ($previewRows === [])
                <p class="empty">This file has no data rows.</p>
            @else
                <div class="preview-list top-gap">
                    @foreach ($previewRows as $row)
                        <article class="preview-card">
                            <header>
                                <strong>Row {{ $row['row'] }}</strong>
                                @if ($row['errors'] !== [])
                                    <span class="badge badge-payment_exception">Needs a fix</span>
                                @elseif ($row['notice'] !== '')
                                    <span class="badge badge-held">Will update</span>
                                @else
                                    <span class="badge badge-confirmed">Ready</span>
                                @endif
                            </header>
                            <dl>
                                @foreach ($fields as $key => $field)
                                    @if (array_key_exists($key, $row['values']))
                                        <div>
                                            <dt>{{ $field['label'] }}</dt>
                                            <dd>{{ $row['values'][$key] !== '' ? $row['values'][$key] : '—' }}</dd>
                                        </div>
                                    @endif
                                @endforeach
                            </dl>
                            @foreach ($row['errors'] as $error)
                                <p class="error">{{ $error }}</p>
                            @endforeach
                            @if ($row['errors'] === [] && $row['notice'] !== '')
                                <p class="muted">{{ $row['notice'] }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
            @error('file')<p class="error">{{ $message }}</p>@enderror
            <div class="import-actions top-gap">
                @if ($import->row_count > 0)
                    <button type="button" class="button primary" wire:click="runImport" wire:loading.attr="disabled" wire:target="runImport">Import</button>
                @endif
                <button type="button" class="button" wire:click="backToMap">Back</button>
                <button type="button" class="button" wire:click="startOver">Choose a different file</button>
            </div>
        </section>
    @endif

    @if ($step === 'result' && $import)
        <section class="panel" @if ($import->status === \App\Enums\ImportStatus::Processing) wire:poll.3s="refreshImport" @endif aria-live="polite">
            <h2>Result</h2>
            @if ($import->status === \App\Enums\ImportStatus::Processing)
                <p class="notice">Importing {{ number_format($import->processed_rows) }} of {{ number_format($import->row_count) }} rows. A large file finishes in the background, so you can leave this page and come back.</p>
            @elseif ($import->status === \App\Enums\ImportStatus::Failed)
                <p class="flash bad">{{ $import->failure }}</p>
            @else
                <p class="flash ok">Import finished.</p>
            @endif

            <div class="stat-grid import-stats">
                <div class="stat-card" data-import-created="{{ $import->created_count }}">
                    <span class="stat-body">
                        <span class="stat-title">Created</span>
                        <span class="stat-value">{{ number_format($import->created_count) }}</span>
                    </span>
                </div>
                <div class="stat-card" data-import-updated="{{ $import->updated_count }}">
                    <span class="stat-body">
                        <span class="stat-title">Updated</span>
                        <span class="stat-value">{{ number_format($import->updated_count) }}</span>
                    </span>
                </div>
                <div class="stat-card" data-import-skipped="{{ $import->skipped_count }}">
                    <span class="stat-body">
                        <span class="stat-title">Skipped</span>
                        <span class="stat-value">{{ number_format($import->skipped_count) }}</span>
                    </span>
                </div>
            </div>

            @if ($import->skipped_count > 0)
                <h3>Skipped rows</h3>
                <ul class="skip-list">
                    @foreach ($import->skipped ?? [] as $skip)
                        <li>Row {{ $skip['row'] }}: {{ $skip['reason'] }}</li>
                    @endforeach
                </ul>
                @if ($skippedMore > 0)
                    <p class="muted">And {{ number_format($skippedMore) }} more skipped {{ $skippedMore === 1 ? 'row' : 'rows' }}.</p>
                @endif
            @endif

            @if ($import->status !== \App\Enums\ImportStatus::Processing)
                <div class="import-actions top-gap">
                    <button type="button" class="button primary" wire:click="startOver">Import another file</button>
                </div>
            @endif
        </section>
    @endif
</div>
