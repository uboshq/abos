<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Services\CustomerPapers;
use App\Modules\Sales\Services\DeliveryOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ডিলারের DO — পোর্টালে নিজে লেখা আর জমা (মালিকের বিক্রয়-ধারা §২ক, ২ অক্টোবর ২০২৬)।
 *
 * ⛔ কেবল নিজের: সব কোয়েরি সরু পথে ([[CustomerPapers]]) — অন্যের DO খোঁজাতেই নেই, ৪০৪ (এক অভিনেতা দুবার);
 * লেখক জমার পরে আর বদলাতে পারেন না ([[DeliveryOrderService]]-এর নিয়ম, এখানে নয়)। দাম পণ্যের — ফর্মে দামের ঘর নেই।
 */
class PortalDeliveryOrderController extends Controller
{
    public function __construct(
        private readonly CustomerPapers $papers,
        private readonly DeliveryOrderService $orders,
    ) {}

    public function index(): View
    {
        $customer = $this->papers->customer();

        return view('sales::portal.do-index', [
            'customer' => $customer,
            'orders' => $this->papers->deliveryOrders(),
            'newStopped' => $this->orders->newOnesStopped(),
        ]);
    }

    public function create(): View|RedirectResponse
    {
        // ⭐ কোম্পানি বিক্রয় আদেশে চলে গেলে নতুন DO-র ফর্ম খোলে না (নকশার ধাপ ১২)
        if ($this->orders->newOnesStopped()) {
            return redirect()->route('sales.portal.do.index')
                ->withErrors(['order' => __('sales::delivery_order.write_an_order_now')]);
        }

        return view('sales::portal.do-form', [
            'customer' => $this->papers->customer(),
            'products' => $this->papers->orderableProducts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = $this->papers->customer();
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        // ⓘ ফর্মের খালি সারিগুলো বাদ — পরিমাণ দেওয়া সারিই লাইন
        $lines = array_values(array_filter($data['lines'], fn ($l) => ! empty($l['product_id']) && is_numeric($l['qty'] ?? null) && bccomp((string) $l['qty'], '0', 4) > 0));

        $order = $this->orders->create(['narration' => $data['narration'] ?? null], $lines, $customer);

        if ($request->boolean('submit')) {
            $this->orders->submit($order, $customer);
        }

        return redirect()->route('sales.portal.do.show', $order->public_id)
            ->with('status', __('sales::delivery_order.saved'));
    }

    public function show(string $id): View
    {
        $customer = $this->papers->customer();

        return view('sales::portal.do-show', ['customer' => $customer, 'order' => $this->papers->deliveryOrder($id)]);
    }

    public function submit(string $id): RedirectResponse
    {
        $customer = $this->papers->customer();
        $this->orders->submit($this->papers->deliveryOrder($id), $customer);

        return redirect()->route('sales.portal.do.show', $id)->with('status', __('sales::delivery_order.submitted'));
    }
}
