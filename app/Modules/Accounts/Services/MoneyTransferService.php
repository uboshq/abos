<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Models\FinancialYear;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\MoneyTransfer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * টাকা হস্তান্তর — দুই ধাপে।
 *
 * ধাপ ১: দাতা "দিলাম" বলে। কিছুই লেজারে বসে না।
 * ধাপ ২: গ্রহীতা "পেলাম" বলে। তখনই দুইটা এন্ট্রি বসে।
 *
 * এক ধাপে করলে দাতার "দিয়েছি" বলাই যথেষ্ট হত, আর টাকাটা সাথে সাথে
 * অন্যের হিসাবে চলে যেত — অথচ সে হয়তো এখনো পায়নি। পথে টাকা হারালে
 * তখন দুইজনেই বলত অন্যজনের কাছে, আর সিস্টেম গ্রহীতার পক্ষে সাক্ষ্য
 * দিত। দুই ধাপে টাকাটা গ্রহণ নিশ্চিত হওয়ার আগ পর্যন্ত দাতার হিসাবেই
 * থাকে, তাই দায়িত্ব নিয়ে অস্পষ্টতা থাকে না।
 */
final class MoneyTransferService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly PostingEngine $posting,
    ) {}

    /**
     * হস্তান্তর শুরু — দাতা পাঠাচ্ছে।
     *
     * @param  array<string, mixed>  $data
     */
    public function initiate(array $data): MoneyTransfer
    {
        return DB::transaction(function () use ($data) {
            $from = $this->till($data['from_till_id'] ?? null, 'from_till_id');

            $amount = $this->amount($data['amount'] ?? null);

            $this->assertDestination($data, $from);
            $this->assertEnoughInHand($from, $amount);

            $trxDate = Carbon::parse($data['trx_date'] ?? now());

            return MoneyTransfer::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'financial_year_id' => $this->year($trxDate)->id,
                'document_no' => $this->numbers->next('MT'),
                'trx_date' => $trxDate->toDateString(),
                'from_till_id' => $from->id,
                'to_till_id' => $data['to_till_id'] ?? null,
                'to_account_id' => $data['to_account_id'] ?? null,
                // দাতা ডিফল্টে যিনি লিখছেন, কিন্তু বদলানো যায়: ছুটির দিনে
                // টিলের মালিক না থাকলে অন্যজন হাতে হাতে দেয়
                'given_by' => $data['given_by'] ?? auth()->id(),
                'received_by' => $data['received_by'] ?? null,
                'amount' => $amount,
                'narration' => $data['narration'] ?? null,
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * গ্রহণ নিশ্চিত — এখনই টাকাটা হাত বদলায়।
     */
    public function confirm(MoneyTransfer $transfer, ?int $receivedBy = null): MoneyTransfer
    {
        if ($transfer->isConfirmed()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.transfer_already_confirmed'),
            ]);
        }

        if ($transfer->isCancelled()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.transfer_cancelled'),
            ]);
        }

        $destination = $transfer->destinationAccountId();

        if ($destination === null) {
            throw ValidationException::withMessages([
                'to_till_id' => __('accounts::validation.transfer_no_destination'),
            ]);
        }

        return DB::transaction(function () use ($transfer, $destination, $receivedBy) {
            $this->posting->post(
                MoneyTransfer::drillSourceType(),
                $transfer->id,
                $transfer->trx_date,
                [
                    // গন্তব্য বাড়ে, উৎস কমে — ভাউচারের twoLineEntry-র
                    // মতোই দিক, আর একই কারণে এক জায়গায় লেখা
                    ['account_id' => $destination, 'debit' => $transfer->amount, 'credit' => '0'],
                    ['account_id' => $transfer->fromTill->account_id, 'debit' => '0', 'credit' => $transfer->amount],
                ],
                documentNo: $transfer->document_no,
                branchId: $transfer->branch_id,
            );

            $transfer->forceFill([
                'status' => DocumentStatus::CONFIRMED,
                'received_by' => $receivedBy ?? $transfer->received_by ?? auth()->id(),
                'confirmed_by' => auth()->id(),
                'confirmed_at' => now(),
            ])->save();

            return $transfer->fresh();
        });
    }

    /**
     * বাতিল — পোস্ট হয়ে থাকলে বিপরীত এন্ট্রি দিয়ে (নিয়ম ৫)।
     */
    public function cancel(MoneyTransfer $transfer, string $reason): MoneyTransfer
    {
        if (blank($reason)) {
            throw ValidationException::withMessages([
                'cancel_reason' => __('accounts::validation.cancel_reason_required'),
            ]);
        }

        if ($transfer->isCancelled()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.already_cancelled'),
            ]);
        }

        return DB::transaction(function () use ($transfer, $reason) {
            if ($transfer->isConfirmed()) {
                $this->posting->reverse(
                    MoneyTransfer::drillSourceType(),
                    $transfer->id,
                    now()->toDateString(),
                    $reason,
                );
            }

            $transfer->forceFill([
                'status' => DocumentStatus::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ])->save();

            return $transfer->fresh();
        });
    }

    private function till(mixed $id, string $field): CashTill
    {
        $till = CashTill::query()->find($id);

        if ($till === null) {
            throw ValidationException::withMessages([
                $field => __('accounts::validation.till_not_found'),
            ]);
        }

        return $till;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertDestination(array $data, CashTill $from): void
    {
        $toTill = $data['to_till_id'] ?? null;
        $toAccount = $data['to_account_id'] ?? null;

        if (blank($toTill) && blank($toAccount)) {
            throw ValidationException::withMessages([
                'to_till_id' => __('accounts::validation.transfer_no_destination'),
            ]);
        }

        if (filled($toTill) && (int) $toTill === $from->id) {
            throw ValidationException::withMessages([
                'to_till_id' => __('accounts::validation.same_till_both_sides'),
            ]);
        }
    }

    /**
     * হাতে যত আছে তার বেশি পাঠানো যায় না।
     *
     * এটাই একমাত্র জায়গা যেখানে নগদের উপর শক্ত বাধা আছে, আর সেটা
     * ইচ্ছাকৃত: হাতে না থাকা টাকা কেউ হাতে হাতে দিতে পারে না। ভাউচারে
     * বাধা নেই, কারণ সেখানে পুরনো তারিখের এন্ট্রি লেখা স্বাভাবিক।
     */
    private function assertEnoughInHand(CashTill $from, string $amount): void
    {
        $inHand = $from->balance();

        if (bccomp($amount, $inHand, 4) > 0) {
            throw ValidationException::withMessages([
                'amount' => __('accounts::validation.not_enough_in_hand', [
                    'have' => number_format((float) $inHand, 2),
                ]),
            ]);
        }
    }

    private function amount(mixed $value): string
    {
        $amount = number_format((float) $value, 4, '.', '');

        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('accounts::validation.amount_must_be_positive'),
            ]);
        }

        return $amount;
    }

    private function year(Carbon $date): FinancialYear
    {
        $year = FinancialYear::forDate($date);

        if ($year === null) {
            throw ValidationException::withMessages([
                'trx_date' => __('accounts::validation.no_financial_year', ['date' => DateFormat::format($date)]),
            ]);
        }

        return $year;
    }
}
