<?php

use App\Models\Booking;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$payload = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);

try {
    $booking = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($payload['booking_id']);
    $assetId = isset($payload['asset_id']) && is_string($payload['asset_id']) && $payload['asset_id'] !== ''
        ? $payload['asset_id']
        : null;
    $result = app(Allocator::class)->extend($booking, CarbonImmutable::parse($payload['ends_at']), $assetId);

    if ($result['offers'] !== []) {
        fwrite(STDERR, 'extension-rejected '.json_encode($result['offers'], JSON_THROW_ON_ERROR)."\n");
        echo json_encode(['ok' => false, 'offers' => $result['offers']], JSON_THROW_ON_ERROR);
        exit(2);
    }

    echo json_encode(['ok' => true, 'ends_at' => $result['booking']->ends_at->utc()->format('Y-m-d\TH:i:s\Z')], JSON_THROW_ON_ERROR);
} catch (AllocationUnavailableException $exception) {
    fwrite(STDERR, $exception->getFile().':'.$exception->getLine().' '.$exception->getMessage()."\n");
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR);
    exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.' '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(3);
}
