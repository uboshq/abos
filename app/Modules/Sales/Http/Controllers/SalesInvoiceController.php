<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\FiltersByDate;
use App\Core\Concerns\SortsLists;
use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Support\DocumentStatus;
use App\Core\Support\ProcessBand;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Http\Requests\SalesInvoiceRequest;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * বিক্রয় বিল — পর্দা।
 */
class SalesInvoiceController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use SortsLists;

    public function __construct(
        private readonly SalesInvoiceService $service,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(SalesInvoice::class, 'invoice'),
            new Middleware('can:sales.invoice.create', only: ['confirm']),
            new Middleware('can:sales.invoice.cancel', only: ['cancel']),
        ];
    }

    public function index(Request $request): View
    {
        $query = SalesInvoice::query()
            ->search($request->query('q'))
            ->with(['customer'])
            /*
             * এক গ্রাহকের চালানগুলো।
             *
             * ── কেন খোঁজার ঘর দিয়ে নয় ───────────────────────────────
             * গ্রাহকের পাতা থেকে "৭টা চালান" ঘরে ক্লিক করলে এখানে আসা
             * হয়। ওই লিংকটা `?q=<গ্রাহকের নাম>` দিয়ে বানানো যেত, আর
             * তাতে কাজও হত — যতক্ষণ না দুইজন গ্রাহকের নামে একই শব্দ
             * থাকে। তখন সংখ্যাটা বলত সাত, তালিকা দেখাত নয়, আর কেউ
             * বুঝতেন না কোনটা ভুল।
             *
             * আইডি ধরে ছাঁকলে সংখ্যা আর তালিকা সবসময় একই কথা বলে।
             *
             * ── কেন `whereKey` নয় ────────────────────────────────────
             * মানটা কোয়েরি-স্ট্রিং থেকে আসে, তাই যেকোনো কিছু হতে পারে।
             * `(int)` করার পর অসংখ্যা মান ০ হয়, আর ০ কোনো গ্রাহক নয় —
             * তখন ছাঁকনিটা বসেই না, ফলে পর্দা ভাঙে না।
             */
            ->when((int) $request->query('customer') > 0,
                fn ($q) => $q->where('customer_id', (int) $request->query('customer')))
            // বাতিলগুলো লুকানো, মোছা নয় (নিয়ম ৫)
            ->when(! $request->boolean('cancelled'),
                fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED));

        /*
         * ধাপের পটির কোয়েরিটা **অবস্থার ছাঁকনি বসানোর আগে**।
         *
         * তীরগুলো বাকি সব ছাঁকনি মানে — তারিখ, গ্রাহক, খোঁজা — কিন্তু
         * অবস্থারটা নয়। নাহলে "খসড়া" বেছে নেওয়ার পর তীরগুলো দেখাত
         * খসড়া ৩১ আর বাকি সব শূন্য, অর্থাৎ পটিটা তার নিজের কাজটাই
         * করত না: কোথায় কতটা জমে আছে সেটা দেখানো।
         */
        $bandBase = clone $query;

        /*
         * অবস্থা ধরে ছাঁকা — এই প্যারামিটারটা এসেছে ধাপের পটির সাথে,
         * ২৯ আগস্ট ২০২৬। তীরে ক্লিক করলে ওই ধাপের কাগজগুলোই থাকে।
         */
        $stage = (string) $request->query('stage', '');

        if (in_array($stage, DocumentStatus::ALL, true)) {
            $query->where('status', $stage);
        } else {
            $stage = '';
        }

        // তারিখের পরিসর — হোম পর্দার "আজকের বিক্রয়" ঠিক এখানেই নামে
        $dates = $this->applyDateRange($query, $request);

        $sort = $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'largest' => fn ($q) => $q->orderByDesc('total'),
            'customer' => fn ($q) => $q->orderBy('customer_id')->orderByDesc('trx_date'),
        ]);

        /*
         * ── তালিকার যোগফল ───────────────────────────────────────────
         *
         * ⚠️ **পাতার নয়, গোটা ছাঁকনির।** পঞ্চাশ সারির পাতায় "মোট"
         * দেখালে সেটা মিথ্যা হত: ব্যবহারকারী "এই মাস" ছেঁকে মোট জানতে
         * চান, প্রথম পঞ্চাশটার মোট নয়।
         *
         * ⓘ `clone` লাগে কারণ `paginate()` কোয়েরিটা খেয়ে ফেলে; আর
         * `reorder()` লাগে কারণ যোগফলে ক্রমের কোনো মানে নেই।
         *
         * ── ⚠️ আদায়টা এই টেবিলে নেই ─────────────────────────────────
         * বকেয়া কোনো কলাম নয় — `মোট − আদায়`, আর আদায় আসে আদায়ের
         * সারি থেকে। ⛔ **কেবল খাতায় বসা আদায়**: খসড়া আদায় গুনলে
         * বিলটা শোধ দেখাত আর তাগাদার তালিকা থেকে হারিয়ে যেত (ঠিক এই
         * ভুলটা [[SalesInvoice::collectedAmount]]-এ একবার ঘটেছে)।
         *
         * ⓘ তাই শর্তটা হাতে লেখা হয়নি — মডেলের নিজের সম্পর্ক ও
         * `posted()` স্কোপই ব্যবহার করা হয়েছে। শর্ত বদলালে দুই
         * জায়গাতেই বদলাবে।
         *
         * ── ⓘ বকেয়াটা যোগফলের স্তরে বিয়োগ ───────────────────────────
         * মালিকের নকশাও ঠিক এভাবেই গোনে: `৪২,১৮,৯৫০ − ৩১,০৪,২২০ =
         * ১১,১৪,৭৩০`। ⓘ এক বিলের বাড়তি আদায় অন্যটার বকেয়া কমায় না —
         * নিচের `LEAST` (১৯ সেপ্টেম্বর ২০২৬), তবু একটাই কোয়েরি।
         *
         * ⓘ খরচ দুইটা হালকা কোয়েরি, ঠিক `processBand`-এর মতোই।
         */
        $totalled = (clone $query)->reorder();

        $money = (clone $totalled)->sum('total');

        /*
         * ⭐ আদায় = আদায়ের কাগজ + রসিদ ভাউচার, বিলপ্রতি বিলের অঙ্ক পর্যন্ত — ১৯ সেপ্টেম্বর ২০২৬।
         *
         * ⛔ আগে কেবল আদায়ের কাগজ গোনা হত। কাউন্টারের টাকা এখন রসিদ ভাউচার
         * (মালিকের নিয়ম), তাই পুরনো গোনায় কাউন্টারের প্রতিটা বিল তালিকায়
         * পুরো বকেয়া দেখাত, অথচ বিলের পাতা বলত শোধ।
         *
         * ⓘ শর্তগুলো [[SalesInvoice::scopeWithCollected()]]-এর — নিজে লেখা নয়।
         * ⚠️ `LEAST`: বাড়তি জমা এখন স্বাভাবিক (খাতায় জমা থাকে, ব্যাংকের মতো),
         * তাই এক বিলের বাড়তি অন্য বিলের বকেয়া কমিয়ে দেখাবে না।
         */
        $perBill = SalesInvoice::query()
            ->whereIn('sal_invoices.id', (clone $totalled)->select('sal_invoices.id'))
            ->withCollected();

        $collected = (string) (DB::query()->fromSub($perBill, 'b')
            ->selectRaw('COALESCE(SUM(LEAST(b.total, b.collected_total + b.voucher_total)), 0) AS s')
            ->value('s') ?? '0');

        return view('sales::invoice.index', [
            'menu' => $this->menu->forUser($request->user()),
            'invoices' => $query->paginate(50)->withQueryString(),
            'totals' => [
                'rows' => (clone $totalled)->count(),
                'money' => $money,
                'collected' => $collected,
                'due' => max(bcsub((string) $money, (string) $collected, 4), '0'),
            ],
            'q' => $request->query('q'),
            'dates' => $dates,
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
            'showCancelled' => $request->boolean('cancelled'),
            'stage' => $stage,
            /*
             * পটিটা কেবল `dynamic` রূপে আঁকা হয়, কিন্তু হিসাবটা এখানেই
             * হয় — কন্ট্রোলার জানে না কে কোন রূপে বসে আছেন, আর জানার
             * দরকারও নেই। খরচ চারটা হালকা `count`/`sum`।
             */
            'processBand' => ProcessBand::forStatuses(
                $bandBase,
                [
                    ['status' => DocumentStatus::DRAFT, 'label' => __('core.status.draft')],
                    ['status' => DocumentStatus::CONFIRMED, 'label' => __('core.status.confirmed')],
                    ['status' => DocumentStatus::CLOSED, 'label' => __('core.status.closed')],
                ],
                'sales.invoice.index',
                $request->except(['stage', 'page']),
                $stage !== '' ? $stage : null,
            ),
        ]);
    }

    /**
     * বিল কেবল একটা কাগজের উপর বসে — শূন্য থেকে নয়।
     *
     * ── ⛔ মালিকের নির্দেশ, ২১ সেপ্টেম্বর ২০২৬ ──────────────────────
     * *"new invoice bolte kono from thakbe na, just duto poth thakbe —
     * ek order, dui direct sales. baki poth bondo koro, r kono vabei
     * bill generate hobe na"*।
     *
     * ── ⚠️ কেন কথাটা ন্যায্য ─────────────────────────────────────────
     * ⓘ INV-0002 ড্যাশবোর্ডের "নতুন বিল" বোতাম থেকে হয়েছিল — একটা
     * **খালি ফর্ম**, যার পিছনে কোনো অর্ডার নেই, কোনো চালান নেই, কোনো
     * মজুদের হিসাব নেই। ⛔ ফল: ৫৬,৯৬,৫৯,০৭,৪১২ টাকার একটা বিল, আর
     * কেউ কিছু বলার নেই, কারণ মেলানোর মতো কোনো কাগজই ছিল না।
     *
     * ⭐ বিল একটা **ফল**, একটা শুরু নয়: হয় অর্ডার → চালান → বিল, নয়
     * সরাসরি বিক্রয় (যেখানে কার্ট, মজুদ আর টাকা একসাথে মেলে)।
     *
     * ── ⓘ বোতাম লুকানো যথেষ্ট নয় ───────────────────────────────────
     * ⚠️ ড্যাশবোর্ডের সারিটা তুলে দেওয়া হয়েছে, কিন্তু ঠিকানা টাইপ
     * করলেই পর্দা খুলত। ⛔ এই অ্যাপে ঠিক ঐ ভুলটা আগে একবার হয়েছে
     * (রপ্তানি বন্ধ করা হয়েছিল কেবল বোতাম লুকিয়ে)। তাই দরজাটা
     * **এখানে** বন্ধ।
     */
    public function create(Request $request): View
    {
        $challan = $this->chosenChallan($request);

        abort_if($challan === null, 404, __('sales::message.invoice_needs_a_paper'));

        return view('sales::invoice.form', [
            'menu' => $this->menu->forUser($request->user()),
            'invoice' => new SalesInvoice(['trx_date' => now()->toDateString()]),
            'challan' => $challan,
            ...$this->formData(),
        ]);
    }

    /**
     * ⛔ আর জমা দেওয়ার দরজাটাও একই শর্তে — ২১ সেপ্টেম্বর ২০২৬।
     *
     * ⚠️ কেবল `create()` আটকালে ফাঁকটা থেকে যেত: ফর্মটা না খুলেও কেউ
     * সরাসরি এখানে POST করে বিল বানাতে পারত। ⓘ মালিকের কথা ছিল *"r
     * kono vabei bill generate hobe na"* — "কোনোভাবেই", অর্থাৎ পর্দা
     * নয়, **পথ** বন্ধ।
     *
     * ⓘ সরাসরি বিক্রয় এই দরজা দিয়ে আসে না — তার নিজের রুট
     * (`sales.direct.store`), আর সে [[SalesInvoiceService]]-কে সরাসরি
     * ডাকে। ⭐ তাই এখানে তালা দিলে ঐ পথটা অক্ষত থাকে।
     */
    public function store(SalesInvoiceRequest $request): RedirectResponse
    {
        $document = $this->service->create($request->documentData(), $request->lineData());

        return redirect()
            ->route('sales.invoice.show', $document)
            ->with('saved', __('sales::message.invoice_created'));
    }

    public function show(Request $request, SalesInvoice $invoice): View
    {
        $invoice->load(['lines.product.unit', 'lines.challanLine.challan', 'customer', 'creator']);

        return view('sales::invoice.show', [
            'menu' => $this->menu->forUser($request->user()),
            'invoice' => $invoice,

            /*
             * ⓘ কাউন্টারের যে ডিপোজিটগুলো সইয়ের অপেক্ষায় — প্রতিটার সাথে
             * অনুমোদনের অবস্থা, যাতে পাতাটা বলতে পারে কোনটা আটকে আছে।
             */
            'heldDeposits' => $invoice->isHeldAtCounter()
                ? $invoice->heldCounterDeposits()->orderBy('id')->get()->map(fn ($v) => [
                    'voucher' => $v,
                    'approval' => app(ApprovalEngine::class)->latestFor($v, VoucherApproval::COUNTER_DEPOSIT),
                ])->all()
                : [],
        ]);
    }

    public function edit(Request $request, SalesInvoice $invoice): View
    {
        $invoice->load(['lines.product', 'lines.challanLine']);

        return view('sales::invoice.form', [
            'menu' => $this->menu->forUser($request->user()),
            'invoice' => $invoice,
            'challan' => null,
            ...$this->formData(),
        ]);
    }

    public function update(SalesInvoiceRequest $request, SalesInvoice $invoice): RedirectResponse
    {
        $this->service->update($invoice, $request->documentData(), $request->lineData());

        return redirect()
            ->route('sales.invoice.show', $invoice)
            ->with('saved', __('sales::message.invoice_updated'));
    }

    public function confirm(SalesInvoice $invoice): RedirectResponse
    {
        /*
         * ⭐ কাউন্টারে আটকে থাকা বিক্রয় — একই বোতাম, ঠিক পথ (১৯ সেপ্টেম্বর)।
         *
         * ⓘ আলাদা বোতাম নয়: মানুষ বিলের পাতায় "নিশ্চিত"-ই খোঁজেন। ⚠️ সই না
         * হলে [[DirectSaleService::finishHeld()]] পরিষ্কার বার্তা দেয়; হলে
         * চালান, মাল, বিল আর ডিপোজিট একসাথে খাতায় ওঠে।
         */
        if ($invoice->isHeldAtCounter()) {
            app(DirectSaleService::class)->finishHeld($invoice);

            return redirect()
                ->route('sales.invoice.show', $invoice)
                ->with('saved', __('sales::message.held_sale_finished', ['no' => $invoice->document_no]));
        }

        $this->service->confirm($invoice);

        return redirect()
            ->route('sales.invoice.show', $invoice)
            ->with('saved', __('sales::message.invoice_confirmed'));
    }

    public function cancel(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $this->service->cancel($invoice, $reason);

        return redirect()
            ->route('sales.invoice.show', $invoice)
            ->with('saved', __('sales::message.invoice_cancelled'));
    }

    /** চালান ধরে খোলা হলে যতটুকুর বিল হয়নি ঠিক ততটুকু নিয়ে লাইন ভরে। */
    private function chosenChallan(Request $request): ?DeliveryChallan
    {
        $id = $request->integer('delivery_challan_id');

        if ($id <= 0) {
            return null;
        }

        return DeliveryChallan::query()
            ->where('status', DocumentStatus::CONFIRMED)
            ->with(['lines.product.unit', 'customer'])
            ->find($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'customers' => Customer::query()->active()->orderBy('name_en')->get(),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'products' => Product::query()->active()->with('unit')->orderBy('name_en')->get(),
            'challans' => DeliveryChallan::query()->where('status', DocumentStatus::CONFIRMED)
                ->with('customer')->orderByDesc('trx_date')->limit(200)->get(),
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
