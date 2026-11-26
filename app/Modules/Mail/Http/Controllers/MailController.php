<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Mail\Models\MailAccount;
use App\Modules\Mail\Services\MailAccess;
use App\Modules\Mail\Services\MailAccounts;
use App\Modules\Mail\Services\MailServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⭐ মেইলের পর্দা আর তার ডাক — নিজের ট্যাবে (মালিক, ২৬ নভেম্বর ২০২৬, নমুনা v3 "ok")।
 *
 * ⓘ পাতাটা খালি কাঠামো; মেইলবক্স, ফোল্ডার, তালিকা আর মেইল আসে JSON-এ ([[mail-app.js]]) — একটা মেইলবক্স ধীর হলে পুরো পাতা আটকায় না।
 * ⛔ প্রতিটা ডাকে আগে [[MailAccess::mustOpen()]] — অন্যের ব্যক্তিগত বা না-দেওয়া দলের মেইলবক্স কোনো পথে খোলে না।
 * ⛔ মেইলের লেখা জমা হয় না; HTML দেখানো হয় স্যান্ডবক্সে (স্ক্রিপ্ট নয়), বাইরের ছবি প্রথমে বন্ধ — ট্র্যাকিং পিক্সেল থামে।
 */
final class MailController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MailServer $server,
        private readonly MailAccess $access,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:mail.use')];
    }

    public function index(Request $request): View
    {
        return view('mail::index', [
            'canManage' => $request->user()->can('mail.manage'),
            'canApprove' => $request->user()->can('mail.personal.approve'),
        ]);
    }

    public function accounts(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = $this->access->listed($user)->map(fn (MailAccount $a) => [
            'id' => $a->public_id,
            'kind' => $a->kind,
            'provider' => $a->provider,
            'email' => $a->email,
            'title' => $a->boxTitle(),
            'status' => $a->status,
            'usable' => $a->isUsable(),
            'expires' => $a->expires_on?->format('d-m-Y'),
            'people' => $a->kind === MailAccount::SHARED ? $a->members()->count() : 0,
        ])->values();

        $requests = $user->can('mail.personal.approve')
            ? MailAccount::query()->with('user:id,name')->where('kind', MailAccount::PERSONAL)->where('status', MailAccount::PENDING)->latest()->get()
                ->map(fn (MailAccount $a) => ['id' => $a->public_id, 'email' => $a->email, 'who' => (string) ($a->user?->name ?? ''),
                    'reason' => (string) $a->reason, 'expires' => $a->expires_on?->format('d-m-Y')])->values()
            : [];

        return response()->json(['accounts' => $rows, 'requests' => $requests]);
    }

    public function folders(Request $request, string $account): JsonResponse
    {
        $box = $this->open($request, $account);

        return $this->guarded(fn () => ['folders' => $this->server->folders($box)], $box);
    }

    public function list(Request $request, string $account): JsonResponse
    {
        $box = $this->open($request, $account);
        $folder = (string) $request->query('folder', 'INBOX');
        $page = max(1, (int) $request->query('page', 1));

        return $this->guarded(fn () => $this->server->list($box, $folder, $page, 40, (string) $request->query('q', '')) + ['page' => $page, 'per' => 40], $box);
    }

    public function read(Request $request, string $account): JsonResponse
    {
        $box = $this->open($request, $account);
        $folder = (string) $request->query('folder', 'INBOX');
        $uid = (int) $request->query('uid');

        return $this->guarded(function () use ($box, $folder, $uid, $request) {
            $mail = $this->server->read($box, $folder, $uid);
            $fromEmail = (string) ($mail['from'][0]['email'] ?? '');
            $domain = substr((string) strrchr($box->email, '@'), 1);

            $mail['external'] = $fromEmail !== '' && ! str_ends_with(mb_strtolower($fromEmail), '@'.mb_strtolower($domain));
            $mail['body'] = $this->safe($mail['html'] !== '' ? $mail['html'] : nl2br(e($mail['text'])), (bool) $request->boolean('images'));
            unset($mail['html'], $mail['text']);

            return $mail;
        }, $box);
    }

    public function file(Request $request, string $account): Response
    {
        $box = $this->open($request, $account);

        try {
            $f = $this->server->attachment($box, (string) $request->query('folder', 'INBOX'), (int) $request->query('uid'), (string) $request->query('part'));
        } catch (\Throwable) {
            abort(502, __('mail::message.server_error'));
        }

        $inline = $request->boolean('view') && (str_starts_with($f['mime'], 'image/') || $f['mime'] === 'application/pdf');

        return response($f['bytes'], 200, [
            'Content-Type' => $f['mime'],
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename*=UTF-8\'\''.rawurlencode($f['name']),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src data:; style-src 'unsafe-inline'",
        ]);
    }

    public function flag(Request $request, string $account): JsonResponse
    {
        $box = $this->open($request, $account);
        $data = $request->validate(['folder' => 'required|string|max:255', 'uid' => 'required|integer', 'flag' => 'required|in:seen,flagged', 'on' => 'required|boolean']);

        return $this->guarded(function () use ($box, $data) {
            $this->server->flag($box, $data['folder'], (int) $data['uid'], $data['flag'], (bool) $data['on']);

            return ['ok' => true];
        }, $box);
    }

    public function move(Request $request, string $account): JsonResponse
    {
        $box = $this->open($request, $account);
        $data = $request->validate(['folder' => 'required|string|max:255', 'uid' => 'required|integer', 'to' => 'required|string|max:255']);

        return $this->guarded(function () use ($box, $data) {
            $this->server->move($box, $data['folder'], (int) $data['uid'], $data['to']);

            return ['ok' => true];
        }, $box);
    }

    public function send(Request $request, string $account): JsonResponse
    {
        $box = $this->open($request, $account);
        abort_unless($this->access->canSendFrom($request->user(), $box), 403);

        $data = $request->validate([
            'to' => 'required|array|min:1|max:50', 'to.*' => 'email',
            'cc' => 'nullable|array|max:50', 'cc.*' => 'email',
            'bcc' => 'nullable|array|max:50', 'bcc.*' => 'email',
            'subject' => 'required|string|max:255',
            'html' => 'required|string|max:200000',
            'inReplyTo' => 'nullable|string|max:255',
            'files' => 'nullable|array|max:10', 'files.*' => 'file|max:20480',
        ]);

        $files = array_map(fn ($f) => ['name' => $f->getClientOriginalName(), 'mime' => $f->getMimeType() ?: 'application/octet-stream', 'bytes' => (string) file_get_contents($f->getRealPath())],
            $request->file('files', []));

        try {
            $id = $this->server->send($box, [
                'to' => $data['to'], 'cc' => $data['cc'] ?? [], 'bcc' => $data['bcc'] ?? [],
                'subject' => $data['subject'], 'html' => $this->safe($data['html'], true), 'inReplyTo' => $data['inReplyTo'] ?? null,
                'attachments' => $files,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => __('mail::message.send_failed', ['why' => mb_substr($e->getMessage(), 0, 160)])], 422);
        }

        return response()->json(['ok' => true, 'messageId' => $id, 'message' => __('mail::message.sent')]);
    }

    /**
     * ⭐ ডানের কার্ড — প্রেরকের ঠিকানা মিলিয়ে গ্রাহক বা সরবরাহকারী: বকেয়া, বাকির সীমা, খোলা বিল (নমুনা v3)।
     * ⛔ `customer.view` ছাড়া গ্রাহক নয়, `supplier.view` ছাড়া সরবরাহকারী নয় — মেইল দেখা মানে খাতা দেখা নয়।
     */
    public function party(Request $request): JsonResponse
    {
        $email = mb_strtolower(trim((string) $request->query('email', '')));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['party' => null]);
        }

        $user = $request->user();

        if ($user->can('customer.view')) {
            $c = \App\Modules\Customer\Models\Customer::query()->whereRaw('LOWER(email) = ?', [$email])->first();

            if ($c) {
                $due = (string) (\Illuminate\Support\Facades\DB::table('ledger_entries')->where('company_id', $c->company_id)
                    ->where('party_type', \App\Modules\Customer\Models\Customer::drillSourceType())->where('party_id', $c->id)
                    ->selectRaw('COALESCE(SUM(debit - credit), 0) b')->value('b') ?? '0');
                $bills = \App\Modules\Sales\Models\SalesInvoice::query()->where('customer_id', $c->id)->posted()->withCollected()
                    ->orderByDesc('trx_date')->limit(15)->get()
                    ->map(fn ($b) => ['no' => (string) $b->document_no, 'date' => $b->trx_date?->format('d-m'), 'total' => (string) $b->total, 'due' => $b->dueAmount()])
                    ->filter(fn ($b) => bccomp($b['due'], '0', 2) > 0)->take(5)->values();

                return response()->json(['party' => [
                    'kind' => 'customer', 'name' => $c->name(), 'point' => (string) ($c->pointName() ?? ''), 'phone' => (string) ($c->phone ?? ''),
                    'due' => $due, 'limit' => (string) ($c->credit_limit ?? '0'), 'bills' => $bills,
                    'links' => [
                        'page' => route('customer.show', $c->id),
                        'receipt' => \Illuminate\Support\Facades\Route::has('accounts.voucher.create') ? route('accounts.voucher.create', ['type' => 'receipt']) : null,
                        'bill' => \Illuminate\Support\Facades\Route::has('sales.direct.create') ? route('sales.direct.create') : null,
                    ],
                ]]);
            }
        }

        if ($user->can('supplier.view')) {
            $s = \App\Modules\Supplier\Models\Supplier::query()->whereRaw('LOWER(email) = ?', [$email])->first();

            if ($s) {
                return response()->json(['party' => [
                    'kind' => 'supplier', 'name' => $s->name(), 'point' => '', 'phone' => (string) ($s->phone ?? ''),
                    'due' => null, 'limit' => null, 'bills' => [],
                    'links' => ['page' => \Illuminate\Support\Facades\Route::has('supplier.show') ? route('supplier.show', $s->id) : null, 'receipt' => null, 'bill' => null],
                ]]);
            }
        }

        return response()->json(['party' => null]);
    }

    public function addAccount(Request $request, MailAccounts $accounts): JsonResponse
    {
        $data = $request->validate([
            'kind' => 'required|in:own,shared,personal',
            'provider' => 'required|in:company,gmail,outlook,yahoo,imap',
            'email' => 'required|email|max:191',
            'secret' => 'required|string|max:500',
            'label' => 'nullable|string|max:80',
            'username' => 'nullable|string|max:191',
            'imap_host' => 'nullable|string|max:191', 'imap_port' => 'nullable|integer|min:1|max:65535', 'imap_secure' => 'nullable|in:ssl,tls,none',
            'smtp_host' => 'nullable|string|max:191', 'smtp_port' => 'nullable|integer|min:1|max:65535', 'smtp_secure' => 'nullable|in:ssl,tls,none',
            'reason' => 'required_if:kind,personal|nullable|string|max:500',
            'months' => 'nullable|integer|min:1|max:24',
            'members' => 'nullable|array', 'members.*' => 'integer',
        ]);

        $box = $accounts->add($request->user(), $data);

        return response()->json(['ok' => true, 'id' => $box->public_id,
            'message' => __($box->status === MailAccount::PENDING ? 'mail::message.requested' : 'mail::message.added')]);
    }

    public function decide(Request $request, MailAccounts $accounts, string $account): JsonResponse
    {
        $box = MailAccount::query()->where('public_id', $account)->firstOrFail();
        $data = $request->validate(['yes' => 'required|boolean', 'note' => 'nullable|string|max:500']);
        $accounts->decide($request->user(), $box, (bool) $data['yes'], $data['note'] ?? null);

        return response()->json(['ok' => true, 'message' => __($data['yes'] ? 'mail::message.approved' : 'mail::message.rejected')]);
    }

    public function off(Request $request, MailAccounts $accounts, string $account): JsonResponse
    {
        $box = MailAccount::query()->where('public_id', $account)->firstOrFail();
        $accounts->turnOff($request->user(), $box);

        return response()->json(['ok' => true, 'message' => __('mail::message.turned_off')]);
    }

    // ── ভিতরের কাজ ───────────────────────────────────────────────────────

    private function open(Request $request, string $account): MailAccount
    {
        $box = MailAccount::query()->where('public_id', $account)->firstOrFail();
        $this->access->mustOpen($request->user(), $box);

        return $box;
    }

    /** ⓘ সার্ভারের ভুল পাতা ভাঙে না — কারণ লিখে রাখে, ব্যবহারকারী সাধারণ বার্তা পান */
    private function guarded(callable $work, MailAccount $box): JsonResponse
    {
        try {
            return response()->json($work());
        } catch (\Throwable $e) {
            $box->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->saveQuietly();
            report($e);

            return response()->json(['message' => __('mail::message.server_error')], 502);
        }
    }

    /**
     * ⛔ মেইলের HTML নিরাপদ করা — স্ক্রিপ্ট, ফ্রেম, ফর্ম আর on…= সরানো, javascript: লিংক বাতিল; বাইরের ছবি বন্ধ (চাইলে খোলে)।
     * ⓘ তারপরও দেখানো হয় স্যান্ডবক্স করা iframe-এ, স্ক্রিপ্ট ছাড়া — দুই স্তরের পাহারা।
     */
    private function safe(string $html, bool $images): string
    {
        $html = (string) preg_replace('#<(script|iframe|object|embed|form|meta|link|base)\b[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<(script|iframe|object|embed|form|meta|link|base)\b[^>]*/?>#is', '', $html);
        $html = (string) preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#is', '', $html);
        $html = (string) preg_replace('#(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2#is', '$1="#"', $html);

        if (! $images) {
            $html = (string) preg_replace('#(<img\b[^>]*?)\ssrc\s*=\s*(["\'])(https?:)?//#is', '$1 data-blocked-src=$2$3//', $html);
        }

        return $html;
    }
}
