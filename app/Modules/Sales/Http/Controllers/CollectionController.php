<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\FiltersByDate;
use App\Core\Concerns\GrandTotals;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\DocumentStatus;
use App\Core\Support\ProcessBand;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Http\Requests\CollectionRequest;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Overview\ConfirmOverview;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Modules\MasterData\Models\TransferMode;
use App\Modules\Sales\Services\CollectionOpenItems;
use App\Modules\Sales\Services\CollectionPreview;
use App\Modules\Sales\Services\CollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * আদায় — পর্দা।
 */
class CollectionController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use GrandTotals;
    use SortsLists;

    public function __construct(
        private readonly CollectionService $service,
        private readonly MenuBuilder $menu,
        private readonly CollectionOpenItems $openItems,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(Collection::class, 'collection'),
            new Middleware('can:sales.collection.create', only: ['confirm', 'openItems', 'preview', 'overviewNew']),
            new Middleware('can:sales.collection.cancel', only: ['cancel', 'bounceCheque']),
        ];
    }

    public function index(Request $request): View
    {
        $query = Collection::query()
            ->search($request->query('q'))
            ->with(['customer.location.parent', 'customer.branch', 'account'])
            // বাতিলগুলো লুকানো, মোছা নয় (নিয়ম ৫)
            ->when(! $request->boolean('cancelled'),
                fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED));

        // ধাপের পটির হিসাব অবস্থার ছাঁকনির আগে — কারণটা
        // [[ProcessBand::forStatuses()]]-এ লেখা
        $bandBase = clone $query;

        $stage = (string) $request->query('stage', '');

        if (in_array($stage, DocumentStatus::ALL, true)) {
            $query->where('status', $stage);
        } else {
            $stage = '';
        }

        $dates = $this->applyDateRange($query, $request);

        $sort = $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'largest' => fn ($q) => $q->orderByDesc('amount'),
            'customer' => fn ($q) => $q->orderBy('customer_id')->orderByDesc('trx_date'),
        ]);

        /*
         * তালিকার যোগফল — **গোটা ছাঁকনির**, এই পাতার নয়।
         *
         * ⓘ `clone` লাগে কারণ `paginate()` কোয়েরিটা খেয়ে ফেলে; আর
         * `reorder()` লাগে কারণ যোগফলে ক্রমের কোনো মানে নেই।
         *
         * ⚠️ দেখানো হবে কি না সেটা রূপ ঠিক করে ([[Ui::listFoot]]) —
         * কন্ট্রোলার জানে না কে কোন রূপে বসে আছেন, জানার দরকারও নেই।
         */
        $totalled = (clone $query)->reorder();

        return view('sales::collection.index', [
            'menu' => $this->menu->forUser($request->user()),
            'grand' => $this->grandTotals($query, ['amount' => 't.amount']),
            'collections' => $query->paginate(50)->withQueryString(),
            'totals' => [
                'rows' => (clone $totalled)->count(),
                'money' => (clone $totalled)->sum('amount'),
            ],
            'q' => $request->query('q'),
            'dates' => $dates,
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
            'showCancelled' => $request->boolean('cancelled'),
            'stage' => $stage,
            'processBand' => ProcessBand::forStatuses(
                $bandBase,
                [
                    ['status' => DocumentStatus::DRAFT, 'label' => __('core.status.draft')],
                    ['status' => DocumentStatus::CONFIRMED, 'label' => __('core.status.confirmed')],
                    ['status' => DocumentStatus::CLOSED, 'label' => __('core.status.closed')],
                ],
                'sales.collection.index',
                $request->except(['stage', 'page']),
                $stage !== '' ? $stage : null,
                /*
                 * আদায়ের টাকার কলামটা `amount`, `total` নয়।
                 *
                 * ── কী ভাঙল, ২৯ আগস্ট ২০২৬ ───────────────────────────
                 * ডিফল্ট `total` ধরে নেওয়ায় লাইভে `/sales/collections`
                 * ৫০০ দিচ্ছিল — "Unknown column 'total'"। বাকি তিনটা
                 * কাগজে `total` আছে বলে ওখানে ধরা পড়েনি।
                 *
                 * সুইট এটা ধরেছিল ([[ModuleMenuTest]] — "মেনুতে দেখা
                 * যাচ্ছে, ক্লিক করলে ভাঙে"), কিন্তু আমি সুইট চালানোর
                 * **আগেই** ডিপ্লয় করে ফেলেছিলাম। পাহারাটা কাজ করেছে;
                 * আমি ওটাকে কাজ করার সুযোগ দিইনি।
                 */
                'amount',
            ),
        ]);
    }

    public function create(Request $request): View
    {
        $invoice = $this->chosenInvoice($request);
        $customer = $invoice?->customer ?? $this->chosenCustomer($request->query('customer'));

        return view('sales::collection.form', [
            'menu' => $this->menu->forUser($request->user()),
            'collection' => new Collection(['trx_date' => now()->toDateString(), 'customer_id' => $customer?->id]),
            'invoice' => $invoice,
            'open' => $customer === null ? null : $this->openItems->for($customer),
            ...$this->formData(),
        ]);
    }

    /**
     * ⭐ টাকা নেওয়া — খসড়া রাখুন, নয়তো পোস্ট (নকশা ১০০-এর নিচের পট্টি; ২১ অক্টোবর ২০২৬)।
     *
     * ⓘ "পোস্ট" (`post=1`) একই চাপে খসড়া লেখে আর খাতায় তোলে — নিশ্চিতের আগে সারাংশের পপ-আপ দেখে ([[overviewNew()]])।
     * ⓘ পোস্ট আটকালে (ছাড়ে মালিকের সই, মাস বন্ধ, রেফারেন্স ছাড়া) কাগজ খসড়া হয়ে থাকে, পাতায় কারণ লেখা — টাইপ করা কিছু হারায় না।
     */
    public function store(CollectionRequest $request): RedirectResponse
    {
        $document = $this->service->createFrom($request->collectionInput());

        $this->keepSlip($request, $document);

        if ($request->input('post') === '1') {
            return $this->post($document);
        }

        return redirect()
            ->route('sales.collection.show', $document)
            ->with('saved', __('sales::message.collection_created'));
    }

    /** ⭐ গ্রাহকের খোলা বিল ও ক্রেডিট — দফা ১, ৩ ([[CollectionOpenItems]]) */
    public function openItems(Request $request): JsonResponse
    {
        $customer = $this->chosenCustomer($request->query('customer'));

        abort_if($customer === null, 404);

        return response()->json($this->openItems->for($customer));
    }

    /** ⭐ খাতায় যা যাবে — দফা ১০; কিছুই লেখে না ([[CollectionPreview]]) */
    public function preview(CollectionRequest $request, CollectionPreview $preview): JsonResponse
    {
        return response()->json($preview->of($request->collectionInput()));
    }

    /**
     * ⭐ নতুন আদায়ের নিশ্চিত-সারাংশ — পপ-আপের ভিতর ([[confirm-overview.js]]), খাতার লাইনসহ। কাগজ তখনো লেখা হয়নি, তাই ফর্মের
     * ঘর থেকে; প্রিভিউর একই হিসাব।
     */
    public function overviewNew(CollectionRequest $request, CollectionPreview $preview): View
    {
        $in = $request->collectionInput();
        $facts = $preview->of($in);
        $customer = Customer::query()->with(Customer::PLACE)->find($in->customerId);

        $o = ConfirmOverview::titled(__('sales::collection_form.overview_title'))
            ->head(__('sales::field.customer'), $customer?->nameWithPoint())
            ->head(__('sales::field.date'), DateFormat::format($in->trxDate));

        foreach ($facts['lines'] as $line) {
            $o->line($line['account'], [$line['branch']], bccomp($line['dr'], '0', 4) > 0
                ? __('sales::collection_form.dr').' '.Money::format($line['dr'])
                : __('sales::collection_form.cr').' '.Money::format($line['cr']));
        }

        $o->total(__('sales::collection_form.total'), Money::format($facts['total_dr']), strong: true);

        if (bccomp($facts['unapplied'], '0', 4) > 0) {
            $o->money(__('sales::collection_form.unapplied'), Money::format($facts['unapplied']));
        }

        foreach ($facts['stops'] as $message) {
            $o->note($message, 'stop');
        }

        if ($facts['needs_signature']) {
            $o->note(__('sales::collection_form.owner_signs'), 'warn');
        }

        return view('ui.confirm-overview-body', ['overview' => $o->toArray()]);
    }

    public function show(Request $request, Collection $collection): View
    {
        $collection->load(['lines.invoice', 'customer', 'account', 'creator']);

        return view('sales::collection.show', [
            'menu' => $this->menu->forUser($request->user()),
            'collection' => $collection,
        ]);
    }

    public function edit(Request $request, Collection $collection): View
    {
        $collection->load(['lines.invoice', 'applications', 'customer']);

        return view('sales::collection.form', [
            'menu' => $this->menu->forUser($request->user()),
            'collection' => $collection,
            'invoice' => null,
            'open' => $collection->customer === null ? null : $this->openItems->for($collection->customer),
            ...$this->formData(),
        ]);
    }

    public function update(CollectionRequest $request, Collection $collection): RedirectResponse
    {
        $this->service->updateFrom($collection, $request->collectionInput());

        $this->keepSlip($request, $collection);

        if ($request->input('post') === '1') {
            return $this->post($collection->fresh());
        }

        return redirect()
            ->route('sales.collection.show', $collection)
            ->with('saved', __('sales::message.collection_updated'));
    }

    public function confirm(Collection $collection): RedirectResponse
    {
        $this->service->confirm($collection);

        return redirect()
            ->route('sales.collection.show', $collection)
            ->with('saved', __('sales::message.collection_confirmed'));
    }

    /** খসড়া লেখা হয়ে গেছে — এবার খাতায়; আটকালে খসড়ার পাতায় কারণসহ (টাইপ করা কিছু হারায় না) */
    private function post(Collection $document): RedirectResponse
    {
        try {
            $this->service->confirm($document);
        } catch (ValidationException $stopped) {
            return redirect()
                ->route('sales.collection.show', $document)
                ->withErrors($stopped->errors())
                ->with('saved', __('sales::message.collection_created'));
        }

        return redirect()
            ->route('sales.collection.show', $document)
            ->with('saved', __('sales::message.collection_confirmed'));
    }

    /** ⭐ দফা ১৫ — স্লিপ/অ্যাডভাইস/স্ক্রিনশট, কাগজের সাথে ([[AttachmentEngine]], স্লিপের ধরন, ৫ MB) */
    private function keepSlip(Request $request, Collection $document): void
    {
        $file = $request->file('slip');

        if (! $file instanceof UploadedFile) {
            return;
        }

        app(AttachmentEngine::class)->store($file, 'sales', Collection::drillSourceType(), (int) $document->id,
            maxBytes: AttachmentEngine::SLIP_MAX_BYTES, only: AttachmentEngine::SLIP);
    }

    /** গ্রাহক — এই মানুষের নাগালে থাকলে (শাখা আর ডিলারের দেয়াল মডেলের নিজের, তার উপরে ডিলারের নিয়ম) */
    private function chosenCustomer(mixed $id): ?Customer
    {
        if (blank($id) || ! is_scalar($id)) {
            return null;
        }

        $id = (string) $id;
        $customer = Customer::query()->with(Customer::PLACE)
            ->when(ctype_digit($id), fn ($q) => $q->whereKey((int) $id), fn ($q) => $q->where('public_id', $id))
            ->first();

        if ($customer !== null && ! app(\App\Core\Services\DealerScope::class)->allowsCustomer((int) $customer->id)) {
            return null;
        }

        return $customer;
    }

    public function cancel(Request $request, Collection $collection): RedirectResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        /*
         * ⛔ পাকা আদায় উল্টাতে দ্বিতীয় জন — দফা ১২ (মালিকের নকশা ১০০, ২১ অক্টোবর ২০২৬; SOX-এর maker-checker)। যিনি বানালেন
         * তিনি নিজে উল্টাতে পারেন না, কারণ লিখে আরেকজন উল্টান; উল্টো দাখিলা বসে উল্টানোর দিনে ([[CollectionService::cancel()]])।
         * ⓘ অনুমোদনের ইঞ্জিনের একই নিয়ম ([[ApprovalEngine::canDecide()]]): মালিক (super_admin) নিজেরটাও পারেন। খসড়া বাতিলে
         * দ্বিতীয় জন লাগে না — খাতায় কিছু ওঠেনি। চেক বাউন্সের পথ আলাদা দরজা, ব্যাংকের খবর ([[bounceCheque()]])।
         */
        $user = $request->user();

        if (in_array((string) $collection->status, DocumentStatus::POSTED, true)
            && (int) $collection->created_by === (int) $user->id
            && ! $user->roles->contains('name', \App\Core\Services\PermissionSyncer::SUPER_ADMIN_ROLE)) {
            throw ValidationException::withMessages(['reason' => __('sales::collection_rules.second_person_cancels')]);
        }

        $this->service->cancel($collection, $reason);

        return redirect()
            ->route('sales.collection.show', $collection)
            ->with('saved', __('sales::message.collection_cancelled'));
    }

    /**
     * আদায়ের কাগজে নেওয়া একটা চেক ফেরত এসেছে — চেকের খাতা থেকে।
     *
     * ── কেন এটা Sales-এ, চেকের খাতা তো Accounts ──────────────────────────
     * চেকের খাতা (accounts.cheque.*) Accounts-এর, কিন্তু ফেরতের কাজটা
     * আদায়ের কাগজ বাতিল করা — সেটা Sales। Accounts নিচের স্তর, সে Sales-কে
     * চেনে না, তাই চেকের খাতার bounce-বোতাম collection-চেকে **এই দরজায়**
     * পোস্ট করে (view রুট-নাম চেনে, ক্লাস নয় — স্তর অক্ষত)।
     *
     * ── কেন `sales.collection.cancel` পারমিশন ────────────────────────────
     * bounce ব্যাংকের দেওয়া তথ্য, cancel একটা সিদ্ধান্ত — শুনতে আলাদা।
     * কিন্তু ফলটা হুবহু এক: আদায় বাতিল, গ্রাহক আবার দেনাদার। একই ফল মানে
     * একই কর্তৃত্ব, তাই নতুন পারমিশন নয়।
     */
    public function bounceCheque(Request $request, Cheque $cheque): RedirectResponse
    {
        $reason = $request->validate([
            'bounce_reason' => ['required', 'string', 'min:3', 'max:255'],
        ])['bounce_reason'];

        $this->service->bounceReceivedCheque($cheque, $reason);

        return back()->with('saved', __('sales::message.cheque_bounced_via_receipt', ['no' => $cheque->cheque_no]));
    }

    /** বিল ধরে খোলা হলে ওই বিলের বকেয়াটাই আগে থেকে বসে। */
    private function chosenInvoice(Request $request): ?SalesInvoice
    {
        $id = $request->integer('sales_invoice_id');

        if ($id <= 0) {
            return null;
        }

        return SalesInvoice::query()
            ->where('status', DocumentStatus::CONFIRMED)
            ->with('customer')
            ->find($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        $mine = \App\Modules\Accounts\Models\CashTill::query()->heldBy((int) auth()->id())->pluck('account_id')
            ->map(fn ($id) => (int) $id)->all();

        return [
            // ⭐ জের-থাকা নিষ্ক্রিয় গ্রাহকও — বকেয়া রেখে বন্ধ দোকানের টাকা আদায়ের পথ ([[PickableWhileOwing]], ১৩ অক্টোবর ২০২৬)
            // ⭐ নামের পাশে পয়েন্ট আর শাখা — মালিক, ১৯ অক্টোবর ২০২৬; একবারে, সারি ধরে নয় ([[Customer::PLACE]])
            'customers' => Customer::query()->inViewedBranch()->activeOrOwing()->with(Customer::PLACE)->orderBy('name_en')->get(),
            // ⭐ অন্য শাখার টিলের খাত বাদ (৩০ সেপ্টেম্বর ২০২৬) — [[Account::scopeNotAnotherBranchsTill()]]
            // ⚠️ ভেতরের OR বাইরে না ছড়াতে পুরনো প্রশ্নটা উপ-প্রশ্ন হয়ে গেছে
            'accounts' => Account::query()->notAnotherBranchsTill()->whereIn('id', Account::query()->postable()
                ->whereIn('code', StandardChart::MONEY_PARENTS)
                ->orWhereIn('parent_id', Account::query()
                    ->whereIn('code', StandardChart::MONEY_PARENTS)->select('id'))
                ->select('id'))
                ->orderBy('code')->get()
                // ⭐ নিজের হাতের নগদ আগে — যিনি টাকা নিচ্ছেন তাঁর টিল (a5-র চুক্তি; [[CashTill::mayUse()]]), তারপর কোড ধরে
                ->sortBy(fn (Account $a) => [in_array((int) $a->id, $mine, true) ? 0 : 1, (string) $a->code])
                ->values(),
            // ⭐ ব্যাংক ট্রান্সফারের মোড — BEFTN · NPSB · RTGS (cb-র x-money.method)
            'transferModes' => TransferMode::query()->active()->orderBy('code')->get()
                ->mapWithKeys(fn (TransferMode $m) => [$m->id => $m->name()])->all(),
            // ⓘ এই কোম্পানিতে আদায়ে সই চালু কি না — নিয়ন্ত্রণের ঘরের প্রথম লাইন
            'collectionSigned' => \App\Models\ApprovalFlow::query()->where('module', 'sales')->where('action', 'collection')
                ->where('is_active', true)->exists(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function sortLabels(): array
    {
        return [
            'recent' => __('sales::sort.recent'),
            'oldest' => __('sales::sort.oldest'),
            'largest' => __('sales::sort.largest'),
            'customer' => __('sales::sort.customer'),
        ];
    }
}
