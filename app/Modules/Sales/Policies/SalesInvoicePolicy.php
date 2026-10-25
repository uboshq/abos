<?php

declare(strict_types=1);

namespace App\Modules\Sales\Policies;

use App\Core\Engines\Approval\PaperSigners;
use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Sales\Models\SalesInvoice;

/**
 * SalesInvoice — কে কী পারে।
 *
 * অনুমতির নামগুলো module.php-তে ঘোষিত, আর ওখানেই একমাত্র তালিকা।
 */
class SalesInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.invoice.view');
    }

    public function view(User $user, SalesInvoice $document): bool
    {
        /*
         * ⭐ এই বিলের সইকারীও — কেবল পড়েন (fe, ২৪ অক্টোবর ২০২৬; [[VoucherPolicy::view()]]-এর একই নিয়ম): যিনি এই বিলে সই দেন বা
         * দিয়েছেন, আর যিনি এই বিলের টাকার কাগজে (কাউন্টারের জমার ভাউচার) সই দেন বা দিয়েছেন। ⛔ আগে সই হয়ে থেমে থাকা বিক্রির
         * খবর সইকারীকে বিলে পাঠাত ([[HeldCounterSaleFinisher]]), আর বিল দেখার চাবি না থাকলে ৪০৩ — যে বিক্রিতে সই দিলেন সেটাই
         * পড়তে পারতেন না। ⓘ অন্য বিল নয়, আর বদল/বাতিল নয়।
         */
        return $user->can('sales.invoice.view')
            || PaperSigners::isSigner($user, $document)
            || $this->signsItsMoney($user, $document);
    }

    /** এই বিলের টাকার কাগজের (বিলের বিপরীতে বসা ভাউচার) সইকারী কি না */
    private function signsItsMoney(User $user, SalesInvoice $invoice): bool
    {
        return Voucher::acrossBranches()
            ->where('against_type', SalesInvoice::drillSourceType())
            ->where('against_id', $invoice->getKey())
            ->get()
            ->contains(fn (Voucher $voucher): bool => PaperSigners::isSigner($user, $voucher));
    }

    public function create(User $user): bool
    {
        return $user->can('sales.invoice.create');
    }

    public function update(User $user, SalesInvoice $document): bool
    {
        // নিশ্চিত হওয়া ডকুমেন্ট আর বদলায় না — সেবা স্তরও আটকায়, কিন্তু
        // বোতামটা দেখানোই উচিত নয়
        return $user->can('sales.invoice.create')
            && $document->status === DocumentStatus::DRAFT;
    }

    public function delete(User $user, SalesInvoice $document): bool
    {
        return $user->can('sales.invoice.cancel');
    }
}
