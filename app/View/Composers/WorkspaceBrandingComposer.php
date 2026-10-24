<?php

namespace App\View\Composers;

use App\Services\Branding\CurrentWorkspaceBranding;
use Illuminate\View\View;

/**
 * Shares the current workspace's branding with every existing view.
 */
final class WorkspaceBrandingComposer
{
    public function __construct(private readonly CurrentWorkspaceBranding $branding) {}

    public function compose(View $view): void
    {
        $view->with('workspaceBranding', $this->branding->resolve());
    }
}
