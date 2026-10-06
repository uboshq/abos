<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Support\DocumentStatus;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PurchaseBill;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ পরিশোধের প্রস্তাব (Payment Proposal) — মালিকের টাকা আসা-যাওয়ার আন্তর্জাতিক পরিকল্পনা, ধাপ খ ১১, ৭ অক্টোবর ২০২৬।
 *
 * ⓘ হিসাবরক্ষক মেয়াদি বা খোলা ক্রয়-বিল বাছেন, প্রতিটায় অঙ্ক বসান — এক চাপে প্রতিটা সরবরাহকারীর **একটা খসড়া পরিশোধ**,
 * বিলে ভাগসহ ([[PaymentService::create()]])। ⛔ খাতায় কিছুই বসে না: অনুমোদন আজকের পথেই (নিশ্চিত করার সময় 'payment'
 * নিয়ম, অঙ্কের সীমা ধরে — [[PaymentService::confirm()]]), আর টাকা দেন ক্যাশিয়ার, আজকের বোতামে।
 *
 * ⚠️ এক বিলে বাকির বেশি নয়; বাতিল বা খসড়া বিল নয়; একটা ভুল সারিতে কিছুই তৈরি হয় না (এক লেনদেন)।
 */
final class PaymentProposalService
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly \App\Core\Engines\NumberSeries\NumberSeriesEngine $numbers,
    ) {}

    /**
     * @param  array<int|string, string>  $picks  বিলের id => এই প্রস্তাবে কত
     * @return list<Payment> খসড়া পরিশোধ, সরবরাহকারী প্রতি একটা
     *
     * @throws ValidationException
     */
    public function propose(array $picks, int $accountId, Carbon|string|null $date = null, ?string $narration = null): array
    {
        $picks = array_filter($picks, fn ($amount) => is_numeric($amount) && bccomp((string) $amount, '0', 4) > 0);

        if ($picks === []) {
            throw ValidationException::withMessages(['bills' => __('purchase::validation.proposal_empty')]);
        }

        $date = Carbon::parse($date ?? now())->toDateString();

        return DB::transaction(function () use ($picks, $accountId, $date, $narration) {
            $bills = PurchaseBill::query()->whereKey(array_map('intval', array_keys($picks)))->lockForUpdate()->get()->keyBy('id');
            $bySupplier = [];

            foreach ($picks as $billId => $amount) {
                $bill = $bills->get((int) $billId);

                if ($bill === null || ! in_array($bill->status, DocumentStatus::POSTED, true)) {
                    throw ValidationException::withMessages(['bills' => __('purchase::validation.proposal_bill_not_open', ['no' => (string) ($bill?->document_no ?? $billId)])]);
                }

                $amount = bcadd((string) $amount, '0', 4);

                // ⛔ বাকির বেশি নয় — পরিশোধ আর পাকা ফেরত বাদে ([[PurchaseBill::dueAmount()]])
                if (bccomp($amount, $bill->dueAmount(), 4) > 0) {
                    throw ValidationException::withMessages(['bills' => __('purchase::validation.proposal_over_due', [
                        'no' => $bill->document_no,
                        'due' => \App\Core\Support\Money::format($bill->dueAmount()),
                    ])]);
                }

                $bySupplier[(int) $bill->supplier_id][] = ['purchase_bill_id' => (int) $bill->id, 'amount' => $amount];
            }

            // ⭐ প্রস্তাবের নিজের নম্বর (PP-…) — নম্বরধারী কাগজ, খসড়াগুলো তাকে চেনে
            $proposalNo = $this->numbers->next('PP');
            $made = [];

            foreach ($bySupplier as $supplierId => $lines) {
                $made[] = $this->payments->create([
                    'supplier_id' => $supplierId,
                    'account_id' => $accountId,
                    'trx_date' => $date,
                    'amount' => array_reduce($lines, fn (string $sum, array $l) => bcadd($sum, $l['amount'], 4), '0'),
                    'narration' => $narration ?? __('purchase::message.proposal_narration', ['no' => $proposalNo]),
                    'proposal_no' => $proposalNo,
                ], $lines);
            }

            return $made;
        });
    }
}
