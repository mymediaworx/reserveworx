<?php

namespace App\Http\Controllers\Web;

use App\Domain\Fees\Disclosures\Money;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CustomerAccount;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\Customers\CustomerAccounts;
use App\Services\Customers\CustomerReceipts;
use App\Support\BookingReference;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Optional customer account on the workspace's own host. Guest checkout stays
 * on the booking widget, which does not use this session.
 */
class CustomerAccountPageController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request, CustomerReceipts $receipts): View
    {
        $account = $this->account($request);

        if (! $account instanceof CustomerAccount) {
            return $this->guest();
        }

        $upcoming = $this->bookings($account, 'upcoming');
        $past = $this->bookings($account, 'past');

        return view('account.show', [
            'account' => $account,
            'upcoming' => $this->rows($upcoming, $receipts),
            'past' => $this->rows($past, $receipts),
        ]);
    }

    public function store(Request $request, CustomerAccounts $accounts): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'confirmed', PasswordRule::min(12), 'max:255'],
        ]);
        $password = $validated['password'] ?? null;
        $name = $validated['name'] ?? null;

        $accounts->register(
            $this->tenant($request),
            $validated['email'],
            is_string($name) ? $name : null,
            is_string($password) && $password !== '' ? $password : null,
            $request,
        );

        return redirect('/account')->with('status', CustomerAccounts::REQUEST_STATUS);
    }

    public function login(Request $request, CustomerAccounts $accounts): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $account = $accounts->login($validated['email'], $validated['password'], $request);
        $this->startSession($request, $account);

        return redirect('/account');
    }

    public function logout(Request $request): RedirectResponse
    {
        $account = $this->account($request);
        Auth::guard('customer_web')->logout();
        $request->session()->regenerateToken();

        if ($account instanceof CustomerAccount) {
            $this->audit->log(
                action: 'customer_auth.sign_out',
                subject: $account,
                properties: ['email' => $account->email, 'channel' => 'web'],
                request: $request,
            );
        }

        return redirect('/account');
    }

    public function password(Request $request, CustomerAccounts $accounts): RedirectResponse
    {
        $account = $this->account($request);

        if (! $account instanceof CustomerAccount) {
            return redirect('/account');
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(12), 'max:255'],
        ]);

        $accounts->setPassword($account, $validated['password'], $request);

        return redirect('/account')->with('status', 'Password saved.');
    }

    public function signIn(Request $request, CustomerAccounts $accounts, string $token): JsonResponse|RedirectResponse|View
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'email', 'max:255'],
            ]);
            $account = $accounts->consume($token, $validated['email'], $request);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            return $this->guest()->withErrors($exception->errors());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'token' => $accounts->issueToken($account),
                'token_type' => 'Bearer',
                'account' => $this->payload($account),
            ]);
        }

        $this->startSession($request, $account);

        return redirect('/account');
    }

    private function startSession(Request $request, CustomerAccount $account): void
    {
        Auth::guard('customer_web')->login($account);
        $request->session()->regenerate();
    }

    private function guest(): View
    {
        return view('account.show', [
            'account' => null,
            'upcoming' => [],
            'past' => [],
        ]);
    }

    private function account(Request $request): ?CustomerAccount
    {
        $account = Auth::guard('customer_web')->user();
        $tenant = $request->attributes->get('tenant');

        if (! $account instanceof CustomerAccount || ! $tenant instanceof Tenant || $account->tenant_id !== $tenant->id || $account->email_verified_at === null) {
            return null;
        }

        return $account;
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    /**
     * @return Collection<int, Booking>
     */
    private function bookings(CustomerAccount $account, string $when): Collection
    {
        $query = Booking::query()
            ->with(['service', 'location'])
            ->whereHas('customer', function (Builder $query) use ($account): void {
                $query->where('customer_account_id', $account->id);
            });

        if ($when === 'upcoming') {
            return $query->where('ends_at', '>=', now())->orderBy('starts_at')->orderBy('id')->limit(100)->get();
        }

        return $query->where('ends_at', '<', now())->orderByDesc('starts_at')->orderByDesc('id')->limit(100)->get();
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return list<array{id: string, reference: string, service: string, when: string, total: string, receipt: string}>
     */
    private function rows(Collection $bookings, CustomerReceipts $receipts): array
    {
        $rows = [];

        foreach ($bookings as $booking) {
            $receipt = $receipts->forBooking($booking);
            $html = $receipt['html'] ?? null;
            $rows[] = [
                'id' => $booking->id,
                'reference' => BookingReference::short($booking->id),
                'service' => $booking->service?->name ?? 'Booking',
                'when' => $this->localStart($booking),
                'total' => Money::format($receipt['total_cents'], $receipt['currency']),
                'receipt' => is_string($html) ? $html : '',
            ];
        }

        return $rows;
    }

    private function localStart(Booking $booking): string
    {
        $start = $booking->starts_at;
        $zone = $booking->location?->timezone;
        $zone = is_string($zone) && $zone !== '' ? $zone : 'UTC';

        if (! $start instanceof CarbonInterface) {
            return '';
        }

        return $start->copy()->timezone($zone)->format('D, M j, Y g:i A');
    }

    /**
     * @return array{id: string, email: string, name: string|null, email_verified_at: string|null}
     */
    private function payload(CustomerAccount $account): array
    {
        $verified = $account->email_verified_at;

        return [
            'id' => $account->id,
            'email' => $account->email,
            'name' => $account->name,
            'email_verified_at' => $verified instanceof CarbonInterface ? $verified->toIso8601String() : null,
        ];
    }
}
