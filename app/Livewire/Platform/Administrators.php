<?php

namespace App\Livewire\Platform;

use App\Models\User;
use App\Services\Platform\PlatformAdminRevokeException;
use App\Services\Platform\PlatformAdminRevoker;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.platform')]
#[Title('Administrators')]
class Administrators extends Component
{
    public ?string $notice = null;

    public ?string $warning = null;

    public function remove(string $email, PlatformAdminRevoker $revoker): void
    {
        $this->guard();
        $this->notice = null;
        $this->warning = null;

        try {
            $revoker->revoke($email, $this->actor());
        } catch (PlatformAdminRevokeException $exception) {
            $this->warning = $exception->getMessage();

            return;
        }

        $this->notice = 'Platform administrator access removed.';
    }

    public function render(PlatformAdminRevoker $revoker): View
    {
        $this->guard();

        return view('livewire.platform.administrators', [
            'administrators' => $revoker->list($this->actor()),
        ]);
    }

    private function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private function guard(): void
    {
        $actor = $this->actor();

        abort_unless($actor instanceof User && $actor->isPlatformAdmin(), 403, 'Platform administrator access is required.');
    }
}
