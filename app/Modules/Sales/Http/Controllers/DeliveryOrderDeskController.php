<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Concerns\FiltersByDate;
use App\Core\Concerns\GrandTotals;
use App\Core\Concerns\SortsLists;
use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Services\DeliveryOrderService;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ⭐ অফিসের DO ডেস্ক — "DO Create & List" (মালিক, ২ অক্টোবর ২০২৬), মেনুর "ডেলিভারি অর্ডার (DO)" ভাঁজ।
 *
 * ⓘ আসল DO কাগজ ([[DeliveryOrder]]) — পুরনো "DO" পাতাটা ([[DeliveryOrderController]]) কাউন্টারের চালানের ধাপ দেখায়।
 * লেখা, জমা আর সুপারভাইজারের পরিমাণ-বদল সব [[DeliveryOrderService]]-এ; এখানে কেবল পর্দা। সুপারভাইজার এই পাতাতেই
 * পরিমাণ বদলান, তারপর অনুমোদন-বাক্সের একই দরজায় সই দেন বা ফেরত পাঠান — সইয়ের নিয়ম একটাই।
 * ⚠️ "কোন ডিলার কার" দেয়াল আসবে abos-bb-র [[DealerOwnership]] থেকে; ততক্ষণ কোম্পানি ও শাখার দেয়াল।
 */
class DeliveryOrderDeskController extends Controller implements HasMiddleware
{
    use FiltersByDate;
    use GrandTotals;
    use SortsLists;

