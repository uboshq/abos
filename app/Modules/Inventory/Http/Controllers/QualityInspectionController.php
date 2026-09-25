<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Attachment\AttachmentException;
use App\Core\Concerns\FiltersByDate;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\QualityInspection;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\QualityInspectionService;
use App\Modules\MasterData\Models\ReasonCode;
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
        private readonly AttachmentEngine $attachments,
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
            new Middleware('can:decide,inspection', only: ['decide', 'dispose']),
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

            /*
             * ⓘ বিনাশের খাত বাছার তালিকা — কেবল সমন্বয়ের কারণগুলো।
             * ⚠️ আটকানোর কারণ দেখালে কেউ "দাম বাড়ার অপেক্ষায়" বেছে
             * ক্ষতিটা ভুল খাতে পাঠাতেন।
             */
            'writeOffReasons' => ReasonCode::query()
                ->where('context', ReasonCode::STOCK_ADJUSTMENT)
                ->orderBy('code')
                ->get(),

            /*
             * ⭐ সনদ ও ছবি — ২৫ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ রায়টা একটা দাবি; কাগজটা তার প্রমাণ। ⚠️ প্রমাণ ছাড়া
             * ছয় মাস পরে *"কেন বাতিল করা হয়েছিল"* প্রশ্নের উত্তর
             * থাকে কেবল একটা মন্তব্যের ঘরে, আর সরবরাহকারীর সাথে
             * তর্কে ওটা যথেষ্ট নয়।
             */
            'papers' => $this->attachments->listFor(
                'inventory',
                QualityInspection::PAPER_ENTITY,
                (int) $inspection->getKey(),
            ),
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

        $this->keepThePaper($request, $inspection);

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
     * ⭐ বাতিল মাল বিনাশ — ২৫ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ চাবিটা রায় দেওয়ারই (`decide`), নতুন কোনো চাবি নয় — ⚠️ বিনাশ
     * রায়েরই ধারাবাহিকতা, আলাদা কোনো ক্ষমতা নয়। ⛔ নতুন চাবি বানালে
     * কেউ একদিন রায় দিতে পারতেন অথচ নিজের রায় কার্যকর করতে পারতেন না,
     * আর তখন কাগজটা ঝুলে থাকত।
     */
    public function dispose(Request $request, QualityInspection $inspection): RedirectResponse
    {
        $data = $request->validate([
            'qty' => ['required', 'numeric', 'gt:0'],

            /*
             * ⛔ ক্ষতিটা কোন খাতে যাবে, সেটা ব্যবহারকারীর বাছাই —
             * ⓘ নষ্ট, চুরি আর মেয়াদোত্তীর্ণ এক খাতে যায় না, আর
             * খাতটা ঠিক করে কারণ কোড ([[ReasonCode::account]])।
             */
            'reason_code_id' => ['required', 'integer',
                Rule::exists('mdm_reason_codes', 'id')
                    ->where('company_id', CompanyContext::id())],

            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        $this->inspections->dispose(
            inspection: $inspection,
            qty: (string) $data['qty'],
            writeOff: ReasonCode::query()->findOrFail($data['reason_code_id']),
            narration: $data['narration'] ?? null,
        );

        return redirect()
            ->route('inventory.qc.show', $inspection)
            ->with('saved', __('inventory::message.qc_disposed'));
    }

    /**
     * ⭐ রায়ের সাথে আসা সনদ বা ছবি রেখে দেওয়া।
     *
     * ── ⚠️ কেন ব্যর্থতা রায়টাকে ফেলে দেয় না ─────────────────────────
     * ⛔ ফাইলটা বড়, বা ধরনটা অনুমোদিত নয় — এই দুইটা কারণেই
     * [[AttachmentEngine]] ব্যতিক্রম ছোড়ে। ⚠️ সেটা উপরে যেতে দিলে
     * **রায়টাই বসত না**, আর পরিদর্শক দেখতেন মাল এখনো অপেক্ষায় —
     * অথচ তিনি সিদ্ধান্ত দিয়ে ফেলেছেন।
     *
     * ⓘ তাই কাগজটা হারায়, রায়টা নয়, আর ব্যবহারকারী একটা সতর্কবার্তা
     * দেখেন। ⛔ নীরবে গিলে ফেলা হয় না: নাহলে তিনি ভাবতেন ছবিটা
     * উঠেছে, আর ছয় মাস পরে খুঁজতে গিয়ে পেতেন না।
     *
     * ⓘ নজিরটা [[DepositController::keepThePaper()]]-এর, হুবহু।
     */
    private function keepThePaper(Request $request, QualityInspection $inspection): void
    {
        if (! $request->hasFile('paper')) {
            return;
        }

        try {
            $this->attachments->store(
                file: $request->file('paper'),
                module: 'inventory',
                entity: QualityInspection::PAPER_ENTITY,
                entityId: (int) $inspection->getKey(),
            );
        } catch (AttachmentException $refused) {
            session()->flash('warning', __('core.attachment.refused', [
                'reason' => $refused->getMessage(),
            ]));
        }
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
