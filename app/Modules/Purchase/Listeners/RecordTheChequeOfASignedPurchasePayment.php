<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Listeners;

use App\Modules\Accounts\Events\VoucherPosted;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Purchase\Models\PurchaseBill;

/**
 * ⛔ সরাসরি কেনায় চেকে দেওয়া টাকা সইয়ের পরে চেক রেজিস্টারে ওঠে — fe, ১৩ অক্টোবর ২০২৬ (Purchase লাইন-ধরা নিরীক্ষা;
 * [[ADirectPurchaseChequeHeldForItsSignatureTest]])।
 *
 * ⚠️ সই চালু থাকলে [[DirectPurchaseService]] ভাউচারটা খসড়ায় রেখে ফিরত, চেক রেজিস্টারের ধাপে পৌঁছাতই না; শেষ সইয়ে ভাউচার পোস্ট হত
 * Accounts-এর শ্রোতায়, যে Purchase-এর চেক চেনে না। ফল: ২১১৫ (দেওয়া চেক) চিরকাল ক্রেডিট, ব্যাংক কখনো কমে না, আর চেকটা পাশ,
 * ফেরত বা বাতিল — কিছুই করা যায় না।
 *
 * ⭐ এখন যে কোনো পথে পোস্ট হোক — সরাসরি কেনার পরিশোধ ভাউচার (কাউন্টার, বিলের বিপরীতে, ২১১৫ ক্রেডিট) যার চেক রেজিস্টারে নেই, তার চেক
 * ওঠে। ⓘ সই ছাড়া পথে [[DirectPurchaseService]] নিজেই চেক তোলে (ব্যাংক খাতসহ) লেনদেনের ভিতরে, আর এই শ্রোতা চলে কমিটের পরে — তখন
 * চেক আছে, কিছুই হয় না। সইয়ের পথে ব্যাংক খাত ফাঁকা থাকে; পাশের সময় বেছে নেওয়া যায় ([[ChequeService]])।
 */
final class RecordTheChequeOfASignedPurchasePayment
{
    public function __construct(private readonly ChequeService $cheques) {}

    public function handle(VoucherPosted $event): void
    {
        if (($event->payload['type'] ?? null) !== Voucher::PAYMENT || ($event->payload['party_type'] ?? null) !== 'supplier') {
            return;
        }

        $voucher = Voucher::acrossBranches()->with('lines.account')->where('public_id', $event->publicId)->first();

        if ($voucher === null
            || $voucher->origin !== Voucher::ORIGIN_COUNTER
            || $voucher->against_type !== PurchaseBill::drillSourceType()
            || Cheque::acrossBranches()->where('voucher_id', $voucher->id)->exists()) {
            return;
        }

        $byCheque = $voucher->lines->contains(
            fn ($line) => $line->account?->code === StandardChart::CHEQUES_ISSUED && bccomp((string) $line->credit, '0', 4) > 0,
        );

        if (! $byCheque) {
            return;
        }

        $this->cheques->record([
            'direction' => Cheque::ISSUED,
            'voucher_id' => $voucher->id,
            'party_type' => 'supplier',
            'party_id' => $voucher->party_id,
            'cheque_no' => $voucher->instrument_no,
            'cheque_date' => $voucher->instrument_date?->toDateString() ?? $voucher->trx_date?->toDateString(),
            'bank_name' => $voucher->from_bank,
            'amount' => (string) $voucher->amount,
            'received_on' => $voucher->trx_date?->toDateString(),
            'narration' => $voucher->narration,
        ]);
    }
}
