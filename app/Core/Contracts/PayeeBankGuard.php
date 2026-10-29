<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * ⛔ পক্ষের ব্যাংকে টাকা পাঠানোর আগে — তাঁর ব্যাংক তথ্য দুই হাতে সই-পাওয়া কি না (জালিয়াতি ঠেকানো; SAP vendor master dual control)।
 *
 * ── কেন কোরে একটা চুক্তি (a4, ২৪ অক্টোবর ২০২৬, লাইভ 95a60587b-এ ধরা) ─────
 * সরবরাহকারী-পরিশোধ ([[PaymentService]]) `SupplierBank::assertBankPayable()` ডাকত, কিন্তু হিসাবের **পরিশোধ ভাউচার** কখনো নয় —
 * ওয়েবে বা ফোনে ভাউচার দিয়ে সই-না-পাওয়া সরবরাহকারী-ব্যাংকে ট্রান্সফার চলে যেত। ⓘ ভাউচার হিসাবের, ব্যাংক তথ্য সরবরাহকারীর, আর
 * নির্ভরতার দিক `supplier → accounts` — তাই মাঝে এই চুক্তি ([[CreditHolds]]-এর ছাঁচ): সরবরাহকারী মডিউল বাস্তবায়ন দেয়
 * (`module.php`-র `bindings`), মডিউল বন্ধ থাকলে কোরের [[NoPayeeBankGuard]] কিছুই আটকায় না (তখন সরবরাহকারীই নেই)।
 */
interface PayeeBankGuard
{
    /**
     * পক্ষের ব্যাংকে এই পদ্ধতিতে টাকা যেতে পারে কি না — না পারলে ValidationException (ঘর `payee_bank`)।
     *
     * ⓘ কেবল যে পদ্ধতিতে টাকা পক্ষের ব্যাংকে ঢোকে (ট্রান্সফার, তাঁর হিসাবে নগদ জমা); নগদ, MFS আর চেকে প্রশ্ন নেই।
     */
    public function assertBankPayable(?string $partyType, ?int $partyId, ?string $method): void;
}
