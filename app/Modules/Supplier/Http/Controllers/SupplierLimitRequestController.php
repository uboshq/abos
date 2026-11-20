<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierLimitRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ⭐ সরবরাহকারীর পাতা থেকে বাকির সীমা বাড়ানোর অনুরোধ — সইয়ে যায়, শেষ সইয়ে নিজে বসে (মালিক, ১৮ নভেম্বর ২০২৬;
 * [[SupplierLimitRequests]])।
 *
 * ⓘ চাবি `supplier.update` (রুটে) — সীমা সরবরাহকারীর তথ্য, সম্পাদনার চাবিতেই; বাড়ানো চলে কেবল সইয়ে। দেয়াল — কোম্পানি আর
 * হেডারের শাখা, না মিললে ৪০৪।
 */
class SupplierLimitRequestController extends Controller
{
    public function __construct(private readonly SupplierLimitRequests $requests) {}

    public function store(Request $request, int $supplier): RedirectResponse
    {
        $supplier = Supplier::query()->inViewedBranch()->whereKey($supplier)->firstOrFail();

        $data = $request->validate([
            // ⛔ "1e5" `numeric` পার হয় — সেবা কেবল সাধারণ দশমিক নেয়, এখানেও
            'amount' => ['required', 'numeric', 'regex:/^\+?(\d+(\.\d*)?|\.\d+)$/', 'min:0', 'max:999999999999'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $made = $this->requests->raise($supplier, (string) $data['amount'], (string) $data['reason'], $request->user());

        return redirect()
            ->route('supplier.show', $supplier->id)
            ->with('saved', __($made->approval_id === null ? 'supplier::credit_request.saved_direct' : 'supplier::credit_request.saved'));
    }
}
