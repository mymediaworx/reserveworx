<?php

namespace App\Http\Controllers\Web;

use App\Enums\ImportType;
use App\Models\CsvImport;
use App\Models\User;
use App\Services\Import\ImportCatalog;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffImportTemplateController
{
    public function __invoke(string $type, ImportCatalog $catalog): StreamedResponse
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->can('create', CsvImport::class), 403);

        $importType = ImportType::tryFrom($type);
        abort_if($importType === null, 404);

        $header = $catalog->header($importType);
        $examples = $catalog->examples($importType);

        return response()->streamDownload(function () use ($header, $examples): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);

            foreach ($examples as $example) {
                fputcsv($out, $example);
            }

            fclose($out);
        }, $catalog->templateFilename($importType), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
