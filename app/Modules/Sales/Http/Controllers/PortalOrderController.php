<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\CustomerPapers;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\SalesOrderStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * ⭐ গ্রাহকের নিজের বিক্রয় আদেশ — পোর্টালে লেখা আর জমা (DO+SO মেশানো, নকশার ধাপ ৯, ৫ অক্টোবর ২০২৬)।
 *
 * ⓘ [[PortalDeliveryOrderController]]-এর যমজ: একই ফর্ম, একই নিয়ম — দাম পণ্যের, ফর্মে দামের ঘর নেই; জমার পরে আর বদল নেই।
 * জমা → বাকির যাচাই → সুপারভাইজার → অনুমোদিত, সবটা [[SalesOrderService::submit()]]-এ; সই চাওয়া গ্রাহকের নিজের নামে।
 * ⛔ কেবল নিজের: সব খোঁজ সরু পথে ([[CustomerPapers]]) — অন্যের আদেশ খোঁজাতেই নেই, ৪০৪ (এক অভিনেতা দুবার)।
 * ⚠️ নতুন আদেশ কেবল কোম্পানির সুইচ চালু থাকলে ([[SalesOrderService::REPLACES_DO]]); বন্ধে তালিকা আর দেখা চলে, লেখা নয়।
 */
class PortalOrderController extends Controller
{
    public function __construct(
        private readonly CustomerPapers $papers,
        private readonly SalesOrderService $orders,
    ) {}

    public function index(): View
    {
        return view('sales::portal.order-index', [
            'customer' => $this->papers->customer(),
            'orders' => $this->papers->salesOrders(),
            'canWrite' => $this->orders->replacesDo(),
        ]);
    }

    public function create(): View|RedirectResponse
    {
        if (! $this->orders->replacesDo()) {
            return redirect()->route('sales.portal.order.index')
                ->withErrors(['order' => __('sales::portal_order.not_open')]);
        }

        return view('sales::portal.order-form', [
            'customer' => $this->papers->customer(),
            'products' => $this->papers->orderableProducts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = $this->papers->customer();

        if (! $this->orders->replacesDo()) {
            throw ValidationException::withMessages(['order' => __('sales::portal_order.not_open')]);
        }

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        // ⓘ ফর্মের খালি সারি বাদ — পরিমাণ দেওয়া সারিই লাইন; দাম পণ্যের ([[CustomerPapers::orderableProducts()]]-এর একই দাম)
        $products = $this->papers->orderableProducts()->keyBy('id');
        $lines = [];
        foreach ($data['lines'] as $line) {
            $product = $products->get((int) ($line['product_id'] ?? 0));
            if ($product === null || ! is_numeric($line['qty'] ?? null) || bccomp((string) $line['qty'], '0', 4) <= 0) {
                continue;
            }
            $lines[] = [
                'product_id' => (int) $product->id,
                'ordered_qty' => (string) $line['qty'],
                // ⭐ এই ডিলারের দর তালিকার দাম, নাহলে পণ্যের ([[SalesPrice]], ৫ অক্টোবর ২০২৬)
                'rate' => app(\App\Modules\Sales\Services\SalesPrice::class)->for($customer, $product)->price,
                'discount' => '0',
            ];
        }

        $order = $this->orders->create([
            'customer_id' => $customer->id,
            'narration' => $data['narration'] ?? null,
            'source' => SalesOrderStatus::SOURCE_PORTAL,
            'created_by_customer_id' => $customer->id,
        ], $lines);

        if ($request->boolean('submit')) {
            $this->orders->submit($order);
        }

        return redirect()->route('sales.portal.order.show', $order->public_id)
            ->with('status', __($request->boolean('submit') ? 'sales::portal_order.submitted' : 'sales::portal_order.saved'));
    }

    public function show(string $id): View
    {
        $order = $this->papers->salesOrder($id);

        return view('sales::portal.order-show', [
            'customer' => $this->papers->customer(),
            'order' => $order,
            'canSubmit' => $this->isOwnDraft($order),
        ]);
    }

    public function submit(string $id): RedirectResponse
    {
        $order = $this->papers->salesOrder($id);

        // ⛔ কেবল নিজের লেখা খসড়া — অফিস বা SR-এর লেখা আদেশ গ্রাহক জমা দেন না
        if (! $this->isOwnDraft($order)) {
            abort(403);
        }

        $this->orders->submit($order);

        return redirect()->route('sales.portal.order.show', $id)->with('status', __('sales::portal_order.submitted'));
    }

    private function isOwnDraft(SalesOrder $order): bool
    {
        return $order->status === SalesOrderStatus::DRAFT
            && (int) $order->created_by_customer_id === (int) $this->papers->customer()->id;
    }
}
