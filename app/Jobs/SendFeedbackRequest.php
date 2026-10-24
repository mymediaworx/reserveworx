<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Feedback\FeedbackRequests;
use Throwable;

class SendFeedbackRequest extends TenantAwareJob
{
    public int $tries = 3;

    public function __construct(string $tenantId, public string $feedbackRequestId)
    {
        parent::__construct($tenantId);
    }

    protected function handleForTenant(Tenant $tenant): void
    {
        app(FeedbackRequests::class)->deliver($tenant, $this->feedbackRequestId);
    }

    public function failed(?Throwable $exception): void
    {
        app(FeedbackRequests::class)->markUndelivered($this->tenantId, $this->feedbackRequestId);
    }
}
