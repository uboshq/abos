<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\FiltersByDate;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\QualityInspection;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\QualityInspectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ গুণমান পরিদর্শন — পর্দা। ২৪ সেপ্টেম্বর ২০২৬।
 *
 * ── ⭐ মালিকের স্পেক ──────────────────────────────────────────────────
 * *"Received → Inspection → Approved / Quarantine / Rejected"*, আর
 * প্রতিটা পরিদর্শনের নিজের কাগজ।
 *
 * ── ⓘ দুই ধাপ, দুই হাত ──────────────────────────────────────────────
 *   ১. **কাগজ খোলা** (`store`) — মজুদে কিছুই বদলায় না
 *   ২. **রায়** (`decide`) — মাল আটকে যায়, আর সেটা ফেরানো সহজ নয়
 *
 * ⚠️ দুইটার চাবি আলাদা ([[QualityInspectionPolicy]]), ঠিক যে কারণে
 * গোনা ও মেনে নেওয়া আলাদা।
 */
class QualityInspectionController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use SortsLists;

    public function __construct(
        private readonly QualityInspectionService $inspections,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(QualityInspection::class, 'inspection'),

            /*
             * ⭐ রায়ের চাবি আলাদা।
             *
             * ⚠️ `resourcePermissions()` সাতটা পদ্ধতি চেনে, `decide`
             * তাদের একটাও নয়। ⛔ এই লাইনটা না থাকলে রুটটা **খোলা**
             * থাকত, আর যে কেউ মাল বাতিল করে দিতে পারত।
             */
            new Middleware('can:decide,inspection', only: ['decide']),
        ];
    }

    public function index(Request $request): View
    {
        $query = QualityInspection::query()
            ->with(['product', 'warehouse', 'inspector']);

        $dates = $this->applyDateRange($query, $request, 'inspected_on');

        $sort = $this->applySort($query, $request, [
            /*
             * ⭐ যেগুলোর রায় বাকি, আগে — ডিফল্ট।
             *
             * ⓘ এই তালিকার আসল প্রশ্ন *"কোন মালটা এখনো দেখা বাকি"*,
             * ⚠️ কারণ ততক্ষণ ঐ মাল বিক্রির বাইরে পড়ে থাকে, আর সেটা
             * টাকার ক্ষতি — শুধু কাগজের দেরি নয়।
             */
            'pending' => fn ($q) => $q
                ->orderByRaw("CASE WHEN status = '".QualityInspection::PENDING."' THEN 0 ELSE 1 END")
                ->orderByDesc('inspected_on')->orderByDesc('id'),
            'recent' => fn ($q) => $q->orderByDesc('inspected_on')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('inspected_on')->orderBy('id'),
        ]);

        return view('inventory::quality.index', [
            'menu' => $this->menu->forUser($request->user()),
            'inspections' => $query->paginate(50)->withQueryString(),
            'dates' => $dates,
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('inventory::quality.form', [
            'menu' => $this->menu->forUser($request->user()),
            ...$this->formData(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = CompanyContext::id();

        $data = $request->validate([
            'product_id' => ['required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],
            'inspected_on' => ['required', 'date', 'before_or_equal:today'],
            'inspected_qty' => ['required', 'numeric', 'gt:0'],
            'batch_no' => ['nullable', 'string', 'max:60'],
            'expiry_date' => ['nullable', 'date'],
            'criteria' => ['nullable', 'string', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $inspection = $this->inspections->open($data);

        return redirect()
            ->route('inventory.qc.show', $inspection)
            ->with('saved', __('inventory::message.qc_opened'));
    }

    public function show(Request $request, QualityInspection $inspection): View
    {
        $inspection->load(['product.unit', 'batch', 'warehouse', 'inspector']);

        return view('inventory::quality.show', [
            'menu' => $this->menu->forUser($request->user()),
            'inspection' => $inspection,
        ]);
    }

    /**
     * ⭐ রায় — এখানেই মাল আটকায়।
     *
     * ⛔ গৃহীত + বাতিল = পরিদর্শিত, আর যোগফলটা সেবা মেলায়
     * ([[QualityInspectionService::decide()]])। ⚠️ এখানে আবার মেলালে
     * দুই জায়গায় দুই নিয়ম হত, আর একদিন একটা বদলাত।
     */
    public function decide(Request $request, QualityInspection $inspection): RedirectResponse
    {
        $data = $request->validate([
            'result' => ['required', 'string', Rule::in([
                QualityInspection::APPROVED,
                QualityInspection::QUARANTINE,
                QualityInspection::REJECTED,
            ])],
            'accepted_qty' => ['required', 'numeric', 'min:0'],
            'rejected_qty' => ['required', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->inspections->decide(
            inspection: $inspection,
            result: $data['result'],
            acceptedQty: (string) $data['accepted_qty'],
            rejectedQty: (string) $data['rejected_qty'],
            remarks: $data['remarks'] ?? null,
        );

        return redirect()
            ->route('inventory.qc.show', $inspection)
            ->with('saved', __('inventory::message.qc_decided'));
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),

            /*
             * ⭐ কেবল যে পণ্যে পরিদর্শন লাগে বলে বলা আছে।
             *
             * ⛔ সব পণ্য দেখালে তালিকাটা হাজার সারির হত, আর মালিকের
             * সিদ্ধান্তটাই (*"পণ্য ধরে ধরে চালু"*) অর্থহীন হয়ে যেত।
             * ⚠️ একটাও না থাকলে পর্দা খালি দেখায়, আর সেটাই সঠিক
             * বার্তা: *"আগে পণ্যে টিক দিন"*।
             */
            'products' => Product::query()->active()->where('qc_required', true)
                ->with('unit')->orderBy('name_en')->get(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function sortLabels(): array
    {
        return [
            'pending' => __('inventory::sort.qc_pending'),
            'recent' => __('inventory::sort.recent'),
            'oldest' => __('inventory::sort.oldest'),
        ];
    }
}
