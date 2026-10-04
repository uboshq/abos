<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\FiltersByDate;
use App\Core\Concerns\GrandTotals;
use App\Core\Concerns\SortsLists;
use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintableDocument;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Engines\Print\PrintProfile;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\PackConversion;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\Sales\Http\Requests\SalesQuotationRequest;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Sales\Models\SalesQuotationLine;
use App\Modules\Sales\Services\SalesQuotationService;
use App\Modules\Sales\Support\QuotationComparison;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * বিক্রয় উদ্ধৃতি — পর্দা (NEXUS §৮)।
 *
 * ⓘ ছক বিক্রয় আদেশের পর্দার ([[SalesOrderController]]): একই সারির সম্পাদক,
 * একই বারকোড, একই তালিকার ধরন — যাতে যিনি আদেশ লেখেন তাঁকে নতুন কিছু
 * শিখতে না হয়।
 */
class SalesQuotationController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use GrandTotals;
    use SortsLists;

    public function __construct(
        private readonly SalesQuotationService $service,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(SalesQuotation::class, 'quotation'),
            new Middleware('can:advance,quotation', only: ['submit', 'approve', 'send', 'accept', 'reject', 'revise']),
            new Middleware('can:delete,quotation', only: ['cancel']),
            new Middleware('can:convert,quotation', only: ['convert']),
            new Middleware('can:view,quotation', only: ['paper']),
            // ⓘ তুলনা আর সংস্করণ — তালিকার মতোই পুরো ধরনের উপর, কোনো একটা কাগজ নয়
            new Middleware('can:viewAny,'.SalesQuotation::class, only: ['compare', 'revisions']),
        ];
    }

    /**
     * তালিকা — ওপরে ট্যাব: সব · খসড়া · পাঠানো · গৃহীত · মেয়াদোত্তীর্ণ · আদেশ হয়েছে · হারানো।
     *
     * ⭐ মালিকের আন্তর্জাতিক পরিকল্পনা, ৪ অক্টোবর ২০২৬ — মেনুর "উদ্ধৃতির তালিকা" এখন এই পাতা। ⓘ অচেনা ট্যাব মানে
     * "সব"; গোনা খোঁজা আর তারিখ মানে, নাহলে ট্যাব বলত "১২" আর খুললে দেখাত "৩"।
     */
    public function index(Request $request): View
    {
        $tab = (string) $request->query('tab', 'all');
        $tab = array_key_exists($tab, SalesQuotation::TABS) ? $tab : 'all';

        $base = SalesQuotation::query()->search($request->query('q'));
        $dates = $this->applyDateRange($base, $request);

        $counts = [];

        foreach (array_keys(SalesQuotation::TABS) as $key) {
            $counts[$key] = (clone $base)->inTab($key)->count();
        }

        $query = (clone $base)->with(['customer', 'order'])->inTab($tab);

        $sort = $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'largest' => fn ($q) => $q->orderByDesc('total'),
            'customer' => fn ($q) => $q->orderBy('customer_id')->orderByDesc('trx_date'),
        ]);

        $keep = $request->except(['tab', 'page']);

        return view('sales::quotation.index', [
            'menu' => $this->menu->forUser($request->user()),
            'grand' => $this->grandTotals($query, ['total' => 't.total']),
            'quotations' => $query->paginate(50)->withQueryString(),
            'q' => $request->query('q'),
            'dates' => $dates,
            'sort' => $sort,
            'sortOptions' => [
                'recent' => __('sales::sort.recent'),
                'oldest' => __('sales::sort.oldest'),
                'largest' => __('sales::sort.largest'),
                'customer' => __('sales::sort.customer'),
            ],
            'tab' => $tab,
            'tabs' => array_map(fn (string $key) => [
                'key' => $key,
                'label' => __('sales::quotation.tab.'.$key),
                'hint' => __('sales::quotation.tab_hint.'.$key),
                'url' => route('sales.quotation.index', $key === 'all' ? $keep : [...$keep, 'tab' => $key]),
                'count' => $counts[$key],
                'active' => $key === $tab,
            ], array_keys(SalesQuotation::TABS)),
        ]);
    }

    /**
     * তুলনা — দুই থেকে ছয়টা উদ্ধৃতি পাশাপাশি, সারি ধরে, বদল রঙে ([[QuotationComparison]])।
     *
     * ⓘ দুইভাবে আসা যায়: `?family=<id>` — একই উদ্ধৃতির সব সংস্করণ; বা `?ids[]=…` — তালিকা থেকে বেছে।
     * দুইটার কম হলে বাছার পাতা (খোঁজা আর টিক)।
     *
     * ⛔ চাওয়া একটা id-ও না পেলে ৪০৪ — অন্য কোম্পানির কাগজ (কোম্পানির দেয়াল মডেলের স্কোপে) নীরবে বাদ দিয়ে
     * বাকিগুলো দেখালে পর্দা মিথ্যা বলত "এই কয়টাই চেয়েছিলেন"।
     */
    public function compare(Request $request): View
    {
        $ids = collect((array) $request->query('ids', []))
            ->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->unique()->values();

        if ($request->filled('family')) {
            $anchor = SalesQuotation::query()->findOrFail((int) $request->query('family'));
            $ids = $anchor->family()->pluck('id')->map(fn ($id) => (int) $id)->values();
        }

        abort_if($ids->count() > QuotationComparison::MAX, 422, __('sales::quotation.compare.too_many', ['max' => QuotationComparison::MAX]));

        $quotations = SalesQuotation::query()
            ->whereKey($ids->all())
            ->with(['lines.product', 'lines.enteredUnit', 'customer', 'paymentTerm'])
            ->orderBy('id')
            ->get();

        abort_if($quotations->count() !== $ids->count(), 404);

        $picker = null;

        if ($quotations->count() < 2) {
            $picker = SalesQuotation::query()
                ->search($request->query('q'))
                ->with('customer')
                ->latest('id')
                ->limit(50)
                ->get();
        }

        return view('sales::quotation.compare', [
            'menu' => $this->menu->forUser($request->user()),
            'quotations' => $quotations,
            'comparison' => $quotations->count() >= 2 ? QuotationComparison::of($quotations) : null,
            'picker' => $picker,
            'chosen' => $ids->all(),
            'q' => $request->query('q'),
        ]);
    }

    /**
     * সংস্করণ — যে উদ্ধৃতির অন্তত একটা নতুন সংস্করণ হয়েছে, মূল ধরে এক সারি করে।
     *
     * ⓘ প্রতিটা সারিতে মূল নম্বর, গ্রাহক, কয়টা সংস্করণ, শেষটার নম্বর ও অবস্থা, আর "তুলনা" — পুরো ইতিহাস এক চাপে।
     */
    public function revisions(Request $request): View
    {
        $roots = SalesQuotation::query()
            ->whereNull('root_quotation_id')
            ->whereIn('id', SalesQuotation::query()->whereNotNull('root_quotation_id')->select('root_quotation_id'))
            ->search($request->query('q'))
            ->with('customer')
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        $latest = SalesQuotation::query()
            ->whereIn('root_quotation_id', $roots->pluck('id'))
            ->get()
            ->groupBy('root_quotation_id')
            ->map(fn ($family) => $family->sortByDesc('revision_no')->first());

        return view('sales::quotation.revisions', [
            'menu' => $this->menu->forUser($request->user()),
            'roots' => $roots,
            'latest' => $latest,
            'q' => $request->query('q'),
        ]);
    }

    public function create(Request $request): View
    {
        $today = now();

        return view('sales::quotation.form', [
            'menu' => $this->menu->forUser($request->user()),
            'quotation' => new SalesQuotation([
                'trx_date' => $today->toDateString(),
                'valid_until' => $today->copy()->addDays($this->service->defaultValidDays())->toDateString(),
                'price_list_id' => PriceList::query()->active()->where('is_default', true)->value('id'),
                'payment_term_id' => PaymentTerm::query()->active()->where('is_default', true)->value('id'),
            ]),
            ...$this->formData(),
        ]);
    }

    public function store(SalesQuotationRequest $request): RedirectResponse
    {
        $quotation = $this->service->create($request->documentData(), $request->lineData());

        return redirect()
            ->route('sales.quotation.show', $quotation)
            ->with('saved', __('sales::quotation.message.created'));
    }

    public function show(Request $request, SalesQuotation $quotation): View
    {
        $quotation->load(['lines.product.unit', 'lines.enteredUnit', 'customer', 'priceList', 'paymentTerm', 'order', 'creator', 'supersededBy']);

        return view('sales::quotation.show', [
            'menu' => $this->menu->forUser($request->user()),
            'quotation' => $quotation,
            // ⭐ সংস্করণের ইতিহাস — মূল থেকে শেষ পর্যন্ত, এই পাতারটা চিহ্নিত
            'family' => $quotation->family()->get(),
        ]);
    }

    public function edit(Request $request, SalesQuotation $quotation): View
    {
        $quotation->load(['lines.product']);

        return view('sales::quotation.form', [
            'menu' => $this->menu->forUser($request->user()),
            'quotation' => $quotation,
            ...$this->formData(),
        ]);
    }

    public function update(SalesQuotationRequest $request, SalesQuotation $quotation): RedirectResponse
    {
        $this->service->update($quotation, $request->documentData(), $request->lineData());

        return redirect()
            ->route('sales.quotation.show', $quotation)
            ->with('saved', __('sales::quotation.message.updated'));
    }

    /**
     * জমা — ছক না থাকলে সোজা অনুমোদিত, থাকলে সইয়ের অপেক্ষায়।
     *
     * ⓘ সইয়ের অপেক্ষার বার্তাটা `HeldForApproval` হয়ে ফর্মের ভুলের মতোই
     * ফেরে — কাগজটা তখন "জমা"-তে বসে থাকে, হারায় না।
     */
    public function submit(SalesQuotation $quotation): RedirectResponse
    {
        $this->service->submit($quotation);

        return $this->back($quotation, 'approved');
    }

    public function approve(SalesQuotation $quotation): RedirectResponse
    {
        $this->service->approve($quotation);

        return $this->back($quotation, 'approved');
    }

    public function send(SalesQuotation $quotation): RedirectResponse
    {
        $this->service->markSent($quotation);

        return $this->back($quotation, 'sent');
    }

    public function accept(Request $request, SalesQuotation $quotation): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        $this->service->accept($quotation, $note);

        return $this->back($quotation, 'accepted');
    }

    public function reject(Request $request, SalesQuotation $quotation): RedirectResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'max:500']])['note'];

        $this->service->reject($quotation, $note);

        return $this->back($quotation, 'rejected');
    }

    /**
     * বদল — ডিলার দেখার আগে একই কাগজ খসড়ায়, পরে নতুন সংস্করণ ([[SalesQuotationService::revise()]])।
     * ⓘ যেটায় এখন কাজ চলবে, পাতা সেখানেই যায়।
     */
    public function revise(SalesQuotation $quotation): RedirectResponse
    {
        $working = $this->service->revise($quotation);

        if ((int) $working->getKey() === (int) $quotation->getKey()) {
            return $this->back($quotation, 'revised');
        }

        return redirect()
            ->route('sales.quotation.show', $working)
            ->with('saved', __('sales::quotation.message.new_revision', ['no' => $working->document_no, 'old' => $quotation->document_no]));
    }

    public function cancel(Request $request, SalesQuotation $quotation): RedirectResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $this->service->cancel($quotation, $reason);

        return $this->back($quotation, 'cancelled');
    }

    /** গৃহীত উদ্ধৃতি → বিক্রয় আদেশ; তারপর সোজা আদেশের পাতায়, নিশ্চিত করার জন্য। */
    public function convert(SalesQuotation $quotation): RedirectResponse
    {
        $order = $this->service->convert($quotation);

        return redirect()
            ->route('sales.order.show', $order)
            ->with('saved', __('sales::quotation.message.converted', ['no' => $quotation->document_no]));
    }

    /**
     * উদ্ধৃতির কাগজ — ডিলারের হাতে যায়।
     *
     * ⓘ ছাপার ইঞ্জিন আর `print.document` ছাঁচ বিক্রয়ের বাকি কাগজের মতোই;
     * কাগজের মাপ আদেশের সেটিং থেকে (`sales.print.paper.order`) — উদ্ধৃতি
     * আদেশেরই আগের ধাপ, আর আলাদা সেটিং মানে মালিককে আরেকটা ঘর বোঝানো।
     *
     * ⚠️ অনুমোদনের আগের বা মেয়াদ পেরোনো কাগজের মাথায় সতর্কবার্তা — নাহলে
     * সই না হওয়া দর ডিলারের হাতে "পাকা" হয়ে পৌঁছাত।
     */
    public function paper(Request $request, SalesQuotation $quotation, PrintEngine $print, SettingsService $settings): Response
    {
        $quotation->load(['lines.product.unit', 'lines.enteredUnit', 'customer', 'priceList', 'paymentTerm']);

        $meta = array_filter([
            'core.print.document_no' => $quotation->document_no,
            'core.print.date' => DateFormat::format($quotation->trx_date),
            'sales::field.customer' => $quotation->customer?->name() ?? '',
            'sales::quotation.field.valid_until' => DateFormat::format($quotation->valid_until),
            'sales::quotation.field.payment_term' => $quotation->paymentTerm?->name() ?? '',
            'sales::quotation.field.price_list' => $quotation->priceList?->name() ?? '',
            'sales::quotation.field.delivery_terms' => (string) $quotation->delivery_terms,
        ], fn ($value) => $value !== '');

        $totals = ['core.print.subtotal' => Money::format($quotation->subtotal)];

        if (bccomp((string) $quotation->discount, '0', 4) > 0) {
            $totals['core.print.discount'] = Money::format($quotation->discount);
        }

        if (bccomp((string) $quotation->tax, '0', 4) > 0) {
            $totals['core.print.tax'] = Money::format($quotation->tax);
        }

        $totals['core.print.total'] = Money::format($quotation->total);

        $doc = new PrintableDocument(
            title: __('sales::quotation.doc'),
            meta: $meta,
            lines: $quotation->lines->map(fn (SalesQuotationLine $line) => [
                'code' => (string) ($line->product?->code ?? ''),
                'name' => (string) ($line->product?->name() ?? ''),
                'qty' => $this->qty($line->packedQty('qty')),
                'unit' => $line->packedUnitName(),
                'rate' => Money::format($line->packedRate('rate', 'qty')),
                'amount' => Money::format($line->amount),
                'note' => '',
                'free' => '',
                'group' => '',
            ])->values()->all(),
            totals: $totals,
            signatures: ['core.print.prepared_by', 'core.print.approved_by'],
            narration: $quotation->narration,
        );

        $state = $quotation->effectiveStatus();

        if (in_array($state, [SalesQuotation::DRAFT, SalesQuotation::SUBMITTED], true)) {
            $doc = $doc->withNotice(__('sales::quotation.print.not_approved'));
        }

        if ($state === SalesQuotation::EXPIRED) {
            $doc = $doc->withNotice(__('sales::quotation.print.expired'));
        }

        $cancelled = $state === SalesQuotation::CANCELLED;

        if ($cancelled) {
            $doc = $doc->withNotice(__('core.print.cancelled_notice'));
        }

        $paper = PaperSize::chosen($request->query('paper'), $settings->get('sales.print.paper.order'));

        $pdf = $print->render(
            template: 'print.document',
            data: [
                'doc' => $doc->withWordsFor((string) $quotation->total, app()->getLocale()),
                'title' => $doc->title.' '.$quotation->document_no,
            ],
            paper: $paper,
            watermark: $cancelled ? __('core.print.cancelled_watermark') : null,
            profile: PrintProfile::for('order', $settings)->target,
        );

        $asFile = $request->boolean('download');

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($asFile ? 'attachment' : 'inline').'; filename="'.$quotation->document_no.'.pdf"',
        ]);
    }

    private function back(SalesQuotation $quotation, string $message): RedirectResponse
    {
        return redirect()
            ->route('sales.quotation.show', $quotation)
            ->with('saved', __('sales::quotation.message.'.$message));
    }

    /** পরিমাণে পিছনের শূন্য বাদ — "১০.০০০০ বস্তা" কেউ লেখে না। */
    private function qty(mixed $value): string
    {
        $formatted = rtrim(rtrim(Money::format($value, 4), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        $products = Product::query()->active()->with('unit')->orderBy('name_en')->get();

        return [
            'customers' => Customer::query()->inViewedBranch()->active()->orderBy('name_en')->get(),
            'priceLists' => PriceList::query()->active()->orderBy('code')->get(),
            'paymentTerms' => PaymentTerm::query()->active()->orderBy('days')->get(),
            'products' => $products,

            // ⓘ বারকোড — আদেশের পর্দার একই মানচিত্র, একই ডেস্ক (`salesOrderDesk`)
            'barcodes' => $products
                ->filter(fn (Product $p) => (string) $p->barcode !== '')
                ->mapWithKeys(fn (Product $p) => [(string) $p->barcode => (string) $p->id])
                ->all(),
            'packBarcodes' => app(PackConversion::class)->barcodesFor($products),
        ];
    }
}
