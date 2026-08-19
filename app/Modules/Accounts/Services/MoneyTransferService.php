<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\FinancialYear;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\MoneyTransfer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * টাকা হস্তান্তর — দুই ধাপে, দুইটা পায়ে।
 *
 * ধাপ ১: দাতা "দিলাম" বলেন। টিল থেকে টাকা বেরিয়ে **পথের টাকা** খাতে
 *        ওঠে (১১০৩)।
 * ধাপ ২: গ্রহীতা "পেলাম" বলেন। পথ থেকে তাঁর খাতে যায়।
 *
 * ── কেন এক ধাপে নয় ─────────────────────────────────────────────────
 * এক ধাপে করলে দাতার "দিয়েছি" বলাই যথেষ্ট হত, আর টাকাটা সাথে সাথে
 * অন্যের হিসাবে চলে যেত — অথচ সে হয়তো এখনো পায়নি। পথে টাকা হারালে
 * তখন দুইজনেই বলত অন্যজনের কাছে, আর সিস্টেম গ্রহীতার পক্ষে সাক্ষ্য
 * দিত।
 *
 * ── কেন প্রথম ধাপেও খতিয়ানে বসে ─────────────────────────────────────
 * আগে প্রথম ধাপে কিছুই বসত না। দায়িত্বের দিক থেকে ঠিক ছিল, কিন্তু
 * ব্যালেন্সের দিক থেকে মিথ্যা: টাকাটা ড্রয়ার থেকে বেরিয়ে গেছে অথচ
 * টিলের ব্যালেন্স তা বলছিল না। ওই দিন নগদ গণনা করলে ঘাটতি দেখাত, আর
 * একই টাকা দুইবারও পাঠানো যেত।
 *
 * "পথের টাকা" খাতটা কারও হাতে নেই — সেটাই পুরো কথা। দায়িত্ব দলিলেই
 * থাকে: কে দিয়েছেন, কে পাবেন, দুইটাই লেখা।
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

            $transfer = MoneyTransfer::create([
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

            /*
             * প্রথম পা — টাকাটা টিল থেকে বেরিয়ে পথে ওঠে।
             *
             * ── কেন এখনই, গ্রহণের অপেক্ষায় নয় ────────────────────────
             * আগে প্রথম ধাপে খতিয়ানে কিছুই বসত না, যুক্তি ছিল "গ্রহণ
             * নিশ্চিত না হওয়া পর্যন্ত টাকাটা দাতার"। দায়িত্বের দিক থেকে
             * ঠিক, কিন্তু **ব্যালেন্সের দিক থেকে মিথ্যা**: টাকাটা ড্রয়ার
             * থেকে বেরিয়ে গেছে, অথচ টিলের ব্যালেন্স তা বলছিল না।
             *
             * ফল ছিল দুইটা:
             *   ১. ওই দিন নগদ গণনা করলে টিলে ঘাটতি দেখাত, আর
             *      হেফাজতকারী দায়ী হতেন এমন টাকার জন্য যেটা তিনি হাতে
             *      হাতে দিয়ে দিয়েছেন
             *   ২. একই টাকা দুইবার পাঠানো যেত — একই ৫,০০০ একই মিনিটে
             *      সিন্দুকে ও ব্যাংকে, দুইটাই সম্ভব দেখাত
             *
             * দায়িত্বটা হারায় না: টাকাটা গ্রহীতার খাতেও যায়নি, গেছে
             * "পথের টাকা" খাতে — যেটা কারও হাতে নেই, আর দলিলে দাতার
             * নাম লেখা আছে।
             */
            $this->posting->post(
                MoneyTransfer::drillSourceType().':sent',
                $transfer->id,
                $transfer->trx_date,
                [
                    ['account_id' => $this->transitAccount()->id,
                        'debit' => $transfer->amount, 'credit' => '0'],
                    ['account_id' => $transfer->fromTill->account_id,
                        'debit' => '0', 'credit' => $transfer->amount],
                ],
                documentNo: $transfer->document_no,
                branchId: $transfer->branch_id,
            );

            return $transfer;
        });
    }

    /**
     * "পথের টাকা" খাতটা — না থাকলে বলা হয়, নিঃশব্দে অন্য খাতে বসে না।
     *
     * পুরনো কোম্পানিতে ছকটা বসার আগে খাতটা নাও থাকতে পারে। তখন
     * হস্তান্তরটা আটকে যাওয়াই ঠিক: টাকা কোথায় গেল তা না জেনে খতিয়ানে
     * বসানোর চেয়ে থেমে যাওয়া ভালো।
     */
    private function transitAccount(): Account
    {
        $account = StandardChart::find(StandardChart::CASH_IN_TRANSIT);

        if ($account === null) {
            throw ValidationException::withMessages([
                'amount' => __('accounts::validation.no_transit_account', [
                    'code' => StandardChart::CASH_IN_TRANSIT,
                ]),
            ]);
        }

        return $account;
    }

    /**
     * গ্রহণের পা কোন তারিখে বসে — **গ্রহণের দিনে**, হস্তান্তরের দিনে নয়।
     *
     * ── কী ভাঙা ছিল ─────────────────────────────────────────────────
     * দুইটা পা-ই `trx_date`-এ বসত, অর্থাৎ হস্তান্তরের দিনেই। ফলে:
     *
     *   ১. "পথের টাকা" খাতটা **কোনোদিন কোনো তারিখে শূন্যের বেশি হত
     *      না** — একই দিনে ডেবিট আর ক্রেডিট। অথচ ওই খাতটার গোটা কাজই
     *      হলো "টাকাটা এখন পথে" বলা। ১০ তারিখে দেওয়া আর ১৪ তারিখে
     *      পাওয়া টাকা ১১, ১২, ১৩ তারিখে কারও হিসাবেই থাকত না — না
     *      দাতার, না গ্রহীতার, না পথের।
     *
     *   ২. আরও খারাপ: জুনের হস্তান্তর জুলাইয়ে গ্রহণ করা **অসম্ভব** হয়ে
     *      যেত। হিসাবরক্ষক ভ্যাটের জন্য জুন বন্ধ করলে গ্রহীতা আর
     *      কোনোদিন "পেলাম" বলতে পারতেন না — টাকাটা পথের খাতে চিরকাল
     *      আটকে থাকত, আর ওটা নামানোর কোনো পর্দাই নেই। ঠিক এভাবেই ধরা
     *      পড়েছে: দাতা ছিলেন মালিক (পেছনের তারিখের ছাড় আছে), গ্রহীতা
     *      হিসাবরক্ষক (নেই)।
     *
     * ── কেন হস্তান্তরের তারিখে মেঝে ─────────────────────────────────
     * গ্রহণ দেওয়ার আগে ঘটতে পারে না। ভবিষ্যতের তারিখে হস্তান্তর বসানো
     * থাকলে গ্রহণটা ওই দিনেই বসে, তার আগে নয়।
     */
    private function receiptDate(MoneyTransfer $transfer): string
    {
        $handover = Carbon::parse($transfer->trx_date);

        return Carbon::today()->lessThan($handover)
            ? $handover->toDateString()
            : Carbon::today()->toDateString();
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
            /*
             * দ্বিতীয় পা — পথ থেকে গন্তব্যে।
             *
             * উৎস এখানে দাতার টিল নয়, "পথের টাকা": প্রথম পায়েই টিল
             * থেকে টাকাটা বেরিয়ে গেছে। টিল লিখলে ওই টাকাটা দুইবার
             * বেরোত, আর দাতার ব্যালেন্স ঋণাত্মক হয়ে যেত।
             *
             * তারিখটাও হস্তান্তরের নয়, গ্রহণের — কারণ `receiptDate()`-এ।
             */
            $this->posting->post(
                MoneyTransfer::drillSourceType(),
                $transfer->id,
                $this->receiptDate($transfer),
                [
                    ['account_id' => $destination, 'debit' => $transfer->amount, 'credit' => '0'],
                    ['account_id' => $this->transitAccount()->id,
                        'debit' => '0', 'credit' => $transfer->amount],
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
            /*
             * দুইটা পা-ই ফেরাতে হয়, আর ক্রমটা উল্টো।
             *
             * নিশ্চিত হয়ে থাকলে দুইটা পোস্টিং হয়েছে: পাঠানো ও গ্রহণ।
             * কেবল গ্রহণেরটা ফেরালে টাকাটা "পথের টাকা" খাতে আটকে থাকত —
             * কারও হাতে নেই, অথচ খাতায় আছে, আর কেউ কোনোদিন খুঁজেও পেত
             * না। খসড়া অবস্থাতেও পাঠানোর পা-টা বসেছে, তাই সেটাও ফেরে।
             */
            if ($transfer->isConfirmed()) {
                $this->posting->reverse(
                    MoneyTransfer::drillSourceType(),
                    $transfer->id,
                    now()->toDateString(),
                    $reason,
                );
            }

            $this->posting->reverse(
                MoneyTransfer::drillSourceType().':sent',
                $transfer->id,
                now()->toDateString(),
                $reason,
            );

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
                    'have' => Money::format($inHand),
                ]),
            ]);
        }
    }

    private function amount(mixed $value): string
    {
        // টাকাটা খাতায় যাচ্ছে, পর্দায় নয় — তাই গোল করা bcmath-এ
        $amount = Money::round($value, 4);

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
