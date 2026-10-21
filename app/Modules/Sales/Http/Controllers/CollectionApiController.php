<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Services\DealerScope;
use App\Core\Support\AmountInWords;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Services\CollectionInput;
use App\Modules\Sales\Services\CollectionOpenItems;
use App\Modules\Sales\Services\CollectionPreview;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\CollectionLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ⭐ ফোনে "টাকা আদায়" — মালিক, ৬ অক্টোবর ২০২৬ (সমন্বয়কের মারফত: "অ্যাপে payment received … দরকার")।
 *
 * ⓘ কেবল পড়া: আদায়ের তালিকা — তারিখের পরিসর, গ্রাহক বা দোকান খুঁজে, পদ্ধতি (নগদ · ব্যাংক · MFS · চেক), মোট — আর একটার
 * বিস্তারিত। ⛔ লেখা নেই; আদায় লেখা ওয়েবের আর ফোনের নিজের পথে।
 *
 * ── ⛔ দেয়াল ───────────────────────────────────────────────────────────────
 *   · চাবি ওয়েবের আদায়ের তালিকার — `sales.collection.view`
 *   · শাখা — মডেলের নিজের ([[ScopedToUserBranch]]), তার উপরে মাথায় বাছা শাখা ([[ViewedBranch::narrow()]]), ওয়েবের তালিকার মতো
 *   · SR — নিজের ডিলারের আদায়ই ([[ScopedToUserDealers]], মডেলের নিজের; দেয়াল চালু থাকলে)
 *
 * ⓘ পদ্ধতি আলাদা কোনো ঘরে লেখা থাকে না: টাকা কোন খাতে এল (নগদ · ব্যাংক · MFS), আর কাগজের নাম চেক হলে চেক।
 */
class CollectionApiController extends Controller implements HasMiddleware
{
    public const METHODS = ['cash', 'bank', 'mfs', 'cheque'];

