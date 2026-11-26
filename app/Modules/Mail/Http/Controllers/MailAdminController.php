<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http\Controllers;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Mail\Models\MailAddress;
use App\Modules\Mail\Services\MailAddresses;
use App\Modules\Mail\Services\MailHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ⭐ মেইল ঠিকানা ও ডোমেইন — প্রশাসন (নমুনা v4 পর্দা ৭–১২, মালিকের "ok" ২৬ নভেম্বর ২০২৬)।
 * ⓘ চাবি `mail.manage`; মোছা কেবল মালিক বা `mail.addresses.delete` ([[MailAddresses::canDelete]])।
 * ⓘ সার্ভারের সংযোগ না থাকলে পাতা খাতার সারি দেখায় আর জানায়; ডেমোতে সার্ভারে কিছু বদলায় না (`writable`)।
 */
final class MailAdminController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MailAddresses $addresses,
        private readonly MailHost $host,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:mail.manage')];
    }

    public function index(Request $request): View
    {
        $server = $this->addresses->sync();
        $domains = [];

        try {
            $domains = $this->host->configured() ? $this->host->domains() : [];
        } catch (\Throwable $e) {
            report($e);
        }

        $all = MailAddress::query()->with(['user:id,name,designation,two_factor_secret', 'account.members:id,name'])->orderBy('email')->get();
        $domains = collect($domains)->merge($all->pluck('domain'))->map(fn ($d) => mb_strtolower((string) $d))->filter()->unique()->values();

        $health = $domains->mapWithKeys(function ($d) use ($all) {
            try {
                $dns = $this->host->dns($d);
            } catch (\Throwable) {
                $dns = ['mx' => false, 'spf' => false, 'dkim' => false, 'dmarc' => false];
            }

            return [$d => $dns + ['count' => $all->where('domain', $d)->where('status', '!=', MailAddress::DELETED)->count()]];
        });

        $live = $all->where('status', '!=', MailAddress::DELETED);
        $usedMb = collect($server)->sum('used_mb');
        $quotaMb = $live->sum('quota_mb');

        $chip = (string) $request->query('show', 'all');
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $rows = $live
            ->when($chip === 'active', fn ($c) => $c->where('status', MailAddress::ACTIVE))
            ->when($chip === 'paused', fn ($c) => $c->whereIn('status', [MailAddress::PAUSED, MailAddress::DELETING]))
            ->when($chip === 'shared', fn ($c) => $c->where('kind', MailAddress::SHARED))
            ->when($q !== '', fn ($c) => $c->filter(fn (MailAddress $a) => str_contains(mb_strtolower($a->email.' '.$a->display_name.' '.$a->user?->name), $q)))
            ->values();

        return view('mail::admin.index', [
            'rows' => $rows,
            'server' => $server,
            'domains' => $domains,
            'health' => $health,
            'kpi' => [
                'domains' => $domains->count(),
                'domains_ok' => $health->filter(fn ($h) => $h['mx'] && $h['spf'] && $h['dkim'] && $h['dmarc'])->count(),
                'active' => $live->where('status', MailAddress::ACTIVE)->count(),
                'new_month' => $live->where('created_at', '>=', now()->startOfMonth())->count(),
                'paused' => $live->whereIn('status', [MailAddress::PAUSED, MailAddress::DELETING])->count(),
                'used_gb' => round($usedMb / 1024, 1),
                'quota_gb' => round($quotaMb / 1024, 1),
                'attention' => $health->filter(fn ($h) => ! ($h['mx'] && $h['spf'] && $h['dkim'] && $h['dmarc']))->keys()->all(),
            ],
            'counts' => [
                'all' => $live->count(),
                'active' => $live->where('status', MailAddress::ACTIVE)->count(),
                'paused' => $live->whereIn('status', [MailAddress::PAUSED, MailAddress::DELETING])->count(),
                'shared' => $live->where('kind', MailAddress::SHARED)->count(),
            ],
            'chip' => $chip,
            'q' => $q,
            'people' => User::query()->whereHas('companies', fn ($c) => $c->where('companies.id', CompanyContext::companyId()))
                ->where('is_active', true)->orderBy('name')->get(['id', 'name', 'designation', 'email']),
            'configured' => $this->host->configured(),
            'writable' => $this->host->writable(),
            'canDelete' => $this->addresses->canDelete($request->user()),
            'password' => session('mail_password'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => 'required|in:person,shared',
            'user_id' => 'required_if:kind,person|nullable|integer|exists:users,id',
            'local' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/i'],
            'domain' => 'required|string|max:120',
            'display_name' => 'nullable|string|max:120',
            'quota_mb' => 'required|integer|min:256|max:102400',
            'deliver' => 'required|in:messenger,screen',
            'forward_to' => 'nullable|email|max:191',
            'members' => 'nullable|array', 'members.*.user_id' => 'integer|exists:users,id', 'members.*.can_send' => 'nullable|boolean',
        ]);

        $done = $this->addresses->create($request->user(), $data);

        return back()->with('mail_flash', __('mail::message.address_created', ['email' => $done['address']->email]))
            ->with('mail_password', $done['password'] ? ['email' => $done['address']->email, 'password' => $done['password']] : null);
    }

    public function update(Request $request, string $address): RedirectResponse
    {
        $row = $this->find($address);
        $data = $request->validate([
            'display_name' => 'nullable|string|max:120',
            'quota_mb' => 'required|integer|min:256|max:102400',
            'sender_with_person' => 'nullable|boolean',
            'forward_to' => 'nullable|email|max:191',
        ]);

        $this->addresses->edit($request->user(), $row, $data + ['sender_with_person' => $request->boolean('sender_with_person')]);

        if (($data['forward_to'] ?? null) !== $row->forward_to && $row->status === MailAddress::ACTIVE) {
            $this->addresses->forward($request->user(), $row, $data['forward_to'] ?? null);
        }

        return back()->with('mail_flash', __('mail::message.saved'));
    }

    public function members(Request $request, string $address): RedirectResponse
    {
        $row = $this->find($address);
        $data = $request->validate(['members' => 'nullable|array', 'members.*.user_id' => 'integer|exists:users,id', 'members.*.can_send' => 'nullable|boolean']);
        $this->addresses->members($request->user(), $row, array_map(fn ($m) => ['user_id' => (int) $m['user_id'], 'can_send' => (bool) ($m['can_send'] ?? false)], (array) ($data['members'] ?? [])));

        return back()->with('mail_flash', __('mail::message.saved'));
    }

    public function password(Request $request, string $address): RedirectResponse
    {
        $row = $this->find($address);
        $data = $request->validate(['deliver' => 'required|in:messenger,screen']);
        $shown = $this->addresses->resetPassword($request->user(), $row, $data['deliver']);

        return back()->with('mail_flash', __('mail::message.password_reset'))
            ->with('mail_password', $shown ? ['email' => $row->email, 'password' => $shown] : null);
    }

    public function pause(Request $request, string $address): RedirectResponse
    {
        $row = $this->find($address);
        $data = $request->validate([
            'mode' => 'required|in:forward,bounce',
            'forward_to' => 'required_if:mode,forward|nullable|email|max:191',
            'reason' => 'nullable|string|max:200',
            'resume_on' => 'nullable|date|after:today',
        ]);

        $this->addresses->pause($request->user(), $row, $data['mode'], $data['forward_to'] ?? null, $data['reason'] ?? null, $data['resume_on'] ?? null);

        return back()->with('mail_flash', __('mail::message.paused', ['email' => $row->email]));
    }

    public function resume(Request $request, string $address): RedirectResponse
    {
        $row = $this->find($address);
        $this->addresses->resume($request->user(), $row);

        return back()->with('mail_flash', __('mail::message.resumed', ['email' => $row->email]));
    }

    public function delete(Request $request, string $address): RedirectResponse
    {
        $row = $this->find($address);
        $data = $request->validate(['typed' => 'required|string|max:191', 'password' => 'required|string|max:200', 'hand_to' => 'nullable|integer|exists:users,id']);
        $this->addresses->delete($request->user(), $row, $data['typed'], $data['password'], isset($data['hand_to']) ? (int) $data['hand_to'] : null);

        return back()->with('mail_flash', __('mail::message.deleting', ['email' => $row->email, 'date' => $row->fresh()->delete_on?->format('d-m-Y')]));
    }

    public function restore(Request $request, string $address): RedirectResponse
    {
        $row = $this->find($address);
        $this->addresses->restore($request->user(), $row);

        return back()->with('mail_flash', __('mail::message.restored', ['email' => $row->email]));
    }

    /** ⓘ Excel-এ খোলে এমন CSV — ঠিকানা, কার, ধরন, জায়গা, অবস্থা */
    public function export(): StreamedResponse
    {
        $rows = MailAddress::query()->with('user:id,name')->where('status', '!=', MailAddress::DELETED)->orderBy('email')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('mail::field.admin.col_address'), __('mail::field.admin.col_owner'), __('mail::field.admin.col_kind'), __('mail::field.admin.col_quota'), __('mail::field.admin.col_status')]);

            foreach ($rows as $r) {
                fputcsv($out, [$r->email, (string) ($r->user?->name ?? $r->display_name ?? ''), __('mail::field.admin.kind_'.$r->kind), $r->quota_mb.' MB', __('mail::field.admin.status_'.$r->status)]);
            }

            fclose($out);
        }, 'mail-addresses-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function find(string $publicId): MailAddress
    {
        $row = MailAddress::query()->where('public_id', $publicId)->firstOrFail();

        if ($row->status === MailAddress::DELETED) {
            throw ValidationException::withMessages(['email' => __('mail::message.already_deleted')]);
        }

        return $row;
    }
}
