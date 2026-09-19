<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\FiltersByDate;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Http\Requests\PurchaseReceiptRequest;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * মাল বুঝে নেওয়ার পর্দা।
 */
class PurchaseReceiptController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use SortsLists;

    public function __construct(
        private readonly PurchaseReceiptService $receipts,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(PurchaseReceipt::class, 'receipt'),
            new Middleware('can:purchase.receipt.create', only: ['confirm']),
            new Middleware('can:purchase.bill.create', only: ['bill']),
            new Middleware('can:purchase.receipt.cancel', only: ['cancel']),
        ];
    }

    public function index(Request $request): View
    {
        $query = PurchaseReceipt::query()
            ->search($request->query('q'))
            ->with(['supplier', 'warehouse'])
            ->when(! $request->boolean('cancelled'),
                fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED));

        $dates = $this->applyDateRange($query, $request);

        $sort = $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'largest' => fn ($q) => $q->orderByDesc('total'),
            'supplier' => fn ($q) => $q->orderBy('supplier_id')->orderByDesc('trx_date'),
        ]);

        return view('purchase::receipt.index', [
            'menu' => $this->menu->forUser($request->user()),
            'receipts' => $query->paginate(50)->withQueryString(),
            'q' => $request->query('q'),
            'dates' => $dates,
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
            'showCancelled' => $request->boolean('cancelled'),
        ]);
    }

    public function create(Request $request): View
    {
        /*
         * আদেশ ধরে খোলা হলে লাইনগুলো আগে থেকে ভরা থাকে — যা বাকি ঠিক
         * ততটুকু নিয়ে।
         *
         * গুদামের লোক ট্রাকের পাশে দাঁড়িয়ে এটা লেখেন। প্রতিটা লাইন হাতে
         * খুঁজে বের করতে বললে সময় লাগত, আর তাড়াহুড়োয় ভুল পণ্য বাছা হত।
         */
        $order = $this->chosenOrder($request);

        return view('purchase::receipt.form', [
            'menu' => $this->menu->forUser($request->user()),
            'receipt' => new PurchaseReceipt(['trx_date' => now()->toDateString()]),
            'order' => $order,
            'orders' => PurchaseOrder::query()->open()->with('supplier')
                ->orderByDesc('trx_date')->limit(200)->get(),
            ...$this->formData(),
        ]);
    }

    public function store(PurchaseReceiptRequest $request): RedirectResponse
    {
        $receipt = $this->receipts->create($request->documentData(), $request->lineData());

        return redirect()
            ->route('purchase.receipt.show', $receipt)
            ->with('saved', __('purchase::message.receipt_created'));
    }

    public function show(Request $request, PurchaseReceipt $receipt): View
    {
        $receipt->load(['lines.product.unit', 'supplier', 'warehouse', 'order', 'creator']);

        return view('purchase::receipt.show', [
            'menu' => $this->menu->forUser($request->user()),
            'receipt' => $receipt,

            // ⓘ এই মাল গ্রহণের বিল(গুলো) — বিল এখন আপনা থেকে হয়, তাই পাতাটা বলে কোনটা
            'bills' => PurchaseBill::query()
                ->where('status', '<>', DocumentStatus::CANCELLED)
                ->whereHas('lines', fn ($q) => $q->whereIn('purchase_receipt_line_id', $receipt->lines->pluck('id')))
                ->orderBy('id')
                ->get(['id', 'document_no', 'status']),

            // ⓘ কিছু অংশের বিল বাকি থাকলে তবেই "বিল তৈরি করুন"
            'unbilled' => $receipt->status === DocumentStatus::CONFIRMED
                && $receipt->lines->contains(fn ($line) => bccomp($line->unbilledQty(), '0', 4) > 0),
        ]);
    }

    public function edit(Request $request, PurchaseReceipt $receipt): View
    {
        $receipt->load(['lines.product', 'order.lines']);

        return view('purchase::receipt.form', [
            'menu' => $this->menu->forUser($request->user()),
            'receipt' => $receipt,
            'order' => $receipt->order,
            'orders' => PurchaseOrder::query()->open()->with('supplier')
                ->orderByDesc('trx_date')->limit(200)->get(),
            ...$this->formData(),
        ]);
    }

    public function update(PurchaseReceiptRequest $request, PurchaseReceipt $receipt): RedirectResponse
    {
        $this->receipts->update($receipt, $request->documentData(), $request->lineData());

        return redirect()
            ->route('purchase.receipt.show', $receipt)
            ->with('saved', __('purchase::message.receipt_updated'));
    }

    /**
     * মাল বুঝে নেওয়া নিশ্চিত — আর সাথে সাথে বিল (মালিকের নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬)।
     *
     * ⓘ বার্তাটা বলে বিলের কী হলো: নিশ্চিত, সইয়ের অপেক্ষায়, নাকি হয়নি
     * আর কেন। ⚠️ বিল না হলে মাল গ্রহণ ফেরে না — পাতায় "বিল তৈরি করুন"
     * বোতাম থাকে ([[bill()]])।
     */
    public function confirm(PurchaseReceipt $receipt): RedirectResponse
    {
        $result = $this->receipts->confirmAndBill($receipt);

        return $this->backWithBill($result['receipt'], $result, __('purchase::message.receipt_confirmed'));
    }

    /**
     * নিশ্চিত মাল গ্রহণের বাকি অংশের বিল — পুরনো মাল গ্রহণ, বা যেটার বিল আটকে গিয়েছিল।
     */
    public function bill(PurchaseReceipt $receipt): RedirectResponse
    {
        return $this->backWithBill($receipt, $this->receipts->billFor($receipt), null);
    }

    /**
     * @param  array{bill: ?PurchaseBill, held: bool, problem: ?string}  $result
     */
    private function backWithBill(PurchaseReceipt $receipt, array $result, ?string $lead): RedirectResponse
    {
        $bill = $result['bill'];

        $said = match (true) {
            $bill !== null && $result['held'] => __('purchase::message.auto_bill_held', ['no' => $bill->document_no]),
            $bill !== null && $result['problem'] === null => __('purchase::message.auto_bill_made', ['no' => $bill->document_no]),
            $bill !== null => __('purchase::message.auto_bill_draft', ['no' => $bill->document_no, 'why' => $result['problem']]),
            $result['problem'] !== null => __('purchase::message.auto_bill_failed', ['why' => $result['problem']]),
            default => __('purchase::message.auto_bill_nothing_left'),
        };

        return redirect()
            ->route('purchase.receipt.show', $receipt)
            ->with('saved', trim(($lead ?? '').' '.$said));
    }

    public function cancel(Request $request, PurchaseReceipt $receipt): RedirectResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $this->receipts->cancel($receipt, $reason);

        return redirect()
            ->route('purchase.receipt.show', $receipt)
            ->with('saved', __('purchase::message.receipt_cancelled'));
    }

    private function chosenOrder(Request $request): ?PurchaseOrder
    {
        $id = $request->integer('purchase_order_id');

        if ($id <= 0) {
            return null;
        }

        return PurchaseOrder::query()->open()->with(['lines.product.unit', 'supplier'])->find($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'suppliers' => Supplier::query()->active()->orderBy('name_en')->get(),
            'warehouses' => Warehouse::query()->active()->orderBy('code')->get(),
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