    /**
     * ট্যাব → কোন অবস্থাগুলো। ⓘ "আংশিক" আর "ব্যাক" অবস্থা নয়, মজুদের ঘটনা — [[tab()]]-এ আলাদা শর্ত।
     *
     * @var array<string, list<string>|null>
     */
    public const TABS = [
        'all' => null,
        'drafts' => [DeliveryOrderStatus::DRAFT],
        'pending' => [DeliveryOrderStatus::SUBMITTED, DeliveryOrderStatus::SUPERVISOR_PENDING, DeliveryOrderStatus::ACCOUNTS_HELD],
        'moving' => [DeliveryOrderStatus::SUPERVISOR_APPROVED, DeliveryOrderStatus::ACCOUNTS_APPROVED, DeliveryOrderStatus::DEPOT_CHECK],
        // ⭐ মাল কিছু গেছে, পুরোটা নয় — মালিকের "Partial DO" ([[DeliveryOrderStock::consume()]])
        'partial' => null,
        // ⭐ মজুদের অভাবে বাকি — হিসাবের যাচাইয়ে stock_short ([[DeliveryOrderAccounts]], abos-86)
        'back' => null,
        'history' => [DeliveryOrderStatus::INVOICED, DeliveryOrderStatus::REJECTED, DeliveryOrderStatus::CANCELLED],
    ];

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly DeliveryOrderService $orders,
        private readonly ApprovalEngine $approvals,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.do.view', only: ['index', 'show']),
            new Middleware('can:sales.do.create', only: ['create', 'store', 'submit']),
            // ⓘ পরিমাণ-বদল: চাবি ছকের (ApprovalEngine::canDecide) — সার্ভিসের ভেতরে, [[DeliveryOrderService::setApprovedQuantities()]]
        ];
    }

    public function index(Request $request): View
    {
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'all';
        $term = trim((string) $request->query('q', ''));

        $query = $this->tab(DeliveryOrder::query(), $tab)
            ->with(['customer.location', 'warehouse'])
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('document_no', 'like', '%'.$term.'%')
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name_en', 'like', '%'.$term.'%')->orWhere('name_bn', 'like', '%'.$term.'%'))));

        $dates = $this->applyDateRange($query, $request);

        $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'largest' => fn ($q) => $q->orderByDesc('total'),
            'customer' => fn ($q) => $q->orderBy('customer_id')->orderByDesc('trx_date'),
        ]);

        return view('sales::delivery_order.index', [
            'menu' => $this->menu->forUser($request->user()),
            'grand' => $this->grandTotals($query, ['total' => 't.total']),
            'orders' => $query->paginate(50)->withQueryString(),
            'tab' => $tab,
            'counts' => collect(self::TABS)->map(fn ($s, string $key) => $this->tab(DeliveryOrder::query(), $key)->count())->all(),
            'q' => $term,
            'dates' => $dates,
            'sortOptions' => [
                'recent' => __('sales::sort.recent'),
                'oldest' => __('sales::sort.oldest'),
                'largest' => __('sales::sort.largest'),
                'customer' => __('sales::sort.customer'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        return view('sales::delivery_order.form', [
            'menu' => $this->menu->forUser($request->user()),
            // ⓘ হেডারে বাছা শাখার গ্রাহকই — মালিক, ১ অক্টোবর ২০২৬: এক শাখার কিছু আরেক শাখায় নয়
            'customers' => Customer::query()->inViewedBranch()->active()->orderBy('name_en')->get(),
            'products' => Product::query()->where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_bn', 'code', 'sale_price']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'deliver_on' => ['nullable', 'date'],
            'narration' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0'],
        ]);

        // ⓘ ফর্মের খালি সারি বাদ — পরিমাণ দেওয়া সারিই লাইন; গ্রাহক দেয়ালের ভেতর থেকেই (অন্য কোম্পানির id ৪০৪)
        $lines = array_values(array_filter($data['lines'], fn ($l) => ! empty($l['product_id']) && is_numeric($l['qty'] ?? null) && bccomp((string) $l['qty'], '0', 4) > 0));
        $customer = Customer::query()->inViewedBranch()->findOrFail((int) $data['customer_id']);

        $order = $this->orders->create([
            'customer_id' => $customer->id,
            'deliver_on' => $data['deliver_on'] ?? null,
            'narration' => $data['narration'] ?? null,
        ], $lines, $request->user());

        if ($request->boolean('submit')) {
            $this->orders->submit($order, $request->user());
        }

        return redirect()->route('sales.delivery_order.show', $order)
            ->with('saved', __($request->boolean('submit') ? 'sales::delivery_order.submitted' : 'sales::delivery_order.saved'));
    }

    public function show(Request $request, DeliveryOrder $order): View
    {
        $order->load(['lines.product.unit', 'customer', 'warehouse', 'creator', 'dealerWriter']);
        $pending = $this->pendingApproval($order);

        return view('sales::delivery_order.show', [
            'menu' => $this->menu->forUser($request->user()),
            'order' => $order,
            'approval' => $pending,
            'awaitingMe' => $pending !== null && $this->approvals->canDecide($pending, $request->user()),
            'mayTouch' => $order->isEditableByWriter() && (int) $order->created_by === (int) $request->user()->id,
        ]);
    }

    public function submit(Request $request, DeliveryOrder $order): RedirectResponse
    {
        $this->orders->submit($order, $request->user());

        return redirect()->route('sales.delivery_order.show', $order)->with('saved', __('sales::delivery_order.submitted'));
    }

    /** সুপারভাইজারের পরিমাণ — {lines: {lineId: qty}}; চাবি ছকের, সার্ভিসের ভেতরে */
    public function quantities(Request $request, DeliveryOrder $order): RedirectResponse
    {
        $data = $request->validate(['lines' => ['required', 'array'], 'lines.*' => ['required', 'numeric', 'min:0']]);

        $this->orders->setApprovedQuantities($order, array_map('strval', $data['lines']), $request->user());

        return redirect()->route('sales.delivery_order.show', $order)->with('saved', __('sales::delivery_order.quantities_saved'));
    }

    /** @param  Builder<DeliveryOrder>  $query */
    private function tab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            /*
             * ⓘ চালানে যা বেরোল তা হোল্ডে `consumed_qty`; সুপারভাইজারের শেষ পরিমাণ `wanted_qty`।
             * কম বেরোলে (ডিপো কম দিল বা মজুদ কম ছিল) — আংশিক। বাতিল বা ফেরত DO নয়।
             */
            'partial' => $query->whereNotIn('status', [DeliveryOrderStatus::CANCELLED, DeliveryOrderStatus::REJECTED])
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('sal_do_stock_holds as h')
                    ->whereColumn('h.delivery_order_id', 'sal_delivery_orders.id')
                    ->where('h.company_id', CompanyContext::id())
                    ->where('h.consumed_qty', '>', 0)
                    ->whereColumn('h.consumed_qty', '<', 'h.wanted_qty')),
            /*
             * ⓘ চালু DO যার হিসাবের যাচাইয়ে কোনো লাইনের মজুদ কম পড়েছে — শূন্য বা আংশিক, দুটোই
             * (abos-86: `accounts_warnings` → kind `stock_short`)।
             */
            'back' => $query->whereNotIn('status', DeliveryOrderStatus::CLOSED)
                ->whereJsonContains('accounts_warnings', ['kind' => 'stock_short']),
            default => self::TABS[$tab] === null ? $query : $query->whereIn('status', self::TABS[$tab]),
        };
    }

    private function pendingApproval(DeliveryOrder $order): ?Approval
    {
        return $order->status === DeliveryOrderStatus::SUPERVISOR_PENDING
            ? $this->approvals->latestFor($order, DeliveryOrderService::APPROVAL_ACTION)
            : null;
    }
}
