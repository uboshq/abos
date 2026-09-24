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
use App\Modules\Purchase\Models\PurchaseRequisition;
use App\Modules\Purchase\Services\PurchaseRequisitionService;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ ক্রয়ের চাহিদা — পর্দা। ২৪ সেপ্টেম্বর ২০২৬।
 *
 * ── ⭐ মালিকের স্পেক ──────────────────────────────────────────────────
 * *"Internal user/department purchase requirement তৈরি করবে"*, আর
 * অনুমোদিত চাহিদা থেকে ক্রয়াদেশ বানানো যাবে।
 *
 * ── ⓘ তিনটা ধাপ, তিনটা চাবি ─────────────────────────────────────────
 *   **লেখা** (`requisition.create`) — যিনি চান, তিনি লেখেন
 *   **অনুমোদন** (`requisition.approve`) — এখানে সই, আর এখানেই থামা যায়
 *   **আদেশে রূপান্তর** (`order.create`) — ক্রয় বিভাগের কাজ
 *
 * ⚠️ তৃতীয়টা চাহিদার নিজের চাবি নয়, **আদেশ বানানোর** চাবি — ⛔ কারণ
 * কাজটা সত্যিই সেটাই, আর নতুন একটা চাবি বানালে কেউ একদিন চাহিদার
 * অনুমতি দিয়ে আদেশ বানিয়ে ফেলতেন।
 */
class PurchaseRequisitionController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use SortsLists;

    public function __construct(
        private readonly PurchaseRequisitionService $requisitions,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(PurchaseRequisition::class, 'requisition'),
            new Middleware('can:approve,requisition', only: ['approve']),

            /*
             * ⛔ রূপান্তরের চাবি **আদেশ বানানোর**, চাহিদার নয় — উপরের
             * docblock-এ কারণ।
             */
            new Middleware('can:purchase.order.create', only: ['order', 'storeOrder']),
            new Middleware('can:delete,requisition', only: ['cancel']),
        ];
    }

    public function index(Request $request): View
    {
        $query = PurchaseRequisition::query()
            ->with(['requester', 'order'])
            ->withCount('lines');

        $dates = $this->applyDateRange($query, $request);

        $sort = $this->applySort($query, $request, [
            /*
             * ⭐ যেগুলোর সিদ্ধান্ত বাকি, আগে — ডিফল্ট।
             *
             * ⓘ এই তালিকার আসল প্রশ্ন *"কার চাওয়াটা ঝুলে আছে"*।
             * ⚠️ সাম্প্রতিক দিয়ে সাজালে পুরনো ঝুলন্ত চাহিদাগুলোই নিচে
             * চাপা পড়ত — অথচ ওগুলোই সবচেয়ে বেশি দিন অপেক্ষা করছে।
             */
            'waiting' => fn ($q) => $q
                ->orderByRaw("CASE WHEN status = '".DocumentStatus::DRAFT."' THEN 0 ELSE 1 END")
                ->orderBy('needed_by')->orderByDesc('id'),
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
        ]);

        return view('purchase::requisition.index', [
            'menu' => $this->menu->forUser($request->user()),
            'requisitions' => $query->paginate(50)->withQueryString(),
            'dates' => $dates,
            'sort' => $sort,
            'sortOptions' => [
                'waiting' => __('purchase::sort.requisition_waiting'),
                'recent' => __('purchase::sort.recent'),
                'oldest' => __('purchase::sort.oldest'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        return view('purchase::requisition.form', [
            'menu' => $this->menu->forUser($request->user()),
            'products' => Product::query()->active()->with('unit')->orderBy('name_en')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            'trx_date' => ['required', 'date', 'before_or_equal:today'],

            /*
             * ⓘ `after_or_equal:today` নয় — ⚠️ কেউ গতকালের তারিখে একটা
             * চাহিদা লিখতেই পারেন (*"এটা কালই লাগত"*), আর সেটা আটকানো
             * মানে সত্যিটা লিখতে না দেওয়া।
             */
            'needed_by' => ['nullable', 'date'],
            'department' => ['nullable', 'string', 'max:120'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'narration' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'lines.*.qty' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.estimated_rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.narration' => ['nullable', 'string', 'max:500'],
        ]);

        $requisition = $this->requisitions->create($data, $data['lines']);

        return redirect()
            ->route('purchase.requisition.show', $requisition)
            ->with('saved', __('purchase::message.requisition_created'));
    }

    public function show(Request $request, PurchaseRequisition $requisition): View
    {
        $requisition->load(['lines.product.unit', 'requester', 'order']);

        return view('purchase::requisition.show', [
            'menu' => $this->menu->forUser($request->user()),
            'requisition' => $requisition,

            /*
             * ⓘ সরবরাহকারী ও গুদামের তালিকা কেবল তখনই, যখন রূপান্তরের
             * ঘরগুলো সত্যিই দেখানো হবে। ⚠️ সবসময় আনলে প্রতিটা চাহিদার
             * পাতায় দুইটা বাড়তি কোয়েরি যেত, অথচ ঘরগুলো দেখা যেত না।
             */
            'suppliers' => $requisition->canBecomeAnOrder() && $request->user()?->can('purchase.order.create')
                ? Supplier::query()->orderBy('code')->get()
                : collect(),
            'warehouses' => $requisition->canBecomeAnOrder() && $request->user()?->can('purchase.order.create')
                ? Warehouse::query()->active()->orderBy('code')->get()
                : collect(),
        ]);
    }

    public function approve(PurchaseRequisition $requisition): RedirectResponse
    {
        $this->requisitions->approve($requisition);

        return redirect()
            ->route('purchase.requisition.show', $requisition)
            ->with('saved', __('purchase::message.requisition_approved'));
    }

    /**
     * অনুমোদিত চাহিদা থেকে ক্রয়াদেশ।
     *
     * ⓘ সরবরাহকারীটা এখানে বাছা হয়, চাহিদায় নয় — ⚠️ যিনি চান তিনি
     * জানেন **কী** লাগবে, আর **কার কাছ থেকে** সেটা ক্রয় বিভাগের
     * সিদ্ধান্ত।
     */
    public function storeOrder(Request $request, PurchaseRequisition $requisition): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            'supplier_id' => ['required', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['required', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'expected_on' => ['nullable', 'date'],
        ]);

        $this->requisitions->toOrder($requisition, $data);

        return redirect()
            ->route('purchase.order.show', $requisition->fresh()->purchase_order_id)
            ->with('saved', __('purchase::message.requisition_ordered', [
                'no' => $requisition->document_no,
            ]));
    }

    public function cancel(Request $request, PurchaseRequisition $requisition): RedirectResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $this->requisitions->cancel($requisition, $reason);

        return redirect()
            ->route('purchase.requisition.show', $requisition)
            ->with('saved', __('purchase::message.requisition_cancelled'));
    }
}
