<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\FiltersByDate;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\Rfq;
use App\Modules\Purchase\Services\RfqService;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ দরপত্রের অনুরোধ ও তুলনা — পর্দা। ২৪ সেপ্টেম্বর ২০২৬।
 *
 * ── ⭐ মালিকের স্পেক ──────────────────────────────────────────────────
 * *"RFQ তৈরি করে একাধিক supplier-এর কাছে quotation request পাঠানো যাবে"*,
 * আর তুলনায় দেখা যাবে দর · ছাড় · কর · ভাড়া · সরবরাহের সময় · শর্ত।
 *
 * ── ⓘ তুলনার পর্দা কাউকে বাছে না ────────────────────────────────────
 * ⚠️ সবচেয়ে কম দরটা দাগানো হয়, কিন্তু *"এটাই নেওয়া হোক"* বলা হয় না।
 * ⛔ কারণ সস্তা মানেই সেরা নয়: একজন কম দর বলেন আর ত্রিশ দিনে মাল দেন,
 * আরেকজন একটু বেশি বলেন আর তিন দিনে দেন। ⭐ সংখ্যাগুলো পাশাপাশি রাখা
 * আমাদের কাজ; সিদ্ধান্তটা মানুষের।
 */
class RfqController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use SortsLists;

    public function __construct(
        private readonly RfqService $rfqs,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(Rfq::class, 'rfq'),
            new Middleware('can:send,rfq', only: ['send']),
            new Middleware('can:quote,rfq', only: ['quote', 'storeQuote']),
        ];
    }

    public function index(Request $request): View
    {
        $query = Rfq::query()
            ->with(['warehouse'])
            ->withCount(['lines', 'suppliers', 'quotations']);

        $dates = $this->applyDateRange($query, $request);

        $sort = $this->applySort($query, $request, [
            /*
             * ⭐ জবাবের অপেক্ষায় থাকাগুলো আগে — ডিফল্ট।
             *
             * ⓘ এই তালিকার আসল প্রশ্ন *"কার জবাব আসেনি"*, আর সেটাই
             * তাগাদার ভিত্তি। ⚠️ সাম্প্রতিক দিয়ে সাজালে পুরনো ঝুলন্ত
             * অনুরোধগুলোই নিচে চাপা পড়ত।
             */
            'waiting' => fn ($q) => $q
                ->orderByRaw("CASE WHEN status = '".DocumentStatus::CONFIRMED."' THEN 0 ELSE 1 END")
                ->orderBy('respond_by')->orderByDesc('id'),
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
        ]);

        return view('purchase::rfq.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rfqs' => $query->paginate(50)->withQueryString(),
            'dates' => $dates,
            'sort' => $sort,
            'sortOptions' => [
                'waiting' => __('purchase::sort.rfq_waiting'),
                'recent' => __('purchase::sort.recent'),
                'oldest' => __('purchase::sort.oldest'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        return view('purchase::rfq.form', [
            'menu' => $this->menu->forUser($request->user()),
            ...$this->formData(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'respond_by' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'terms' => ['nullable', 'string', 'max:2000'],
            'narration' => ['nullable', 'string', 'max:2000'],

            'suppliers' => ['required', 'array', 'min:1'],
            'suppliers.*' => ['integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId)],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'lines.*.qty' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.specification' => ['nullable', 'string', 'max:500'],
        ]);

        $rfq = $this->rfqs->create($data, $data['lines'], $data['suppliers']);

        return redirect()
            ->route('purchase.rfq.show', $rfq)
            ->with('saved', __('purchase::message.rfq_created'));
    }

    /**
     * একটা অনুরোধ — আর তার তুলনার ছক।
     */
    public function show(Request $request, Rfq $rfq): View
    {
        $rfq->load(['lines.product.unit', 'suppliers', 'quotations.lines', 'quotations.supplier', 'warehouse']);

        return view('purchase::rfq.show', [
            'menu' => $this->menu->forUser($request->user()),
            'rfq' => $rfq,

            /*
             * ⓘ ছকটা সবসময় বানানো হয়, এমনকি একটাও দর না এলেও — ⚠️
             * তখন খালি ছকটাই সঠিক বার্তা: *"কেউ এখনো জবাব দেননি"*।
             */
            'comparison' => $this->rfqs->compare($rfq),
            'silent' => $rfq->silentSuppliers(),
        ]);
    }

    public function send(Rfq $rfq): RedirectResponse
    {
        $this->rfqs->send($rfq);

        return redirect()
            ->route('purchase.rfq.show', $rfq)
            ->with('saved', __('purchase::message.rfq_sent'));
    }

    /**
     * একজন সরবরাহকারীর দর লেখার ফর্ম।
     *
     * ⓘ সারিগুলো RFQ থেকেই আসে, ⚠️ কারণ দরটা **ঐ প্রশ্নেরই** উত্তর।
     * ⛔ নতুন পণ্য যোগ করতে দিলে তুলনার ছকে দুইজনের দুই রকম তালিকা
     * বসত, আর পাশাপাশি রাখার কোনো মানে থাকত না।
     */
    public function quote(Request $request, Rfq $rfq): View
    {
        $rfq->load(['lines.product.unit', 'suppliers', 'quotations']);

        $answered = $rfq->quotations->pluck('supplier_id')->all();

        return view('purchase::rfq.quote', [
            'menu' => $this->menu->forUser($request->user()),
            'rfq' => $rfq,

            /*
             * ⛔ যাঁরা আগেই জবাব দিয়েছেন তাঁরা তালিকায় নেই — ⚠️ একই
             * RFQ-তে একজনের দুইটা দর থাকলে তুলনায় তিনিই দুইবার বসতেন।
             */
            'suppliers' => $rfq->suppliers->reject(
                fn ($supplier) => in_array($supplier->id, $answered, true),
            ),
        ]);
    }

    public function storeQuote(Request $request, Rfq $rfq): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            'supplier_id' => ['required', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'supplier_quote_no' => ['nullable', 'string', 'max:60'],
            'quoted_on' => ['required', 'date', 'before_or_equal:today'],
            'valid_until' => ['nullable', 'date'],
            'delivery_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'payment_terms' => ['nullable', 'string', 'max:200'],
            'freight' => ['nullable', 'numeric', 'min:0'],
            'other_charges' => ['nullable', 'numeric', 'min:0'],
            'narration' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'lines.*.qty' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->rfqs->quote(['rfq_id' => $rfq->id] + $data, $data['lines']);

        return redirect()
            ->route('purchase.rfq.show', $rfq)
            ->with('saved', __('purchase::message.quotation_saved'));
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'products' => Product::query()->active()->with('unit')->orderBy('name_en')->get(),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
            'suppliers' => Supplier::query()->orderBy('code')->get(),
        ];
    }
}
