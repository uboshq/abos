<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Core\Contracts\PayeeBankGuard;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Validation\ValidationException;

/**
 * ⛔ সরবরাহকারীর ব্যাংকে ট্রান্সফার বা নগদ জমা — তাঁর ব্যাংক তথ্য সই-পাওয়া না হলে নয়, ভাউচারেও ([[PayeeBankGuard]]; a4, ২৪ অক্টোবর ২০২৬)।
 *
 * ⓘ নিয়মটা এক জায়গায়: [[SupplierBank::assertBankPayable()]] — সরবরাহকারী-পরিশোধ ([[PaymentService]]) যা ডাকে, ভাউচারও সেটাই;
 * পদ্ধতির তালিকাও একই (ট্রান্সফার আর নগদ জমা)।
 */
final class SupplierPayeeBankGuard implements PayeeBankGuard
{
    /** যে পদ্ধতিতে টাকা সরবরাহকারীর ব্যাংকে ঢোকে — [[PaymentService]]-এর একই তালিকা */
    public const INTO_THEIR_BANK = ['transfer', 'deposit'];

    public function __construct(private readonly SupplierBank $banks) {}

    public function assertBankPayable(?string $partyType, ?int $partyId, ?string $method): void
    {
        if ($partyType !== 'supplier' || $partyId === null || ! in_array($method, self::INTO_THEIR_BANK, true)) {
            return;
        }

        $supplier = Supplier::query()->find($partyId);

        // ⓘ অচেনা বা অন্য কোম্পানির সরবরাহকারী — পক্ষের নিজের পাহারা ধরে; এখানে কিছু খোঁজা নয়
        if ($supplier === null) {
            return;
        }

        /*
         * ⓘ নিয়ম একই জায়গার; বার্তা ভাউচারের নিজের — কার ব্যাংক, আর কোন পাতায় গিয়ে বসাতে হবে (fe, ২৫ অক্টোবর ২০২৬: লাইভে
         * সই-না-পাওয়া ব্যাংকে ৯টা ভাউচার গিয়েছিল, পরেরটা থামলে লেখককে পথ দেখাতে হবে)। ⓘ লেখা ঠিকানা, লিংক নয় — ভুলের বাক্স আর
         * ফোন দুটোই কেবল লেখা দেখায়।
         */
        try {
            $this->banks->assertBankPayable($supplier);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['payee_bank' => __('supplier::bank.unsigned_blocks_voucher', [
                'supplier' => $supplier->name(), 'url' => route('supplier.show', $supplier->id),
            ])]);
        }
    }
}
