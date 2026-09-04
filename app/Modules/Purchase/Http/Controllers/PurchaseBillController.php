<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\FiltersByDate;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\DocumentStatus;
use App\Core\Support\ProcessBand;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Http\Requests\PurchaseBillRequest;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ক্রয় বিলের পর্দা।
 */
class PurchaseBillController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use SortsLists;

    public function __construct(
        private readonly PurchaseBillService $bills,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(PurchaseBill::class, 'bill'),
            new Middleware('can:purchase.bill.create', only: ['confirm']),
            new Middleware('can:purchase.bill.cancel', only: ['cancel']),
        ];
    }

    public function index(Request $request): View
    {
        $query = PurchaseBill::query()
            ->search($request->query('q'))
            ->with('supplier')
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
            'largest' => fn ($q) => $q->orderByDesc('total'),
            'supplier' => fn ($q) => $q->orderBy('supplier_id')->orderByDesc('trx_date'),
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

        return view('purchase::bill.index', [
            'menu' => $this->menu->forUser($request->user()),
            'bills' => $query->paginate(50)->withQueryString(),
            'totals' => [
                'rows' => (clone $totalled)->count(),
                'money' => (clone $totalled)->sum('total'),
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
                'purchase.bill.index',
                $request->except(['stage', 'page']),
                $stage !== '' ? $stage : null,
            ),
        ]);
    }

    public function create(Request $request): View
    {
        // চালান ধরে খোলা হলে লাইনগুলো আগে থেকে ভরা — যতটুকুর বিল এখনো
        // হয়নি ঠিক ততটুকু নিয়ে
        $receipt = $this->chosenReceipt($request);

        /*
         * আদেশ ধরেও খোলা যায় — মাল গ্রহণের কাগজ ছাড়াই।
         *
         * যে ডিপো GRN ব্যবহার করে না (আর Control Panel-এ ওই পর্দাটা বন্ধও
         * করা যায়), তার আদেশ এতদিন কখনো বিলে পৌঁছাতই না। এখন পৌঁছায়, আর
         * সারিগুলো আদেশের সারির সাথে জোড়া থাকে — নইলে "অপেক্ষমাণ আদেশ"
         * তালিকায় ওটা চিরকাল ঝুলে থাকত।
         *
         * দুইটা একসাথে দেওয়ার মানে নেই: চালান দেওয়া থাকলে সেটাই জেতে,
         * কারণ চালানই বেশি নির্দিষ্ট (কতটা সত্যিই এসেছে তা সে জানে)।
         */
        $order = $receipt === null ? $this->chosenOrder($request) : null;

        /*
         * আদেশ চাওয়া হয়েছিল, অথচ পাওয়া গেল না — কারণটা বলা হয়।
         *
         * ── কী ভাঙা ছিল ─────────────────────────────────────────────
         * `chosenOrder()` নীরবে `null` ফেরাত। ফলে খসড়া আদেশের আইডি
         * নিয়ে এলে ফর্মটা **সম্পূর্ণ খালি** আসত — সরবরাহকারী নেই,
         * লাইন নেই, কোনো বার্তাও নেই।
         *
         * আদেশের পাতা খসড়ায় লিংকটা দেখায় না, তাই ওখান থেকে এই
         * অবস্থায় পৌঁছানো যায় না। কিন্তু বুকমার্ক, ব্রাউজারের পেছনে
         * যাওয়া, বা কাউকে পাঠানো একটা লিংক — তিনটাতেই পৌঁছানো যায়।
         * আর তখন পর্দাটা দেখতে ভাঙা লাগে, অথচ নিয়মটা ঠিকই কাজ করছে।
         *
         * মালিকের রিপোর্ট, ২২ আগস্ট: "PO থেকে সরাসরি Bill-এ গেলে ফর্ম
         * খালি আসে"। নিশ্চিত আদেশে ভরে; খসড়ায় খালি — নীরবে।
         */
        $askedFor = $request->integer('purchase_order_id');
        $why = null;

        if ($receipt === null && $askedFor > 0 && $order === null) {
            $draft = PurchaseOrder::query()->find($askedFor);

            $why = $draft !== null
                ? __('purchase::message.order_not_confirmed', ['no' => $draft->document_no])
                : __('purchase::message.order_not_found');
        }

        return view('purchase::bill.form', [
            'menu' => $this->menu->forUser($request->user()),
            'bill' => new PurchaseBill(['trx_date' => now()->toDateString()]),
            'receipt' => $receipt,
            'order' => $order,
            'why' => $why,
            'receipts' => PurchaseReceipt::query()
                ->where('status', DocumentStatus::CONFIRMED)
                ->with('supplier')->orderByDesc('trx_date')->limit(200)->get(),
            ...$this->formData(),
        ]);
    }

    public function store(PurchaseBillRequest $request): RedirectResponse
    {
        $bill = $this->bills->create($request->documentData(), $request->lineData());

        return redirect()
            ->route('purchase.bill.show', $bill)
            ->with('saved', __('purchase::message.bill_created'));
    }

    public function show(Request $request, PurchaseBill $bill): View
    {
        $bill->load(['lines.product.unit', 'lines.receiptLine.receipt', 'supplier', 'creator']);

        return view('purchase::bill.show', [
            'menu' => $this->menu->forUser($request->user()),
            'bill' => $bill,
        ]);
    }

    public function edit(Request $request, PurchaseBill $bill): View
    {
        $bill->load(['lines.product', 'lines.receiptLine']);

        return view('purchase::bill.form', [
            'menu' => $this->menu->forUser($request->user()),
            'bill' => $bill,
            'receipt' => null,
            'receipts' => PurchaseReceipt::query()
                ->where('status', DocumentStatus::CONFIRMED)
                ->with('supplier')->orderByDesc('trx_date')->limit(200)->get(),
            ...$this->formData(),
        ]);
    }

    public function update(PurchaseBillRequest $request, PurchaseBill $bill): RedirectResponse
    {
        $this->bills->update($bill, $request->documentData(), $request->lineData());

        return redirect()
            ->route('purchase.bill.show', $bill)
            ->with('saved', __('purchase::message.bill_updated'));
    }

    public function confirm(PurchaseBill $bill): RedirectResponse
    {
        $this->bills->confirm($bill);

        return redirect()
            ->route('purchase.bill.show', $bill)
            ->with('saved', __('purchase::message.bill_confirmed'));
    }

    public function cancel(Request $request, PurchaseBill $bill): RedirectResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $this->bills->cancel($bill, $reason);

        return redirect()
            ->route('purchase.bill.show', $bill)
            ->with('saved', __('purchase::message.bill_cancelled'));
    }

    private function chosenReceipt(Request $request): ?PurchaseReceipt
    {
        $id = $request->integer('purchase_receipt_id');

        if ($id <= 0) {
            return null;
        }

        return PurchaseReceipt::query()
            ->where('status', DocumentStatus::CONFIRMED)
            ->with(['lines.product.unit', 'supplier'])
            ->find($id);
    }

    /**
     * নিশ্চিত করা একটা আদেশ, যেটার বিপরীতে সরাসরি বিল হবে।
     *
     * খসড়া আদেশ বাদ: সেটা এখনো সরবরাহকারীকে পাঠানোই হয়নি, তাই তার
     * বিপরীতে বিল আসার কথা নয়।
     */
    private function chosenOrder(Request $request): ?PurchaseOrder
    {
        $id = $request->integer('purchase_order_id');

        if ($id <= 0) {
            return null;
        }

        return PurchaseOrder::query()
            ->where('status', DocumentStatus::CONFIRMED)
            ->with(['lines.product.unit', 'supplier'])
            ->find($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'suppliers' => Supplier::query()->active()->orderBy('name_en')->get(),
            'products' => Product::query()->active()->with('unit')->orderBy('name_en')->get(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function sortLabels(): array
    {
        return [
            'recent' => __('purchase::sort.recent'),
            'oldest' => __('purchase::sort.oldest'),
            'largest' => __('purchase::sort.largest'),
            'supplier' => __('purchase::sort.supplier'),
        ];
    }
}
