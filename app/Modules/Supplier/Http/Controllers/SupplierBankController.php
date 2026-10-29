<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierBankDetail;
use App\Modules\Supplier\Services\SupplierBank;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ⭐ সরবরাহকারীর পাতার "ব্যাংক তথ্য" কার্ডের দরজা — চাওয়া, সই, না, ফিরিয়ে নেওয়া (নকশা দফা #৬, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ চাওয়া: `supplier.update` আর শাখার নাগাল ([[SupplierPolicy::update()]])।
 * ⓘ সই/না: `supplier.bank.approve`, নাগাল, আর চাওয়া মানুষটা নন — নিয়মটা সেবায় ([[SupplierBank::sign()]]), দরজায় নয়, যাতে
 * অন্য কোনো পথও (ফোন, কমান্ড) একই নিয়মে আটকায়।
 */
class SupplierBankController extends Controller
{
    public function __construct(private readonly SupplierBank $bank) {}

    public function store(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:120'],
            'branch_name' => ['nullable', 'string', 'max:120'],
            'account_name' => ['required', 'string', 'max:160'],
            'account_no' => ['required', 'string', 'max:40'],
            'routing_no' => ['nullable', 'regex:/^[0-9]{9}$/'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->bank->request($supplier, $data, $request->user());

        return redirect()->route('supplier.show', $supplier)->with('saved', __('supplier::bank.requested'));
    }

    public function sign(Request $request, Supplier $supplier, SupplierBankDetail $bank): RedirectResponse
    {
        $this->authorize('view', $supplier);
        abort_unless((int) $bank->supplier_id === (int) $supplier->id, 404);

        $this->bank->sign($bank, $request->user());

        return redirect()->route('supplier.show', $supplier)->with('saved', __('supplier::bank.signed'));
    }

    public function reject(Request $request, Supplier $supplier, SupplierBankDetail $bank): RedirectResponse
    {
        $this->authorize('view', $supplier);
        abort_unless((int) $bank->supplier_id === (int) $supplier->id, 404);

        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);

        $this->bank->reject($bank, $request->user(), $data['note']);

        return redirect()->route('supplier.show', $supplier)->with('saved', __('supplier::bank.rejected'));
    }

    public function withdraw(Request $request, Supplier $supplier, SupplierBankDetail $bank): RedirectResponse
    {
        $this->authorize('view', $supplier);
        abort_unless((int) $bank->supplier_id === (int) $supplier->id, 404);

        $this->bank->withdraw($bank, $request->user());

        return redirect()->route('supplier.show', $supplier)->with('saved', __('supplier::bank.withdrawn'));
    }
}
