<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\User;
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
        private readonly DocumentApproval $approvals,
        private readonly CashOnHand $cash,
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

            // ⛔ টিলের খাতে তালা, তারপর জের — চূড়ান্ত অডিট ⛔৭ ([[TwoTransfersEmptiedOneTillTest]])।
            // ⓘ তালা ছাড়া দুইজন একসাথে পাঠালে দুইজনেই পুরো জের দেখতেন, আর টিল শূন্যের নিচে নামত।
            $trxDate = Carbon::parse($data['trx_date'] ?? now());

            $this->cash->lock($from->account);
            $this->assertEnoughInHand($from, $amount, on: $trxDate->toDateString());

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
             * ⭐ সই হস্তান্তরের আগে — Accounts-Finance অডিট ম৬, ৪ অক্টোবর ২০২৬ (৬৩-এর সিদ্ধান্ত: "কাজের আগে অনুমোদন")।
             *
             * ⛔ আগে পাঠানোর পা সাথে সাথে খাতায় বসত, আর সই চাওয়া হত গ্রহণের সময় — অর্থাৎ টাকা হাতবদল হয়ে যেত, সই পরে।
             * ⓘ এখন `accounts.transfer` ছক চালু থাকলে কাগজটা সইয়ের অপেক্ষায় থাকে: খাতায় কিছু বসে না, টাকা ড্রয়ারেই;
             * শেষ সইয়ে পাঠানোর পা বসে ([[finishSigned()]])। ছক বন্ধে আগের মতোই এখনই।
             */
            $held = $this->approvals->stopping(
                document: $transfer,
                module: 'accounts',
                action: 'transfer',
                amount: (string) $transfer->amount,
                reason: $transfer->narration,
            ) !== null;

            if ($held) {
                $transfer->forceFill(['status' => MoneyTransfer::AWAITING])->save();

                return $transfer;
            }

            $this->postSendLeg($transfer);

            return $transfer;
        });
    }

    /**
     * ⭐ প্রতিটা পা নিজের শাখায় — Accounts-Finance অডিট ম৬, ৪ অক্টোবর ২০২৬।
     *
     * ⛔ আগে দুই পা-ই কাগজের শাখায় (যিনি লিখলেন তাঁর) বসত: ধানমন্ডির বাক্স থেকে মিরপুরের বাক্সে টাকা গেলে দুই শাখার খাতাই
     * ভুল — ধানমন্ডির নগদ কমত না, মিরপুরের বাড়ত না, আর যে শাখার নামে বসল তার জের দুই দিকেই নড়ত। ⓘ এখন পাঠানো পা দাতার
     * শাখায়, গ্রহণ গ্রহীতার; "পথের টাকা" প্রতিটা শাখায় এক পাশ পায় আর কোম্পানি-স্তরে শূন্যে মেলে। শাখা না জানা গেলে কাগজেরটাই।
     */
    private function legBranch(mixed $branchId, MoneyTransfer $transfer): ?int
    {
        $branchId = $branchId !== null ? (int) $branchId : null;

        return $branchId ?: ($transfer->branch_id !== null ? (int) $transfer->branch_id : null);
    }

    /**
     * শেষ সইয়ের পরে — পাঠানোর পা বসে, কাগজ "গ্রহণের অপেক্ষায়" (ম৬; [[FinishTheAccountsPaperOnTheLastSignature]] ডাকে)।
     *
     * ⛔ সইয়ের অপেক্ষার মধ্যে টাকা খরচ হয়ে গিয়ে থাকলে থামে — নইলে টিল শূন্যের নিচে নামত। ⓘ সারি তালা দিয়ে অবস্থা আবার
     * পড়া: একই সইয়ের ঘটনা দুইবার এলে দ্বিতীয়বার কিছু হয় না।
     */
    public function finishSigned(MoneyTransfer $transfer): MoneyTransfer
    {
        return DB::transaction(function () use ($transfer) {
            $this->lockFresh($transfer);

            if (! $transfer->isAwaiting()) {
                return $transfer;
            }

            $from = $transfer->fromTill;
            $this->cash->lock($from->account);
            $this->assertEnoughInHand($from, (string) $transfer->amount, except: (int) $transfer->id,
                on: Carbon::parse($transfer->trx_date)->toDateString());

            $this->postSendLeg($transfer);
            $transfer->forceFill(['status' => DocumentStatus::DRAFT])->save();

            return $transfer->fresh();
        });
    }

    /**
     * প্রথম পা — টাকাটা টিল থেকে বেরিয়ে পথে ওঠে (ছক বন্ধে হস্তান্তরের মুহূর্তে, ছক চালু থাকলে শেষ সইয়ে)।
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
    private function postSendLeg(MoneyTransfer $transfer): void
    {
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
            // ⛔ দাতার শাখায় — টাকা ঐ শাখার ড্রয়ার থেকে বেরোল (অডিট ম৬, [[legBranch()]])
            branchId: $this->legBranch($transfer->fromTill?->branch_id, $transfer),
        );
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
        $this->assertReceivable($transfer);
        $this->assertMayReceive($transfer);

        $destination = $transfer->destinationAccountId();

        if ($destination === null) {
            throw ValidationException::withMessages([
                'to_till_id' => __('accounts::validation.transfer_no_destination'),
            ]);
        }

        /*
         * ⭐ অনুমোদন — মালিকের সিদ্ধান্ত, ১৮ সেপ্টেম্বর ২০২৬।
         *
         * মালিকের কথা: *"এখন সব জায়গায় এপ্রুভাল দিয়ে টেস্ট কর, পরে যে
         * যে জায়গায় লাগবে না তাও উঠিয়ে দিব"*।
         *
         * ⚠️ সারিটা কারো আজকের কাজ থামায় না: ছক না বসানো পর্যন্ত
         * `assertClear()` চুপচাপ ফিরে যায়, আর কাজ আগের মতোই চলে।
         */
        $this->approvals->assertClear(
            document: $transfer,
            module: 'accounts',
            action: 'transfer',
            field: 'status',
            amount: (string) $transfer->amount,
            reason: $transfer->narration,
        );

        return DB::transaction(function () use ($transfer, $destination, $receivedBy) {
            // ⛔ সারিতে তালা দিয়ে অবস্থা আবার — পুরনো কপিতে দ্বিতীয় "গ্রহণ" খাতার দরজায় ভাঙত (⛔৭)
            $this->lockFresh($transfer);
            $this->assertReceivable($transfer);

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
                // ⛔ গ্রহীতার বাক্সের শাখায় (অডিট ম৬); ব্যাংকের খাত কোম্পানির, তাই ব্যাংকে জমা দাতার শাখাতেই — পথের টাকা সেখানেই মেলে
                branchId: $this->legBranch($transfer->toTill?->branch_id ?? $transfer->fromTill?->branch_id, $transfer),
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

        $this->assertNotCancelled($transfer);

        return DB::transaction(function () use ($transfer, $reason) {
            // ⛔ সারিতে তালা দিয়ে অবস্থা আবার — একই বাতিল দুইবার বিপরীত দাখিলা বসাত না, ভাঙত (⛔৭)
            $this->lockFresh($transfer);
            $this->assertNotCancelled($transfer);
            // ⓘ তালার পরে — অন্যজন এইমাত্র গ্রহণ করে থাকলে পাঠানো ব্যক্তি আর ফেরাতে পারেন না
            $this->assertMayCancel($transfer);

            /*
             * দুইটা পা-ই ফেরাতে হয়, আর ক্রমটা উল্টো।
             *
             * নিশ্চিত হয়ে থাকলে দুইটা পোস্টিং হয়েছে: পাঠানো ও গ্রহণ।
             * কেবল গ্রহণেরটা ফেরালে টাকাটা "পথের টাকা" খাতে আটকে থাকত —
             * কারও হাতে নেই, অথচ খাতায় আছে, আর কেউ কোনোদিন খুঁজেও পেত
             * না। খসড়া অবস্থাতেও পাঠানোর পা-টা বসেছে, তাই সেটাও ফেরে।
             */
            if ($transfer->isConfirmed()) {
                /*
                 * ⛔ গ্রহণ উল্টালে টাকা গন্তব্য খাত থেকে বেরোয় — সেখানে টাকা থাকতে হবে (Accounts-Finance অডিট ম৩, ৪ অক্টোবর
                 * ২০২৬)। ⚠️ আগে গ্রহণকারী কাউন্টার টাকাটা খরচ করে ফেলার পরে স্থানান্তর বাতিল করলে কাউন্টার ঋণাত্মক হত।
                 * ⓘ কেবল পাঠানোর পা থাকলে (খসড়া) উল্টানো টাকা উৎসে ফেরায়, তাই সেখানে কিছু মাপার নেই।
                 */
                $this->assertDestinationStillHolds($transfer);

                $this->posting->reverse(
                    MoneyTransfer::drillSourceType(),
                    $transfer->id,
                    now()->toDateString(),
                    $reason,
                );
            }

            // ⓘ সইয়ের অপেক্ষায় থাকা কাগজের কোনো পা খাতায় বসেনি — ফেরানোরও কিছু নেই (ম৬)
            if (! $transfer->isAwaiting()) {
                $this->posting->reverse(
                    MoneyTransfer::drillSourceType().':sent',
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

        /*
         * ⭐ খাত হলে কেবল এই কোম্পানির চালু ব্যাংক খাত (গ৪, Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬) — পর্দার
         * তালিকার হুবহু ([[MoneyTransferController::options()]])।
         *
         * ⛔ আগে হাতে বানানো অনুরোধে "account:খরচের খাত" পাঠালে নগদ সই ছাড়াই খরচে বা সরবরাহকারীর খাতে বসত।
         */
        if (filled($toAccount)) {
            $account = Account::query()->whereKey((int) $toAccount)->first();

            if ($account === null || ! $account->isBank() || ! $account->is_active || $account->is_group) {
                throw ValidationException::withMessages([
                    'to_account_id' => __('accounts::validation.transfer_bank_only', ['account' => $account?->label() ?? '#'.$toAccount]),
                ]);
            }
        }
    }

    /**
     * হাতে যত আছে তার বেশি পাঠানো যায় না।
     *
     * এটাই একমাত্র জায়গা যেখানে নগদের উপর শক্ত বাধা আছে, আর সেটা
     * ইচ্ছাকৃত: হাতে না থাকা টাকা কেউ হাতে হাতে দিতে পারে না। ভাউচারে
     * বাধা নেই, কারণ সেখানে পুরনো তারিখের এন্ট্রি লেখা স্বাভাবিক।
     */
    /**
     * সারিটা তালা দিয়ে আবার পড়া — হাতের কপি বাসি হলে তাজা অবস্থা বসে
     * ([[DepositClaimService::lockPending()]]-এর ছাঁচ, ৩০ সেপ্টেম্বর ২০২৬)।
     */
    private function lockFresh(MoneyTransfer $transfer): void
    {
        $fresh = MoneyTransfer::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

        $transfer->setRawAttributes($fresh->getAttributes(), true);
    }

    private function assertReceivable(MoneyTransfer $transfer): void
    {
        // ⛔ সইয়ের আগে গ্রহণ নয় — টাকা তো এখনো দাতার ড্রয়ারে (ম৬)
        if ($transfer->isAwaiting()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.transfer_awaiting_signature'),
            ]);
        }

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
    }

    /**
     * ⭐ গ্রহণ দেন কেবল গ্রহীতা বাক্সের মালিক (গ৫, Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
     *
     * ⛔ আগে "গ্রহণ" চাবি থাকলেই যে কেউ পারতেন — পাঠানো ক্যাশিয়ার নিজেও। তাতে দুই ধাপের পুরো মানে হারাত: পথের
     * টাকা অন্যের বাক্সে বসত, অথচ সেই মানুষটা টাকা হাতে পাননি।
     *
     *  · বাক্সের মালিক বসানো থাকলে — কেবল তিনি।
     *  · মালিক না থাকলে, কিন্তু গ্রহীতার নাম লেখা থাকলে — কেবল তিনি।
     *  · ব্যাংকে জমা বা নামহীন বাক্স — চাবিওয়ালা যে কেউ, তবে পাঠানো ব্যক্তি নন (দুই হাতের নিয়ম)।
     *
     * ⓘ মালিক (super admin) সব পারেন — তাঁর ক্ষমতা কোনো সংশোধনে কমে না।
     */
    private function assertMayReceive(MoneyTransfer $transfer): void
    {
        $user = auth()->user();

        if ($user instanceof User && $this->isSuperAdmin($user)) {
            return;
        }

        $userId = (int) auth()->id();
        $expected = $transfer->toTill?->holder_id ?? ($transfer->to_till_id !== null ? $transfer->received_by : null);

        if ($expected !== null) {
            if ((int) $expected !== $userId) {
                throw ValidationException::withMessages([
                    'status' => __('accounts::validation.transfer_not_your_box'),
                ]);
            }

            return;
        }

        if (in_array($userId, array_map('intval', array_filter([$transfer->created_by, $transfer->given_by])), true)) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.transfer_sender_cannot_receive'),
            ]);
        }
    }

    /**
     * ⭐ গ্রহণের পরে বাতিল করেন কেবল যাঁর হাতে নগদ এখন (গ৫) — বাক্সের মালিক, গ্রহীতা বা যিনি গ্রহণ দিয়েছেন।
     *
     * ⛔ আগে পাঠানো ব্যক্তি "তৈরি" চাবি দিয়েই বাতিল করতেন: খাতায় টাকা তাঁর বাক্সে ফিরত, অথচ নগদ অন্যের হাতে।
     * গ্রহণের আগে পাঠানো ব্যক্তি আগের মতোই ফেরাতে পারেন — টাকা তখনো পথে, কারও হাতে ওঠেনি।
     */
    /** ⛔ গ্রহণ উল্টানোর আগে গন্তব্যে টাকাটা আছে কি না — ম৩ ([[CashOnHand]], পোস্টের নিয়মের হুবহু) */
    private function assertDestinationStillHolds(MoneyTransfer $transfer): void
    {
        $account = Account::query()->find($transfer->destinationAccountId());
        $cash = app(CashOnHand::class);

        if ($account === null || ! $cash->guards($account)) {
            return;
        }

        $cash->lock($account);
        $short = $cash->shortfall($account, (string) $transfer->amount, now()->toDateString());

        if ($short !== null) {
            throw ValidationException::withMessages([
                'cancel_reason' => __('accounts::validation.not_enough_money_in', [
                    'account' => $account->label(),
                    'held' => Money::format(bcsub((string) $transfer->amount, $short, 4)),
                    'amount' => Money::format((string) $transfer->amount),
                ]),
            ]);
        }
    }

    private function assertMayCancel(MoneyTransfer $transfer): void
    {
        if (! $transfer->isConfirmed()) {
            return;
        }

        $user = auth()->user();

        if ($user instanceof User && $this->isSuperAdmin($user)) {
            return;
        }

        $holders = array_map('intval', array_filter([
            $transfer->toTill?->holder_id,
            $transfer->received_by,
            $transfer->confirmed_by,
        ]));

        if (! in_array((int) auth()->id(), $holders, true)) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.transfer_cancel_only_receiver'),
            ]);
        }
    }

    private function isSuperAdmin(User $user): bool
    {
        return $user->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE);
    }

    private function assertNotCancelled(MoneyTransfer $transfer): void
    {
        if ($transfer->isCancelled()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.already_cancelled'),
            ]);
        }
    }

    /**
     * ⛔ সইয়ের অপেক্ষায় থাকা স্থানান্তরের টাকা "পাওয়া যায়" থেকে বাদ — ম৬। ⚠️ ওগুলোর পা এখনো খাতায় বসেনি, তাই জেরে টাকাটা
     * এখনো আছে; বাদ না দিলে একই টাকা অপেক্ষার মধ্যে আরেক জায়গায় পাঠানো যেত, আর দুই সই পড়লে টিল ঋণাত্মক।
     */
    private function assertEnoughInHand(CashTill $from, string $amount, ?int $except = null, ?string $on = null): void
    {
        $awaiting = (string) MoneyTransfer::query()
            ->where('from_till_id', $from->id)
            ->where('status', MoneyTransfer::AWAITING)
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->sum('amount');

        /*
         * ⛔ পেছনের তারিখের স্থানান্তর — Accounts-Finance অডিট ম৯, ৪ অক্টোবর ২০২৬। ⚠️ আগে কেবল আজকের জের দেখা হত; ১০
         * তারিখে ১,০০০ ছিল, ১২ তারিখে ৮০০ খরচ — আজ ২০০। ১১ তারিখের ৫০০-র স্থানান্তর তখন আজকের ২০০ দেখে থামত না যদি
         * জের বেশি থাকত, অথচ ১২ তারিখে বাক্স ঋণাত্মক হত। ⓘ এখন সেই তারিখ থেকে আজ পর্যন্ত **সবচেয়ে কম** জের মাপা হয়।
         */
        $inHand = bcsub($this->lowestFrom($from, $on), $awaiting ?: '0', 4);

        if (bccomp($amount, $inHand, 4) > 0) {
            throw ValidationException::withMessages([
                'amount' => __('accounts::validation.not_enough_in_hand', [
                    'have' => Money::format($inHand),
                ]),
            ]);
        }
    }

    /** বাক্সের সবচেয়ে কম জের — দেওয়া তারিখের শেষ থেকে আজ পর্যন্ত প্রতিটা দিনের শেষে (তারিখ না দিলে বা ভবিষ্যৎ হলে আজকেরটাই) */
    private function lowestFrom(CashTill $till, ?string $on): string
    {
        $today = $till->balance();

        if ($on === null || $on >= now()->toDateString()) {
            return $today;
        }

        $lowest = $running = $till->balance($on);

        $days = LedgerEntry::query()
            ->where('account_id', $till->account_id)
            ->where('trx_date', '>', $on)
            ->groupBy('trx_date')
            ->orderBy('trx_date')
            ->selectRaw('trx_date, COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n')
            ->pluck('n');

        foreach ($days as $change) {
            $running = bcadd($running, (string) $change, 4);
            $lowest = bccomp($running, $lowest, 4) < 0 ? $running : $lowest;
        }

        return bccomp($today, $lowest, 4) < 0 ? $today : $lowest;
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
