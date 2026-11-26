<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Mail\Models\MailAccount;
use App\Modules\Mail\Models\MailLink;
use App\Modules\Mail\Models\MailTemplate;
use App\Modules\Mail\Services\MailAccess;
use App\Modules\Mail\Services\MailAccounts;
use App\Modules\Mail\Services\MailDefaults;
use App\Modules\Mail\Services\MailFields;
use App\Modules\Mail\Services\MailPapers;
use App\Modules\Mail\Services\MailSends;
use App\Modules\Mail\Services\MailServer;
use App\Modules\Mail\Services\MailSignatures;
use App\Modules\Mail\Services\MailTemplates;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⭐ মেইলের পর্দা আর তার ডাক — নিজের ট্যাবে (মালিক, ২৬ নভেম্বর ২০২৬, নমুনা v4 "ok")।
 *
 * ⓘ পাতাটা খালি কাঠামো; মেইলবক্স, ফোল্ডার, তালিকা আর মেইল আসে JSON-এ ([[mail-page.js]]) — একটা মেইলবক্স ধীর হলে পুরো পাতা আটকায় না।
 * ⛔ প্রতিটা ডাকে আগে [[MailAccess::mustOpen()]] — অন্যের ব্যক্তিগত বা না-দেওয়া দলের মেইলবক্স কোনো পথে খোলে না, মালিকও না।
 * ⛔ মেইলের লেখা জমা হয় না; HTML দেখানো হয় স্যান্ডবক্সে (স্ক্রিপ্ট নয়), বাইরের ছবি প্রথমে বন্ধ — ট্র্যাকিং পিক্সেল থামে।
 * ⓘ পাঠানো, দলের মেইলবক্সে মোছা/সরানো, গ্রাহকের পাতায় রাখা — সব খাতায় ([[MailSends]], অডিট)।
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

    public function index(Request $request, MailDefaults $defaults): View
    {
        $defaults->ensure();

        return view('mail::index', [
            'canManage' => $request->user()->can('mail.manage'),
            'canApprove' => $request->user()->can('mail.personal.approve'),
        ]);
    }

    public function accounts(Request $request): JsonResponse
    {
        $user = $request->user();
        $palette = ['#00ab3f', '#1a3fd0', '#0b7a70', '#f5121d', '#6a3fd0', '#f8a500', '#be185d', '#334155'];
        $rows = $this->access->listed($user)->values()->map(fn (MailAccount $a, int $i) => [
            'id' => $a->public_id,
            'kind' => $a->kind,
            'provider' => $a->provider,
            'email' => $a->email,
            'title' => $a->boxTitle(),
            'colour' => $a->colour ?: $palette[$i % count($palette)],
            'status' => $a->status,
            'usable' => $a->isUsable(),
            'canSend' => $this->access->canSendFrom($user, $a),
            'expires' => $a->expires_on?->format('d-m-Y'),
            'people' => $a->kind === MailAccount::SHARED ? $a->members()->count() : 0,
        ])->values();

        $requests = $user->can('mail.personal.approve')
            ? MailAccount::query()->with('user:id,name')->where('kind', MailAccount::PERSONAL)->where('status', MailAccount::PENDING)
                ->where('user_id', '!=', $user->id)->latest()->get()
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
        $per = min(100, max(10, (int) $request->query('per', 50)));

        return $this->guarded(fn () => $this->server->list($box, $folder, $page, $per, (string) $request->query('q', '')) + ['page' => $page, 'per' => $per], $box);
    }

    public function read(Request $request, string $account): JsonResponse
    {
        $box = $this->open($request, $account);
        $folder = (string) $request->query('folder', 'INBOX');
        $uid = (int) $request->query('uid');
        $profile = app(MailSignatures::class)->profile($request->user());

        return $this->guarded(function () use ($box, $folder, $uid, $request, $profile) {
            $mail = $this->server->read($box, $folder, $uid);
            $fromEmail = (string) ($mail['from'][0]['email'] ?? '');
            $domain = substr((string) strrchr($box->email, '@'), 1);
            $trusted = $fromEmail !== '' && $profile->trusts($fromEmail);
            $messageId = (string) ($mail['messageId'] ?? '');

            $mail['external'] = $fromEmail !== '' && ! str_ends_with(mb_strtolower($fromEmail), '@'.mb_strtolower($domain));
            $mail['trusted'] = $trusted;
            $mail['body'] = self::safe($mail['html'] !== '' ? $mail['html'] : nl2br(e($mail['text'])), $trusted || $request->boolean('images'));
            $mail['linked'] = $messageId !== '' && MailLink::query()->where('mail_account_id', $box->id)->where('message_id', $messageId)->exists();
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
        $data = $request->validate(['folder' => 'required|string|max:255', 'uid' => 'required|integer', 'flag' => 'required|in:seen,flagged,later', 'on' => 'required|boolean']);

        return $this->guarded(function () use ($box, $data) {
            $this->server->flag($box, $data['folder'], (int) $data['uid'], $data['flag'], (bool) $data['on']);

            return ['ok' => true];
        }, $box);
    }

    public function move(Request $request, string $account, MailSends $sends): JsonResponse
    {
        $box = $this->open($request, $account);
        $data = $request->validate(['folder' => 'required|string|max:255', 'uid' => 'required|integer', 'to' => 'required|string|max:255',
            'subject' => 'nullable|string|max:255', 'action' => 'nullable|in:mail_moved,mail_deleted,mail_archived']);

        return $this->guarded(function () use ($box, $data, $sends) {
            $this->server->move($box, $data['folder'], (int) $data['uid'], $data['to']);
            $sends->recordMove($box, $data['action'] ?? 'mail_moved', (string) ($data['subject'] ?? ''), $data['to']);

            return ['ok' => true];
        }, $box);
    }

    /** ⭐ একসাথে অনেকগুলো — পড়া, না-পড়া, পরে দেখব, সংরক্ষণ, সরান, মুছুন (নমুনা v4 পর্দা ৩) */
    public function bulk(Request $request, string $account, MailSends $sends): JsonResponse
    {
        $box = $this->open($request, $account);
        $data = $request->validate([
            'folder' => 'required|string|max:255', 'uids' => 'required|array|min:1|max:100', 'uids.*' => 'integer',
            'do' => 'required|in:read,unread,later,archive,delete,move', 'to' => 'nullable|string|max:255',
            'subjects' => 'nullable|array', 'subjects.*' => 'nullable|string|max:255',
        ]);

        return $this->guarded(function () use ($box, $data, $sends) {
            $folders = in_array($data['do'], ['archive', 'delete'], true) ? collect($this->server->folders($box)) : collect();
            $to = match ($data['do']) {
                'archive' => (string) ($folders->firstWhere('role', 'archive')['path'] ?? ''),
                'delete' => (string) ($folders->firstWhere('role', 'trash')['path'] ?? ''),
                'move' => (string) ($data['to'] ?? ''),
                default => '',
            };
            $actions = ['archive' => 'mail_archived', 'delete' => 'mail_deleted', 'move' => 'mail_moved'];

            foreach (array_values($data['uids']) as $i => $uid) {
                if ($data['do'] === 'read' || $data['do'] === 'unread') {
                    $this->server->flag($box, $data['folder'], (int) $uid, 'seen', $data['do'] === 'read');
                } elseif ($data['do'] === 'later') {
                    $this->server->flag($box, $data['folder'], (int) $uid, 'later', true);
                } elseif ($to !== '') {
                    $this->server->move($box, $data['folder'], (int) $uid, $to);
                    $sends->recordMove($box, $actions[$data['do']], (string) ($data['subjects'][$i] ?? ''), $to);
                }
            }

            return ['ok' => true, 'missing' => isset($actions[$data['do']]) && $to === ''];
        }, $box);
    }

    /**
     * ⭐ লেখার পর্দার তথ্য — টেমপ্লেট (প্রাপকের আসল তথ্যসহ), সই, ABOS-এর কাগজ (নমুনা v4 পর্দা ৩খ)।
     */
    public function compose(Request $request, MailTemplates $templates, MailFields $fields, MailSignatures $signatures, MailPapers $papers): JsonResponse
    {
        $user = $request->user();
        $to = (string) $request->query('to', '');
        $box = $request->query('from') ? $this->open($request, (string) $request->query('from')) : null;
        $profile = $signatures->profile($user);
        $values = $fields->values($user, $to);
        $paper = null;

        if ($request->query('paper') && ($request->query('id') || $request->query('no'))) {
            $kind = (string) $request->query('paper');
            abort_unless(isset(MailPapers::KINDS[$kind]), 422, __('mail::message.paper_unknown'));
            $id = $request->query('id') ? (int) $request->query('id') : $papers->idOf($kind, (string) $request->query('no'));
            abort_if($id === null, 404, __('mail::message.paper_unknown'));
            $no = $papers->numberOf($kind, $id) ?: (string) $request->query('no', '');
            $paper = ['kind' => $kind, 'id' => $id, 'no' => $no, 'label' => __('mail::field.papers.'.$kind),
                'party' => (string) $request->query('party', 'customer')];
            $values['{বিল নম্বর}'] = $no;

            if ($kind === 'invoice' && ($inv = SalesInvoice::query()->find($id))) {
                $values['{বিলের টাকা}'] = MailFields::money((string) $inv->total);
            }
        }

        $company = (string) ($values['{কোম্পানি}'] ?? '');
        $list = MailTemplate::query()->seenBy($user)->where('active', true)->orderBy('category')->orderBy('name')->get()
            ->map(fn (MailTemplate $t) => ['id' => $t->public_id, 'name' => $t->name, 'category' => $t->category, 'plain' => $t->format === 'plain',
                'attach' => $t->attach_paper] + $templates->render($t, $values, $company))->values();

        return response()->json([
            'templates' => $list,
            'paper' => $paper,
            'papers' => collect(array_keys(MailPapers::KINDS))->map(fn ($k) => ['kind' => $k, 'label' => __('mail::field.papers.'.$k)])->values(),
            'signature' => [
                'new' => $signatures->preview($user, (int) ($profile->style_new ?: 1), $box?->email),
                'reply' => $signatures->preview($user, (int) ($profile->style_reply ?: 10), $box?->email),
            ],
        ]);
    }

    public function send(Request $request, string $account, MailSends $sends, MailSignatures $signatures, MailPapers $papers, MailTemplates $templates): JsonResponse
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
            'signature' => 'nullable|in:new,reply,none',
            'template' => 'nullable|string|max:40',
            'papers' => 'nullable|array|max:5', 'papers.*.kind' => 'required_with:papers|string|max:20', 'papers.*.id' => 'required_with:papers|integer',
            'papers.*.party' => 'nullable|string|max:20',
        ]);

        $user = $request->user();
        $files = array_map(fn ($f) => ['name' => $f->getClientOriginalName(), 'mime' => $f->getMimeType() ?: 'application/octet-stream', 'bytes' => (string) file_get_contents($f->getRealPath())],
            $request->file('files', []));

        // ⭐ ABOS থেকে সংযুক্ত — ছাপার পাতা যা দেয়, পাঠানো কর্মীর নিজের নামে ([[MailPapers]])
        $paper = null;
        try {
            foreach ((array) ($data['papers'] ?? []) as $p) {
                $pdf = $papers->pdf($user, (string) $p['kind'], (int) $p['id'], (string) ($p['party'] ?? 'customer'));
                $files[] = ['name' => $pdf['name'], 'mime' => $pdf['mime'], 'bytes' => $pdf['bytes']];
                $paper ??= ['type' => (string) $p['kind'], 'id' => (int) $p['id'], 'no' => $pdf['no']];
            }
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage() ?: __('mail::message.paper_no_pdf')], 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first() ?: __('mail::message.paper_no_pdf')], 422);
        }

        $html = self::safe($data['html'], true);
        $sig = $data['signature'] ?? 'new';

        if ($sig !== 'none') {
            $profile = $signatures->profile($user);
            $style = $sig === 'reply' ? (int) ($profile->style_reply ?: 10) : (int) ($profile->style_new ?: 1);
            $html .= $signatures->inline($user, $signatures->html($user, $style, $box->email), false);
        }

        $embeds = $signatures->embeds($user, $html);
        $fromName = $box->kind === MailAccount::SHARED
            ? trim(($box->sender_with_person ? $user->name.' · ' : '').($box->sender_name ?: $box->boxTitle()))
            : (string) $user->name;

        try {
            $id = $this->server->send($box, [
                'to' => $data['to'], 'cc' => $data['cc'] ?? [], 'bcc' => $data['bcc'] ?? [],
                'subject' => $data['subject'], 'html' => $html, 'inReplyTo' => $data['inReplyTo'] ?? null,
                'attachments' => $files, 'embeds' => $embeds, 'fromName' => $fromName,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => __('mail::message.send_failed', ['why' => mb_substr($e->getMessage(), 0, 160)])], 422);
        }

        $template = ! empty($data['template']) ? MailTemplate::query()->where('public_id', $data['template'])->first() : null;

        if ($template) {
            $templates->used($template);
        }

        $sends->record($box, $user, ['to' => $data['to'], 'cc' => $data['cc'] ?? [], 'subject' => $data['subject'], 'messageId' => $id,
            'paper' => $paper, 'template_id' => $template?->id, 'files' => count($files)]);

        return response()->json(['ok' => true, 'messageId' => $id, 'message' => __('mail::message.sent')]);
    }

    /**
     * ⭐ ডানের কার্ড — প্রেরকের ঠিকানা মিলিয়ে গ্রাহক বা সরবরাহকারী: বকেয়া, বাকির সীমা, আর কত দেওয়া যাবে, পয়েন্ট, শেষ বিল, খোলা বিল।
     * ⛔ `customer.view` ছাড়া গ্রাহক নয়, `supplier.view` ছাড়া সরবরাহকারী নয় — মেইল দেখা মানে খাতা দেখা নয়।
     */
    public function party(Request $request, MailFields $fields): JsonResponse
    {
        $email = mb_strtolower(trim((string) $request->query('email', '')));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['party' => null]);
        }

        $user = $request->user();

        if ($user->can('customer.view')) {
            $c = Customer::query()->whereRaw('LOWER(email) = ?', [$email])->first();

            if ($c) {
                $due = $this->dueOf($c);
                $bills = SalesInvoice::query()->where('customer_id', $c->id)->posted()->withCollected()
                    ->orderByDesc('trx_date')->limit(30)->get()
                    ->map(fn ($b) => ['no' => (string) $b->document_no, 'id' => $b->id, 'date' => $b->trx_date?->format('d-m'), 'due' => (string) $b->dueAmount()])
                    ->filter(fn ($b) => bccomp($b['due'], '0', 2) > 0)->take(5)->values()
                    ->map(fn ($b) => $b + ['url' => Route::has('sales.invoice.show') ? route('sales.invoice.show', $b['id']) : null]);
                $limit = (string) $c->effectiveCreditLimit();
                $f = $fields->forCustomer($c);

                return response()->json(['party' => [
                    'kind' => 'customer', 'name' => $c->name(), 'point' => (string) ($c->pointName() ?? ''), 'phone' => (string) ($c->phone ?? ''),
                    'due' => $due, 'limit' => $limit,
                    'left' => bccomp($limit, '0', 2) > 0 ? bcsub($limit, $due, 2) : null,
                    'points' => $f['{পয়েন্ট}'] ?? null,
                    'last' => $f['{শেষ বিল}'] ?? null,
                    'bills' => $bills,
                    'links' => [
                        'page' => route('customer.show', $c->id),
                        'receipt' => Route::has('accounts.voucher.create') ? route('accounts.voucher.create', ['type' => 'receipt']) : null,
                        'bill' => Route::has('sales.direct.create') ? route('sales.direct.create') : null,
                    ],
                    'ref' => ['type' => 'customer', 'id' => $c->id],
                ]]);
            }
        }

        if ($user->can('supplier.view')) {
            $s = Supplier::query()->whereRaw('LOWER(email) = ?', [$email])->first();

            if ($s) {
                return response()->json(['party' => [
                    'kind' => 'supplier', 'name' => $s->name(), 'point' => '', 'phone' => (string) ($s->phone ?? ''),
                    'due' => null, 'limit' => null, 'left' => null, 'points' => null, 'last' => null, 'bills' => [],
                    'links' => ['page' => Route::has('supplier.show') ? route('supplier.show', $s->id) : null, 'receipt' => null, 'bill' => null],
                    'ref' => ['type' => 'supplier', 'id' => $s->id],
                ]]);
            }
        }

        return response()->json(['party' => null]);
    }

    /**
     * ⭐ তালিকার চিহ্ন — অনেক প্রেরক একসাথে: গ্রাহক/সরবরাহকারী, পয়েন্টের নাম, বাকি (নমুনা v4 পর্দা ৩)।
     * ⛔ চাবি না থাকলে চিহ্ন নেই।
     */
    public function parties(Request $request): JsonResponse
    {
        $emails = collect((array) $request->input('emails', []))->map(fn ($e) => mb_strtolower(trim((string) $e)))
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))->unique()->take(100)->values();
        $user = $request->user();
        $out = [];

        if ($emails->isNotEmpty() && $user->can('customer.view')) {
            $customers = Customer::query()->whereIn(DB::raw('LOWER(email)'), $emails->all())->get();
            $dues = DB::table('ledger_entries')->where('party_type', Customer::drillSourceType())->whereIn('party_id', $customers->pluck('id'))
                ->groupBy('party_id')->selectRaw('party_id, COALESCE(SUM(debit - credit), 0) b')->pluck('b', 'party_id');

            foreach ($customers as $c) {
                $out[mb_strtolower((string) $c->email)] = ['kind' => 'customer', 'point' => (string) ($c->pointName() ?? ''), 'due' => (string) ($dues[$c->id] ?? '0')];
            }
        }

        if ($emails->isNotEmpty() && $user->can('supplier.view')) {
            foreach (Supplier::query()->whereIn(DB::raw('LOWER(email)'), $emails->all())->get() as $s) {
                $out[mb_strtolower((string) $s->email)] ??= ['kind' => 'supplier', 'point' => '', 'due' => null];
            }
        }

        return response()->json(['parties' => (object) $out]);
    }

    /** ⭐ "এই প্রেরকের ছবি সবসময় দেখান" — কর্মীর নিজের পছন্দ */
    public function trust(Request $request, MailSignatures $signatures): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:191', 'on' => 'required|boolean']);
        $profile = $signatures->profile($request->user());
        $list = collect((array) ($profile->trusted ?? []))->map(fn ($e) => mb_strtolower((string) $e))->reject(fn ($e) => $e === mb_strtolower($data['email']));

        if ($data['on']) {
            $list->push(mb_strtolower($data['email']));
        }

        $profile->fill(['trusted' => $list->unique()->take(500)->values()->all()])->save();

        return response()->json(['ok' => true]);
    }

    /** ⭐ "গ্রাহকের পাতায় রাখুন" — মেইলটা গ্রাহক বা সরবরাহকারীর সাথে বাঁধা (বিষয়, প্রেরক, সময় — লেখা নয়) */
    public function link(Request $request, string $account): JsonResponse
    {
        $box = $this->open($request, $account);
        $data = $request->validate(['messageId' => 'required|string|max:255', 'subject' => 'nullable|string|max:255', 'from' => 'nullable|email|max:191',
            'date' => 'nullable|date', 'type' => 'required|in:customer,supplier', 'id' => 'required|integer']);

        $ability = $data['type'] === 'customer' ? 'customer.view' : 'supplier.view';
        abort_unless($request->user()->can($ability), 403);

        $model = $data['type'] === 'customer' ? Customer::class : Supplier::class;
        $party = $model::query()->findOrFail((int) $data['id']);

        MailLink::query()->firstOrCreate(
            ['mail_account_id' => $box->id, 'message_id' => $data['messageId'], 'linkable_type' => $party->getMorphClass(), 'linkable_id' => $party->id],
            ['subject' => $data['subject'] ?? null, 'from_email' => $data['from'] ?? null, 'sent_at' => $data['date'] ?? null, 'linked_by' => $request->user()->id],
        );

        return response()->json(['ok' => true, 'message' => __('mail::message.linked')]);
    }

    public function addAccount(Request $request, MailAccounts $accounts): JsonResponse
    {
        $data = $request->validate([
            'kind' => 'required|in:own,shared,personal',
            'provider' => 'required|in:company,gmail,outlook,yahoo,imap',
            'email' => 'required|email|max:191',
            'secret' => 'required|string|max:500',
            'label' => 'nullable|string|max:80',
            'colour' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
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

    /** ⓘ ABOS-এর বারের না-পড়া সংখ্যা — নিজের আর দলের মেইলবক্সের ইনবক্স; ধীর সার্ভার হলে যা পাওয়া গেল */
    public function unread(Request $request): JsonResponse
    {
        $user = $request->user();
        $key = 'mail.unread.'.\App\Core\Support\CompanyContext::companyId().'.'.$user->id;

        // ⓘ 2 মিনিট জমা — প্রতিটা খোলা ট্যাব মেইল সার্ভারে আলাদা করে ঢোকে না
        $total = \Illuminate\Support\Facades\Cache::remember($key, 120, function () use ($user) {
            $total = 0;

            foreach ($this->access->listed($user)->filter(fn (MailAccount $a) => $a->isUsable())->take(6) as $box) {
                try {
                    $inbox = collect($this->server->folders($box))->firstWhere('role', 'inbox');
                    $total += (int) ($inbox['unread'] ?? 0);
                } catch (\Throwable) {
                    // ⓘ একটা মেইলবক্স না পেলে বাকিগুলো গোনা হয়
                }
            }

            return $total;
        });

        return response()->json(['unread' => (int) $total]);
    }

    // ── ভিতরের কাজ ───────────────────────────────────────────────────────

    private function open(Request $request, string $account): MailAccount
    {
        $box = MailAccount::query()->where('public_id', $account)->firstOrFail();
        $this->access->mustOpen($request->user(), $box);

        return $box;
    }

    private function dueOf(Customer $c): string
    {
        return (string) (DB::table('ledger_entries')->where('company_id', $c->company_id)
            ->where('party_type', Customer::drillSourceType())->where('party_id', $c->id)
            ->selectRaw('COALESCE(SUM(debit - credit), 0) b')->value('b') ?? '0');
    }

    /** ⓘ সার্ভারের ভুল পাতা ভাঙে না — কারণ লিখে রাখে, ব্যবহারকারী সাধারণ বার্তা পান */
    private function guarded(callable $work, MailAccount $box): JsonResponse
    {
        try {
            return response()->json($work());
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Validation\ValidationException $e) {
            throw $e;
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
    public static function safe(string $html, bool $images): string
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
