<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\FiltersByDate;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Purchase\Http\Requests\PaymentRequest;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * পরিশোধ — পর্দা।
 */
class PaymentController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use FiltersByDate;
    use SortsLists;

    public function __construct(
        private readonly PaymentService $service,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(Payment::class, 'payment'),
            new Middleware('can:purchase.payment.create', only: ['confirm']),
            new Middleware('can:purchase.payment.cancel', only: ['cancel']),
        ];
    }

    public function index(Request $request): View
    {
        $query = Payment::query()
            ->search($request->query('q'))
            ->with(['supplier', 'account'])
            // বাতিলগুলো লুকানো, মোছা নয় (নিয়ম ৫)
            ->when(! $request->boolean('cancelled'),
                fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED));

        $dates = $this->applyDateRange($query, $request);

        $sort = $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'largest' => fn ($q) => $q->orderByDesc('amount'),
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

        return view('purchase::payment.index', [
            'menu' => $this->menu->forUser($request->user()),
            'payments' => $query->paginate(50)->withQueryString(),
            'totals' => [
                'rows' => (clone $totalled)->count(),
                'money' => (clone $totalled)->sum('amount'),
            ],
            'q' => $request->query('q'),
            'dates' => $dates,
            'sort' => $sort,
            'sortOptions' => $this->sortLabels(),
            'showCancelled' => $request->boolean('cancelled'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('purchase::payment.form', [
            'menu' => $this->menu->forUser($request->user()),
            'payment' => new Payment(['trx_date' => now()->toDateString()]),
            'bill' => $this->chosenBill($request),
            ...$this->formData(),
        ]);
    }

    public function store(PaymentRequest $request): RedirectResponse
    {
        $document = $this->service->create($request->documentData(), $request->lineData());

        return redirect()
            ->route('purchase.payment.show', $document)
            ->with('saved', __('purchase::message.payment_created'));
    }

    public function show(Request $request, Payment $payment): View
    {
        $payment->load(['lines.bill', 'supplier', 'account', 'creator']);

        return view('purchase::payment.show', [
            'menu' => $this->menu->forUser($request->user()),
            'payment' => $payment,
        ]);
    }

    public function edit(Request $request, Payment $payment): View
    {
        $payment->load(['lines.bill']);

        return view('purchase::payment.form', [
            'menu' => $this->menu->forUser($request->user()),
            'payment' => $payment,
            'bill' => null,
            ...$this->formData(),
        ]);
    }

    public function update(PaymentRequest $request, Payment $payment): RedirectResponse
    {
        $this->service->update($payment, $request->documentData(), $request->lineData());

        return redirect()
            ->route('purchase.payment.show', $payment)
            ->with('saved', __('purchase::message.payment_updated'));
    }

    public function confirm(Payment $payment): RedirectResponse
    {
        $this->service->confirm($payment);

        return redirect()
            ->route('purchase.payment.show', $payment)
            ->with('saved', __('purchase::message.payment_confirmed'));
    }

    public function cancel(Request $request, Payment $payment): RedirectResponse
    {
        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $this->service->cancel($payment, $reason);

        return redirect()
            ->route('purchase.payment.show', $payment)
            ->with('saved', __('purchase::message.payment_cancelled'));
    }

    /** বিল ধরে খোলা হলে ওই বিলের বকেয়াটাই আগে থেকে বসে। */
    private function chosenBill(Request $request): ?PurchaseBill
    {
        $id = $request->integer('purchase_bill_id');

        if ($id <= 0) {
            return null;
        }

        return PurchaseBill::query()
            ->where('status', DocumentStatus::CONFIRMED)
            ->with('supplier')
            ->find($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        $moneyCodes = StandardChart::MONEY_PARENTS;

        return [
            'suppliers' => Supplier::query()->active()->orderBy('name_en')->get(),
            'accounts' => Account::query()->postable()
                ->whereIn('code', $moneyCodes)
                ->orWhereIn('parent_id', Account::query()->whereIn('code', $moneyCodes)->select('id'))
                ->orderBy('code')->get(),

            /*
             * যে বিলগুলোয় এখনো টাকা বাকি।
             *
             * পুরো শোধ হয়ে যাওয়া বিল তালিকায় রাখলে ব্যবহারকারীকে
             * প্রতিবার খুঁজে বের করতে হত কোনটায় এখনো বাকি — আর ভুল
             * করে শোধ হওয়া বিলে আবার টাকা বসানোর সুযোগ থাকত।
             */
            'openBills' => PurchaseBill::query()
                ->where('status', DocumentStatus::CONFIRMED)
                ->with('supplier')
                /*
                 * withPaid — নিচের ছাঁকনি ও পর্দার লেখা, দুইটাই এই অঙ্কটা
                 * চায়। এটা ছাড়া ২০০টা বিলের জন্য ছাঁকতে ২০০ আর দেখাতে
                 * আরও ২০০ কোয়েরি হত; মাপা পাতায় দেখা গেছে আটবার।
                 */
                ->withPaid()
                ->orderByDesc('trx_date')
                ->limit(200)
                ->get()
                ->filter(fn (PurchaseBill $bill) => bccomp($bill->dueAmount(), '0', 4) > 0)
                ->values(),
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