    private const PER_PAGE = 30;

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.collection.view', only: ['index', 'show']),
            // ⓘ আদায় লেখার প্রস্তুতি — কেবল অফিসের লোক: আদায়ের চাবি আর খাতায় টাকা তোলার চাবি ([[CollectionSync::officeMayCollect()]])
            new Middleware('can:sales.collection.create', only: ['setup']),
            new Middleware('can:accounts.voucher.create', only: ['setup']),
            // ⭐ টাকা নেওয়ার নকশা ১০০ — a4-এর চুক্তির তিন দরজা; খোলা বিল আর প্রিভিউ কেবল পড়ে, সংযুক্তি কাগজে যোগ করে
            new Middleware('can:sales.collection.create', only: ['openBills', 'preview', 'attachment']),
        ];
    }

    /**
     * `GET /collections/open-bills?customer=<public_id>` — গ্রাহকের খোলা বিল ও ক্রেডিট, পুরনো আগে (দফা ১, ৩; a4-এর চুক্তি)।
     *
     * ⓘ ওয়েবের একই উত্তর ([[CollectionOpenItems::for()]]): বকেয়া = খাতার বকেয়া, বাকির সীমা, সবচেয়ে পুরনো বাকির দিন।
     * ⛔ গ্রাহক কেবল ফোনে বাছা শাখার, আর SR হলে নিজের ডিলার — নাগালের বাইরে মানে ৪০৪, কিছুই ফাঁস নয় (দফা ১৮)।
     */
    public function openBills(Request $request, CollectionOpenItems $openItems): JsonResponse
    {
        $data = $request->validate(['customer' => ['required', 'string', 'max:64']]);

        $customer = Customer::query()->inViewedBranch()->with(Customer::PLACE)->where('public_id', $data['customer'])->first();

        abort_if($customer === null || ! app(DealerScope::class)->allowsCustomer((int) $customer->id), 404);

        return response()->json($openItems->for($customer));
    }

    /**
     * `POST /collections/preview` — সিঙ্কের আদায়-বদলের একই পাঠানো, আর খাতায় যা যাবে (দফা ১০)। ⛔ কিছুই লেখে না।
     *
     * ⓘ ওয়েবের পর্দার একই প্রিভিউ ([[CollectionPreview]] → সেবার সেই লাইন-বানানো যা পোস্ট খাতায় পাঠায়)।
     */
    public function preview(Request $request, CollectionPreview $preview): JsonResponse
    {
        $payload = $request->all();
        $in = CollectionInput::fromPhone($payload);

        abort_unless(app(DealerScope::class)->allowsCustomer($in->customerId), 404);

        return response()->json($preview->of($in));
    }

    /**
     * `POST /collections/{public_id}/attachment` — স্লিপ, অ্যাডভাইস বা স্ক্রিনশট: JPG, PNG বা PDF, ৫ MB (দফা ১৫)।
     *
     * ⓘ ওয়েবের একই ইঞ্জিন আর একই ধরনের তালিকা ([[AttachmentEngine::SLIP]]); কাগজটা এই মানুষের নাগালে না থাকলে ৪০৪।
     */
    public function attachment(Request $request, string $id): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,pdf']]);

        $collection = ViewedBranch::narrow(Collection::query(), 'sal_collections.branch_id')->where('public_id', $id)->firstOrFail();

        $paper = app(AttachmentEngine::class)->store($request->file('file'), 'sales', Collection::drillSourceType(), (int) $collection->id,
            maxBytes: AttachmentEngine::SLIP_MAX_BYTES, only: AttachmentEngine::SLIP);

        /*
         * ⭐ স্লিপ এল — ফোন "পোস্ট" চেয়েছিল, তবে এখন পোস্ট (দফা ১৫; a4-এর অ্যাপ a3a030302, ২১ অক্টোবর ২০২৬)।
         * ⓘ অ্যাপ আগে আদায় পাঠায় (`confirm: true`), স্লিপের ছবি পরে, বসার পরে। স্লিপ বাধ্যতামূলক কোম্পানিতে প্রথম চাপে পোস্ট
         * আটকায় (তখনো স্লিপ নেই), আদায় খসড়া থাকে — আর ফোনে খসড়া পোস্টের আর কোনো বোতাম নেই, তাই চিরকাল খসড়া। ⓘ এখন:
         * যিনি পাঠালেন তিনিই স্লিপ দিলে, আর তাঁর সিঙ্কের বদলটা সত্যিই পোস্ট চেয়েছিল, পোস্টের একই দরজা আবার
         * ([[CollectionService::confirm()]]) — বাকি সব পাহারা (মাস বন্ধ, ছাড়ের সই, রেফারেন্স) আগের মতোই থামায়, তখন খসড়াই থাকে।
         */
        $collection = $collection->fresh();

        if ($collection->status === DocumentStatus::DRAFT
            && (int) $collection->created_by === (int) auth()->id()
            && $this->phoneAskedToPost($collection)) {
            try {
                $collection = app(\App\Modules\Sales\Services\CollectionService::class)->confirm($collection->fresh(['lines']));
            } catch (\Illuminate\Validation\ValidationException|\App\Core\Engines\Posting\PostingException) {
                // ⓘ অন্য কারণে আটকাল — খসড়া থাকল, সিঙ্কের পথের একই আচরণ ([[CollectionSync::apply()]])
            }
        }

        return response()->json([
            'id' => (string) ($paper->public_id ?? $paper->id),
            'name' => (string) $paper->original_name,
            // ⓘ আদায়ের এখনকার অবস্থা — সিঙ্কের উত্তরের একই ঘর ([[answer()]])
            'state' => self::answer($collection->fresh())['state'],
        ], 201);
    }

    /** ফোনের সিঙ্কের যে বদল এই আদায়টা বানাল, সেটা "পোস্ট" (`confirm: true`) চেয়েছিল কি না */
    private function phoneAskedToPost(Collection $collection): bool
    {
        $payload = \App\Models\SyncChange::query()
            ->where('company_id', (int) $collection->company_id)
            ->where('entity_type', \App\Modules\Sales\Sync\CollectionSync::entityType())
            ->where('applied_entity_id', (string) $collection->public_id)
            ->where('status', \App\Models\SyncChange::APPLIED)
            ->value('payload_json');

        $decoded = is_string($payload) ? json_decode($payload, true) : null;

        return is_array($decoded) && ($decoded['confirm'] ?? false) === true;
    }

    /**
     * ⭐ আদায়ের উত্তর — সিঙ্কের উত্তর আর ফোনের রসিদ একই কথা বলে (a4-এর চুক্তি): নম্বর, অবস্থা (paper_states-এর কোড), টাকা কথায়,
     * রসিদের PDF, কোন কাগজে কত বসল আর কত অপ্রয়োগকৃত।
     *
     * @return array{no: string, state: string, amount_words: string, receipt_pdf: string, applied: list<array{kind: string, no: string, amount: string}>, unapplied: string}
     */
    public static function answer(Collection $c): array
    {
        $c->loadMissing(['lines.invoice', 'applications']);

        /*
         * ⭐ প্রিভিউর একই তালিকা ([[CollectionService::preview()]]-এর `applied`) — বিলের সারিতে বিলে বসা পুরো অঙ্ক (টাকার অংশ +
         * ক্রেডিটের অংশ), তারপর ডেবিট নোট, তারপর ক্রেডিট ঋণাত্মক; যোগফল = "বিলে প্রয়োগ" (নকশার সারাংশ)।
         * ⚠️ আগে বিলে কেবল টাকার অংশ আর পাশে ক্রেডিট ঋণাত্মক — ১,২০০-র বিলে ৫০০ ক্রেডিট দিলে ৭০০ আর −৫০০, যোগ ২০০; অথচ
         * পাওয়া ৭০০ (a6, ২১ অক্টোবর ২০২৬)।
         */
        $bills = [];
        foreach ($c->lines as $line) {
            $id = (int) $line->sales_invoice_id;
            $bills[$id] = bcadd($bills[$id] ?? '0', (string) $line->amount, 4);
        }

        $notes = [];
        $credits = [];
        foreach ($c->applications as $app) {
            if (in_array($app->kind, CollectionInput::CREDIT_KINDS, true)) {
                $credits[] = $app;

                if ($app->target_invoice_id !== null) {
                    $id = (int) $app->target_invoice_id;
                    $bills[$id] = bcadd($bills[$id] ?? '0', (string) $app->amount, 4);
                }
            } else {
                $notes[] = $app;
            }
        }

        $billNo = \App\Modules\Sales\Models\SalesInvoice::acrossBranches()->whereKey(array_keys($bills))->pluck('document_no', 'id');
        $sourceNo = fn ($app) => (string) ($app->kind === 'credit'
            ? Collection::acrossBranches()->whereKey($app->source_id)->value('document_no')
            : \App\Modules\Accounts\Models\Note::acrossBranches()->whereKey($app->source_id)->value('document_no'));

        $applied = [];
        foreach ($bills as $id => $amount) {
            $applied[] = ['kind' => 'bill', 'no' => (string) ($billNo[$id] ?? ''), 'amount' => Money::round($amount, 4)];
        }
        foreach ($notes as $app) {
            $applied[] = ['kind' => (string) $app->kind, 'no' => $sourceNo($app), 'amount' => Money::round((string) $app->amount, 4)];
        }
        foreach ($credits as $app) {
            $applied[] = ['kind' => (string) $app->kind, 'no' => $sourceNo($app), 'amount' => Money::round('-'.$app->amount, 4)];
        }

        $state = match (true) {
            in_array((string) $c->status, DocumentStatus::POSTED, true) => 'posted',
            (string) $c->status === DocumentStatus::CANCELLED => 'cancelled',
            Approval::query()->whereIn('approvable_type', [Collection::class, $c->getMorphClass()])
                ->where('approvable_id', $c->id)->pending()->exists() => 'waiting',
            default => 'draft',
        };

        $unapplied = $c->unapplied !== null
            ? (string) $c->unapplied
            : bcsub((string) $c->amount, (string) ($c->lines->sum('amount') ?: '0'), 4);

        return [
            'no' => (string) $c->document_no,
            'state' => $state,
            /*
             * ⭐ কথায় টাকা যিনি চাইছেন তাঁর ভাষায় (a4-এর অ্যাপ 5802d83c9, ২২ অক্টোবর ২০২৬) — আগে সবসময় বাংলা, ইংরেজির মানুষও বাংলা পেতেন।
             * ⓘ ফোনের দরজায় ভাষা আগেই বসানো: [[ResolveCompanyContext]] চলতি মানুষের `users.locale` থেকে app()->setLocale() করে
             * (টোকেনের পরে); `Accept-Language` কেবল সই-না-করা অনুরোধের — এখানে সবসময় কেউ সই করা। ⚠️ a4/api-locale-এর
             * `UserLocale` এলে ভাষার নিয়ম সেখানে; এখানে কেবল app()->getLocale() পড়া, তাই কিছু সরাতে হবে না।
             */
            'amount_words' => AmountInWords::of(bcadd((string) $c->amount, '0', 4), app()->getLocale()),
            'receipt_pdf' => route('sales.print.receipt', $c->id),
            'applied' => $applied,
            'unapplied' => Money::round($unapplied, 4),
        ];
    }

    /**
     * `GET /collections/setup` — ফোনের আদায়-ফর্মের টাকার খাত (মালিক, ৭ অক্টোবর ২০২৬: "অ্যাপে পেমেন্ট অপশন চালু করো")।
     *
     * ⓘ ওয়েবের আদায়-ফর্মের হুবহু তালিকা ([[CollectionController::formData()]]): নগদ, ব্যাংক আর MFS-এর পোস্টযোগ্য খাত,
     * অন্য শাখার টিল বাদ। প্রতিটার ধরন (`cash` | `bank` | `mfs`) — মা-খাত দেখে — যাতে ফোন "মাধ্যম" বলতে পারে।
     * ⓘ লেখা নিজে সিঙ্কে ([[CollectionSync::apply()]]) — নেট ছাড়াও জমা থাকে, আর একই আদায় দুবার বসে না।
     */
    public function setup(): JsonResponse
    {
        // ⓘ ধরন খাতের নিজের ঘর (`money_kind`) থেকে; না থাকলে মা-খাতের কোড দেখে
        $kindOf = fn (Account $a): string => match (true) {
            in_array($a->money_kind, [Account::CASH, Account::BANK, Account::MFS], true) => (string) $a->money_kind,
            ($a->parent?->code ?? $a->code) === StandardChart::BANK => Account::BANK,
            ($a->parent?->code ?? $a->code) === StandardChart::MOBILE_MONEY => Account::MFS,
            default => Account::CASH,
        };

        // ⓘ টাকার মা-খাতের (নগদ ১১০১ · ব্যাংক ১১০২ · MFS ১১০৫) সন্তান-খাত, কেবল পোস্টযোগ্য — মা-খাতগুলো নিজেরাই দল, তাই মা
        // খোঁজায় postable() নয় ([[TheMoneyParentsAreGroupsSoPostableFindsNoneTest]]); অন্য শাখার টিল বাদ, ওয়েবের ফর্মের মতো
        $accounts = Account::query()->notAnotherBranchsTill()
            ->whereIn('parent_id', Account::query()->whereIn('code', StandardChart::MONEY_PARENTS)->select('id'))
            ->postable()
            ->with('parent:id,code')->orderBy('code')->get();

        // ⭐ যিনি লিখছেন তাঁর নিজের হাতের নগদ — ফোনে আগে থেকে বাছা (a4-এর চুক্তি, ২১ অক্টোবর ২০২৬; ওয়েবের পর্দার একই ক্রম)
        $mine = \App\Modules\Accounts\Models\CashTill::query()->heldBy((int) auth()->id())->pluck('account_id')
            ->map(fn ($id) => (int) $id)->all();

        return response()->json([
            'today' => now()->toDateString(),
            'accounts' => $accounts->map(fn (Account $a): array => [
                'id' => (string) $a->public_id,
                'code' => (string) $a->code,
                'name' => $a->name(),
                'kind' => $kindOf($a),
                'mine' => in_array((int) $a->id, $mine, true),
            ])->values(),
            // ⭐ ব্যাংক ট্রান্সফারের মোড — পাঠানোর `transfer_mode_id` এখান থেকে (cb-র পদ্ধতির ব্লকের একই তালিকা)
            'transfer_modes' => \App\Modules\MasterData\Models\TransferMode::query()->active()->orderBy('code')->get()
                ->map(fn ($m): array => ['id' => (string) $m->public_id, 'code' => (string) $m->code, 'name' => $m->name()])->values(),
        ]);
    }

    /** `GET /collections?from=&to=&q=&method=&page=` */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
            'method' => ['nullable', 'in:'.implode(',', self::METHODS)],
        ]);

        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();

        $query = ViewedBranch::narrow(Collection::query(), 'sal_collections.branch_id')
            ->whereIn('sal_collections.status', DocumentStatus::POSTED)
            ->whereBetween('sal_collections.trx_date', [$from, $to])
            ->search($data['q'] ?? null)
            ->tap(fn (Builder $q) => self::method($q, $data['method'] ?? null));

        $total = (string) (clone $query)->sum('sal_collections.amount');
        $page = (clone $query)->with(['customer.location.parent', 'customer.branch', 'account'])
            ->orderByDesc('sal_collections.trx_date')->orderByDesc('sal_collections.id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'from' => $from,
            'to' => $to,
            'count' => $page->total(),
            'total' => Money::round($total, 4),
            'rows' => collect($page->items())->map(fn (Collection $c) => $this->row($c))->values(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    /** `GET /collections/{public_id}` — একটা আদায়, কোন বিলে কত বসল */
    public function show(string $id): JsonResponse
    {
        $collection = ViewedBranch::narrow(Collection::query(), 'sal_collections.branch_id')
            ->where('public_id', $id)->with(['customer.location.parent', 'customer.branch', 'account', 'lines.invoice', 'creator'])->firstOrFail();

        return response()->json([
            ...$this->row($collection),
            // ⭐ ফোনের রসিদ — পাকা, নাকি খসড়া (অনুমোদন বা নিশ্চিতের অপেক্ষায়) — ৭ অক্টোবর ২০২৬
            'status' => (string) $collection->status,
            'instrument' => (string) ($collection->instrument ?? ''),
            'instrument_no' => (string) ($collection->instrument_no ?? ''),
            'instrument_date' => $collection->instrument_date?->toDateString(),
            'narration' => (string) ($collection->narration ?? ''),
            'by' => (string) ($collection->creator?->name ?? ''),
            'lines' => $collection->lines->map(fn (CollectionLine $l) => [
                'invoice' => (string) ($l->invoice?->document_no ?? ''),
                'amount' => Money::round((string) $l->amount, 4),
            ])->values(),
            // ⭐ নকশা ১০০ — গ্রাহক যা কেটে রাখলেন, ছাড়/রাউন্ডিং, আর সিঙ্কের উত্তরের একই ঘর ([[answer()]])
            'vds' => Money::round((string) ($collection->vds ?? '0'), 4),
            'ait' => Money::round((string) ($collection->ait ?? '0'), 4),
            'difference' => filled($collection->difference_kind)
                ? ['kind' => (string) $collection->difference_kind, 'amount' => Money::round((string) $collection->difference_amount, 4)] : null,
            ...self::answer($collection),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Collection $c): array
    {
        return [
            'id' => (string) $c->public_id,
            'no' => (string) $c->document_no,
            'date' => $c->trx_date?->toDateString(),
            'customer' => (string) ($c->customer?->name() ?? ''),
            // ⭐ পয়েন্ট — মালিক, ৭ অক্টোবর ২০২৬: "app e sob jaygay customer er pase obosoi point dibe" ([[Customer::pointName()]])
            'customer_point' => $c->customer?->pointName(),
            // ⭐ শাখা — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম): "গ্রাহকের নামের পাশে পয়েন্ট দিতে হবে অবশ্যই, সব জায়গায়, আর শাখার নামও দিতে হবে" ([[Customer::branchName()]]); ⓘ পুরনো অ্যাপ বাড়তি ঘর উপেক্ষা করে
            'customer_branch' => $c->customer?->branchName(),
            'account' => (string) ($c->account?->name() ?? ''),
            'method' => self::methodOf($c),
            'amount' => Money::round((string) $c->amount, 4),
        ];
    }

    /** পদ্ধতি — চেক কাগজের নামে, নাহলে টাকা যে খাতে এল তার ধরন */
    public static function methodOf(Collection $c): string
    {
        if (self::isCheque((string) $c->instrument)) {
            return 'cheque';
        }

        return match ($c->account?->money_kind) {
            Account::CASH => 'cash',
            Account::BANK => 'bank',
            Account::MFS => 'mfs',
            default => 'other',
        };
    }

    private static function isCheque(string $instrument): bool
    {
        $word = mb_strtolower(trim($instrument));

        return $word !== '' && (str_contains($word, 'cheque') || str_contains($word, 'check') || str_contains($word, 'চেক'));
    }

    private static function method(Builder $query, ?string $method): void
    {
        $cheque = fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('sal_collections.instrument', 'like', '%cheque%')
            ->orWhere('sal_collections.instrument', 'like', '%check%')
            ->orWhere('sal_collections.instrument', 'like', '%চেক%'));

        match ($method) {
            'cheque' => $cheque($query),
            // ⚠️ কাগজের নাম ফাঁকা (NULL) সারি "চেক নয়" — NOT LIKE-এ NULL পড়ে যেত, আর নগদের আদায় হারাত
            'cash', 'bank', 'mfs' => $query
                ->where(fn (Builder $w) => $w->whereNull('sal_collections.instrument')->orWhereNot(fn (Builder $q) => $cheque($q)))
                ->whereHas('account', fn (Builder $a) => $a->where('money_kind', match ($method) {
                    'cash' => Account::CASH, 'bank' => Account::BANK, default => Account::MFS,
                })),
            default => null,
        };
    }
}
