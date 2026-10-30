<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierBankDetail;

/**
 * ⓘ দাবির প্রস্তুতি: সরবরাহকারীর ব্যাংক তথ্য দুই হাতে সই-পাওয়া — ব্যাংকে পরিশোধের বাস্তব পূর্বশর্ত (২৫ অক্টোবর ২০২৬)।
 *
 * ⓘ ভাউচারও এখন সই-না-পাওয়া ব্যাংকে ট্রান্সফার ফেরায় ([[AVoucherPaysOnlyASignedSupplierBankTest]]); যে দাবি অন্য কিছু মাপে
 * (চার্জের খাত, ফর্ম যা পাঠায়) কিন্তু সরবরাহকারীকে ব্যাংকে দেয়, তার প্রস্তুতিতে এটা লাগে — নাহলে দাবিটা ঠিক কারণেই থামত।
 */
trait SignsASupplierBank
{
    protected function supplierBankIsSigned(Supplier $supplier): SupplierBankDetail
    {
        // ⓘ লগইনের আগে প্রস্তুতিতে ডাকলেও চলে — তখন মালিক সই দেন
        $signer = auth()->id() ?? \App\Models\User::query()->where('email', 'owner@abos.test')->value('id');

        return SupplierBankDetail::query()->create([
            'company_id' => $supplier->company_id, 'supplier_id' => $supplier->id, 'bank_name' => 'Dutch-Bangla',
            'account_name' => (string) $supplier->name_en, 'account_no' => '1234567890', 'account_last4' => '7890',
            'status' => SupplierBankDetail::SIGNED, 'requested_by' => $signer, 'signed_by' => $signer, 'signed_at' => now(),
        ]);
    }
}
