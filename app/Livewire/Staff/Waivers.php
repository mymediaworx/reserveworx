<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\WithinTenant;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use App\Services\CheckIn\WaiverGate;
use App\Services\Waivers\WaiverSigner;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Write and publish the waivers guests sign before check-in (#134). The newest
 * version of each named waiver is the one guests must sign. Publishing a new
 * version keeps every earlier version and signature.
 */
#[Layout('layouts.staff')]
#[Title('Waivers')]
class Waivers extends Component
{
    use WithinTenant;

    public string $name = '';

    public string $body = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->requirePermission('settings.view');
    }

    public function edit(string $versionId): void
    {
        $this->requirePermission('settings.update');
        $version = WaiverVersion::query()->find($versionId);
        abort_unless($version instanceof WaiverVersion, 404);
        $this->name = $version->name;
        $this->body = $version->body;
        $this->flash = null;
    }

    public function publish(WaiverSigner $signer): void
    {
        $this->requirePermission('settings.update');
        $this->reset('flash');
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'min:20', 'max:20000'],
        ], [
            'body.min' => 'Write the full waiver text.',
        ]);

        $version = $signer->publish($this->tenant()->id, $validated['name'], $validated['body']);
        $this->audit('waiver.published', $version, [
            'name' => $version->name,
            'version' => $version->version,
            'content_hash' => $version->content_hash,
        ]);
        $this->reset('name', 'body');
        $this->flash = $version->version === 1
            ? $version->name.' is published. Guests now see a signing link on their confirmation.'
            : $version->name.' version '.$version->version.' is published. Guests who signed an earlier version are asked to sign again.';
    }

    public function render(WaiverGate $gate): View
    {
        $current = $gate->current();
        $counts = WaiverSignature::query()
            ->whereIn('waiver_version_id', $current->pluck('id'))
            ->selectRaw('waiver_version_id, count(*) as total')
            ->groupBy('waiver_version_id')
            ->pluck('total', 'waiver_version_id');

        return view('livewire.staff.waivers', [
            'current' => $current->values(),
            'history' => WaiverVersion::query()->orderBy('name')->orderByDesc('version')->get()->whereNotIn('id', $current->pluck('id'))->values(),
            'signatureCounts' => $counts,
            'canManage' => $this->allows('settings.update'),
        ]);
    }
}
