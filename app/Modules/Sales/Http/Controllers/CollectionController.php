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
use App\Models\Approval;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * আদায় — পর্দা।
 *
 * ⭐ মালিক, ২০ নভেম্বর ২০২৬ (চূড়ান্ত — "one money-in paper"): গ্রাহকের টাকা ঢোকে কেবল দুই দরজায় — কাউন্টারের সরাসরি বিক্রির রসিদ আর
 * হিসাবের রসিদ ভাউচার (RCV-…); "টাকা জমার খবর" কেবল খবর, গৃহীত হলে রসিদ ভাউচার হয় ([[DepositClaimService::accept()]])। আদায়ের কাগজ
 * (COL-…) আর লেখা যায় না: নতুন/বদলের ঠিকানা রসিদের পর্দায় যায়, লেখার দরজা কারণসহ ফেরায়। ⓘ আগের কাগজ তালিকায়, পাতায়, ছাপায় থাকে;
 * বাতিল আর চেক-ফেরত আগের মতোই।
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

        /*
         * ⛔ মোটে কেবল পাকা আদায় — নিশ্চিত আর বন্ধ, ফোনের মোটের মতোই ([[CollectionApiController::index()]]; a4-এর নিরীক্ষা,
         * fe ২৪ অক্টোবর ২০২৬)। আগে খসড়াও গোনা হত, তাই ওয়েব আর ফোন একই দিনের আদায়ে আলাদা মোট বলত, আর খাতায় না-ওঠা
         * টাকা "আদায়" হয়ে দেখাত। খসড়া আর সইয়ের অপেক্ষা আলাদা লাইনে — সংখ্যা আর টাকা।
         */
        $posted = (clone $totalled)->whereIn('sal_collections.status', DocumentStatus::POSTED);
        $drafts = (clone $totalled)->where('sal_collections.status', DocumentStatus::DRAFT);
        $signing = fn ($q) => $q->select(DB::raw(1))->from('approvals')
            ->where('approvals.approvable_type', Collection::class)
            ->whereColumn('approvals.approvable_id', 'sal_collections.id')
            ->where('approvals.status', Approval::PENDING);
        $waiting = (clone $drafts)->whereExists($signing);
        $plain = (clone $drafts)->whereNotExists($signing);

        return view('sales::collection.index', [
            'menu' => $this->menu->forUser($request->user()),
            'grand' => $this->grandTotals((clone $query)->whereIn('sal_collections.status', DocumentStatus::POSTED), ['amount' => 't.amount']),
            'collections' => $query->paginate(\App\Core\Support\PerPage::of())->withQueryString(),
            'totals' => [
                'rows' => (clone $totalled)->count(),
                'money' => (string) (clone $posted)->sum('sal_collections.amount'),
                'drafts' => ['count' => (clone $plain)->count(), 'money' => (string) (clone $plain)->sum('sal_collections.amount')],
                'waiting' => ['count' => (clone $waiting)->count(), 'money' => (string) (clone $waiting)->sum('sal_collections.amount')],
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

    /**
     * ⭐ টাকা ঢোকার কাগজ একটাই — হিসাবের রসিদ ভাউচার (মালিক, ২০ নভেম্বর ২০২৬, চূড়ান্ত: "one money-in paper")।
     *
     * ⓘ পুরনো ঠিকানা (`/sales/collections/create`, বুকমার্ক, পুরনো লিংক) রসিদের পর্দায় পাঠায়, গ্রাহক ভরা; বিল ধরে এলে বিল আর
     * বকেয়াও ভরা — গ্রাহক আর বিলের তালিকার "⋯ → টাকা নিন"-এর একই ঠিকানা। কারণটা উপরে নোটিশে। ⛔ আদায়ের ফর্ম আর আঁকা হয় না।
     * ⓘ আগের আদায়ের কাগজ (COL-…) দেখা, ছাপা, বাতিল আর চেক-ফেরত আগের মতোই ([[index()]], [[show()]], [[cancel()]], [[bounceCheque()]])।
     */
    public function create(Request $request): RedirectResponse
    {
        $invoice = $this->chosenInvoice($request);
        $customer = $invoice?->customer ?? $this->chosenCustomer($request->query('customer'));

        return $this->toTheReceipt($customer?->id, $invoice);
    }

    /** ⛔ নতুন আদায় লেখা হয় না — রসিদের পর্দায়, কারণসহ; কিছুই লেখা হয় না (মালিক, ২০ নভেম্বর ২০২৬) */
    public function store(Request $request): RedirectResponse
    {
        return redirect()
            ->route('accounts.voucher.create', ['type' => 'receipt'])
            ->withErrors(['collection' => __('sales::collection_rules.no_new_collection')])
            ->with('warning', __('sales::collection_rules.no_new_collection'));
    }

    /** ⛔ নতুন আদায়ের ফর্মের সাহায্যকারী — আর নেই (রসিদের পর্দার নিজের খোলা বিল আছে) */
    public function openItems(Request $request): JsonResponse
    {
        return $this->refusedJson();
    }

    /** ⛔ নতুন আদায়ের প্রিভিউ — আর নেই; কিছুই লেখে না, কিছুই দেখায় না */
    public function preview(Request $request): JsonResponse
    {
        return $this->refusedJson();
    }

    /** ⛔ নতুন আদায়ের নিশ্চিত-সারাংশ — আর নেই */
    public function overviewNew(Request $request): JsonResponse
    {
        return $this->refusedJson();
    }

    public function show(Request $request, Collection $collection): View
    {
        $collection->load(['lines.invoice', 'customer', 'account', 'creator']);

        return view('sales::collection.show', [
            'menu' => $this->menu->forUser($request->user()),
            'collection' => $collection,
        ]);
    }

    /**
     * ⛔ আদায়ের কাগজ আর বদলানো হয় না — রসিদের পর্দায়, সেই গ্রাহক ভরা (মালিক, ২০ নভেম্বর ২০২৬)। ⓘ খসড়া আদায় বাতিল করা যায়
     * ([[cancel()]]); টাকাটা তখন রসিদে নেওয়া হয়।
     */
    public function edit(Request $request, Collection $collection): RedirectResponse
    {
        return $this->toTheReceipt($collection->customer_id === null ? null : (int) $collection->customer_id, null);
    }

    /** ⛔ বদল নয় — কাগজের পাতায় ফেরত, কারণসহ; কিছুই বদলায় না */
    public function update(Request $request, Collection $collection): RedirectResponse
    {
        return $this->refusedOn($collection);
    }

    /**
     * ⛔ হাতে পাকা করা নয় — কাগজের পাতায় ফেরত, কারণসহ (মালিক, ২০ নভেম্বর ২০২৬)।
     * ⓘ আগে সইয়ে পাঠানো খসড়া শেষ সইয়ে নিজের পুরনো পথে পাকা হয় ([[\App\Modules\Sales\Listeners\ConfirmTheCollectionOnTheLastSignature]]);
     * না চাইলে বাতিল করুন।
     */
    public function confirm(Collection $collection): RedirectResponse
    {
        return $this->refusedOn($collection);
    }

    /**
     * রসিদ ভাউচারের পর্দা — গ্রাহক, আর বিল ধরে এলে বিল ও তার বকেয়া ভরা ([[\App\Modules\Accounts\Http\Controllers\VoucherController::prefill()]]-এর
     * সাদা তালিকার ঘর)।
     */
    private function toTheReceipt(?int $customerId, ?SalesInvoice $invoice): RedirectResponse
    {
        $due = $invoice === null ? null
            : SalesInvoice::query()->withCollected()->whereKey($invoice->id)->first()?->dueAmount();

        return redirect()
            ->route('accounts.voucher.create', array_filter([
                'type' => 'receipt',
                'party_type' => $customerId === null ? null : 'customer',
                'party_id' => $customerId,
                'against_type' => $invoice === null ? null : SalesInvoice::drillSourceType(),
                'against_id' => $invoice?->id,
                'amount' => $due !== null && bccomp($due, '0', 4) > 0 ? $due : null,
            ], fn ($v) => $v !== null))
            ->with('warning', __('sales::collection_rules.now_on_receipt'));
    }

    private function refusedOn(Collection $collection): RedirectResponse
    {
        return redirect()
            ->route('sales.collection.show', $collection)
            ->withErrors(['collection' => __('sales::collection_rules.no_new_collection')])
            ->with('warning', __('sales::collection_rules.no_new_collection'));
    }

    private function refusedJson(): JsonResponse
    {
        return response()->json([
            'message' => __('sales::collection_rules.no_new_collection'),
            'errors' => ['collection' => [__('sales::collection_rules.no_new_collection')]],
        ], 422);
    }

    /** গ্রাহক — নাগালে থাকলে: হেডারে বাছা শাখার (পিকারের একই ছাঁকনি; Customer-এ শাখার গ্লোবাল স্কোপ ইচ্ছা করেই নেই, ফোনের open-bills-ও তাই), তার উপরে ডিলারের নিয়ম */
    private function chosenCustomer(mixed $id): ?Customer
    {
        if (blank($id) || ! is_scalar($id)) {
            return null;
        }

        $id = (string) $id;
        $customer = Customer::query()->inViewedBranch()->with(Customer::PLACE)
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
