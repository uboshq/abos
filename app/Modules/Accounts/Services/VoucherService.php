<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Contracts\SettledByAVoucher;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ভাউচার লেখা, পোস্ট করা ও বাতিল করা।
 *
 * পাঁচ ধরনের ফর্ম আলাদা, কিন্তু নিয়ম এক জায়গায়। DMS-এ প্রতিটা ভাউচারের
 * নিজের কন্ট্রোলারে নিজের পোস্টিং লেখা ছিল, আর সেই কারণেই "প্রতিটা কন্ট্রা
 * উল্টো দিকে বসত" — এক জায়গার ভুল, যা ধরা পড়েছিল অনেক পরে। এখানে
 * দিকটা একবারই ঠিক করা হয়, আর পাঁচটা ধরনই সেই একই পথে যায়।
 */
final class VoucherService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly PostingEngine $posting,
    ) {}

    /**
     * খসড়া হিসেবে তৈরি — লেজারে কিছুই বসে না।
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): Voucher
    {
        return DB::transaction(function () use ($data, $lines) {
            $type = $this->assertType($data['type'] ?? null);

            $trxDate = Carbon::parse($data['trx_date']);
            $year = $this->resolveFinancialYear($trxDate);

            // নম্বর ট্রানজেকশনের ভেতরে — সেভ ব্যর্থ হলে নম্বরটাও ফিরে যায়,
            // নাহলে প্রতিটা ব্যর্থ চেষ্টায় সিরিজে একটা ফাঁক থাকত
            $documentNo = $this->numbers->next(Voucher::DOC_TYPES[$type]);

            $voucher = Voucher::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'financial_year_id' => $year->id,
                'type' => $type,
                'document_no' => $documentNo,
                'trx_date' => $trxDate->toDateString(),

                /*
                 * ⚠️ প্রতিটা ঘর **হাতে লেখা**, `...$data` নয় — আর সেটাই
                 * এই মেথডের পাহারা: অনুরোধে যা-ই আসুক, খাতায় কেবল এই
                 * তালিকার ঘরগুলোই বসে। নতুন ঘর যোগ করলে এখানে এক লাইন
                 * লিখতেই হবে, আর সেই বাধ্যবাধকতাটাই ইচ্ছাকৃত।
                 *
                 * ⛔ ১৪ সেপ্টেম্বর ২০২৬-এ ঠিক এখানেই হোঁচট: মালিকের চাওয়া
                 * পাঁচটা নতুন ঘর যাচাইয়ে ছিল, ফর্মে ছিল, কলামও ছিল —
                 * কিন্তু এই তালিকায় না থাকলে সেগুলো নীরবে হারিয়ে যেত।
                 * `update()` স্প্রেড করে বলে সম্পাদনায় বসত, তৈরিতে নয় —
                 * অর্থাৎ ভুলটা ধরা পড়ত কেবল "নতুন ভাউচারে ব্যাংকের নাম
                 * থাকে না, কিন্তু এডিট করলে বসে" এই অদ্ভুত অভিযোগে।
                 */
                'ref_date' => $data['ref_date'] ?? null,
                'party_type' => $data['party_type'] ?? null,
                'party_id' => $data['party_id'] ?? null,
                'money_category_id' => $data['money_category_id'] ?? null,
                'money_subcategory_id' => $data['money_subcategory_id'] ?? null,
                'charge_amount' => $data['charge_amount'] ?? 0,

                /*
                 * ⭐ এই রসিদটা কোন নথি নিষ্পন্ন করছে।
                 *
                 * ⓘ অর্থ মডিউল থেকে "টাকা এসেছে" চাপলে রসিদের পর্দাটা
                 * এই দুইটা ঘর আগে থেকে ভরা অবস্থায় খোলে, আর পোস্ট হলে
                 * ঐ নথিটা আর খসড়া থাকে না — এক সত্যের একটাই উৎস।
                 */
                'against_type' => $data['against_type'] ?? null,
                'against_id' => $data['against_id'] ?? null,
                'narration' => $data['narration'] ?? null,
                'instrument' => $data['instrument'] ?? null,
                'instrument_no' => $data['instrument_no'] ?? null,
                'instrument_date' => $data['instrument_date'] ?? null,
                'from_bank' => $data['from_bank'] ?? null,
                'from_account_no' => $data['from_account_no'] ?? null,
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            $this->replaceLines($voucher, $lines);

            // ইস্যু করা নম্বরটা কোন ভাউচারে বসল — "RV-০০০৭ কার" প্রশ্নের উত্তর
            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update([
                    'source_type' => Voucher::SOURCE_TYPES[$type],
                    'source_id' => $voucher->id,
                ]);

            return $voucher->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(Voucher $voucher, array $data, array $lines): Voucher
    {
        $this->assertEditable($voucher);

        return DB::transaction(function () use ($voucher, $data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? $voucher->trx_date);

            $voucher->update([
                ...$data,
                // ধরন ও নম্বর কখনো বদলায় না: নম্বরটা সিরিজ থেকে এসেছে আর
                // ধরন বদলালে ওই সিরিজটাই ভুল হয়ে যেত
                'type' => $voucher->type,
                'document_no' => $voucher->document_no,
                'trx_date' => $trxDate->toDateString(),
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            $this->replaceLines($voucher, $lines);

            return $voucher->fresh(['lines']);
        });
    }

    /**
     * লেজারে বসানো।
     *
     * এখান থেকেই ভাউচারটা আসল হয়ে ওঠে — তার আগ পর্যন্ত সেটা শুধু একটা
     * খসড়া, কোনো হিসাবে নেই। PostingEngine নিজেই দেখে ডেবিট-ক্রেডিট
     * মিলছে কি না, বছর খোলা আছে কি না, আর আগে বসানো হয়েছে কি না।
     */
    public function post(Voucher $voucher): Voucher
    {
        if ($voucher->isPosted()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.already_posted', ['no' => $voucher->document_no]),
            ]);
        }

        if ($voucher->isCancelled()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.cancelled_cannot_post'),
            ]);
        }

        $voucher->load('lines.account');

        $this->assertLinesArePostable($voucher);
        $this->assertBankReferenceIsFree($voucher);

        /*
         * ── হেডারের পক্ষ কোন কোন সারিতে নামবে ────────────────────────
         *
         * ⚠️ আগে এখানে কোনো শর্ত ছিল না: `$line->party_type ??
         * $voucher->party_type` — অর্থাৎ **হেডারে পক্ষ থাকলে সেটা
         * প্রতিটা সারিতে বসত**।
         *
         * ⛔ উদ্দেশ্যটা ভালো ছিল — একটা সাধারণ ভাউচারে "কার সাথে
         * লেনদেন" একবার লিখলেই যেন সব সারিতে পৌঁছায়। কিন্তু ফলটা ছিল:
         *
         *     Dr ৫১০০  খরচের খাত   →  ⛔ সরবরাহকারীর নাম বসে যেত
         *     Cr ২১১১  প্রদেয়       →  ✅ ঠিক
         *     Cr ১১০১  নগদ          →  ⛔ নগদেরও একটা "মালিক" হয়ে যেত
         *
         * ⚠️ **নগদ বা খরচের খাত কারো নামে বসে থাকে না।** ওখানে পক্ষ
         * বসলে "এই সরবরাহকারীর সাথে লেনদেন" খুঁজলে **টাকার চলাচলও উঠে
         * আসত**, আর বকেয়ার অঙ্ক দ্বিগুণ দেখাত — একই টাকা একবার দেনার
         * সারিতে, আরেকবার নগদের সারিতে।
         *
         * ⭐ প্রশ্নটা "কোন পথে এল" নয়, **"এই খাতটা কি কারো নামে বসে
         * থাকে"**। পাওনা ও দেনা ছাড়া কোনো খাতের মালিক থাকে না — তাই
         * শর্তটা খাত ধরে, ভাউচারের ধরন ধরে নয়।
         *
         * ⓘ কেন এটা এতদিন ধরা পড়েনি: হেডারের পক্ষের ঘরটা কোনো পর্দা
         * ব্যবহার করত না (৪ সেপ্টেম্বর ২০২৬-এ মাপা — পক্ষসহ পোস্ট করা
         * ভাউচার **শূন্য**)। বাকিতে খরচের পর্দাই তার প্রথম ব্যবহারকারী,
         * আর সেদিনই সুপ্ত বাগটা জাগত।
         *
         * ⛔ শর্তটা তুলে দিলে ঠিক ওই তিন লাইনের ছবিটাই ফিরে আসবে।
         */
        $ownable = $this->accountsThatHoldAParty();

        return DB::transaction(function () use ($voucher, $ownable) {
            $this->posting->post(
                Voucher::SOURCE_TYPES[$voucher->type],
                $voucher->id,
                $voucher->trx_date,
                $voucher->lines->map(fn (VoucherLine $line) => [
                    'account_id' => $line->account_id,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                    'party_type' => $line->party_type
                        ?? (in_array((int) $line->account_id, $ownable, true)
                            ? $voucher->party_type
                            : null),
                    'party_id' => $line->party_id
                        ?? (in_array((int) $line->account_id, $ownable, true)
                            ? $voucher->party_id
                            : null),
                    'cost_center_id' => $line->cost_center_id,
                    'narration' => $line->narration ?? $voucher->narration,
                    'source_line_id' => $line->id,
                ])->all(),
                documentNo: $voucher->document_no,
                branchId: $voucher->branch_id,
            );

            $voucher->forceFill([
                'status' => DocumentStatus::CONFIRMED,
                'amount' => $voucher->totals()['debit'],
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ])->save();

            $this->settle($voucher, settled: true);

            return $voucher->fresh(['lines']);
        });
    }

    /**
     * যে নথির বিপরীতে এই ভাউচার, সেটাকে নিষ্পন্ন বা আবার খসড়া করা।
     *
     * ── ⭐ মালিকের স্থাপত্যগত সিদ্ধান্ত, ১৪ সেপ্টেম্বর ২০২৬ ───────────
     * *"অর্থে মূলধন লিখে হিসাবে রিসিভ করলেই তো সমাধান।"* অর্থ কেবল লেখে
     * "কে কত দেবেন"; টাকা গ্রহণ করে একাই রসিদের পর্দা। ⛔ কিন্তু ভাগটা
     * তখনই কাজ করে যখন রসিদ পোস্ট হলে অর্থের সারিটাও নিষ্পন্ন হয় —
     * নাহলে সারিটা চিরকাল খসড়া, আর এক সত্যের দুইটা উৎস।
     *
     * ── ⚠️ কেন ক্লাসটার নাম এখানে লেখা নেই ──────────────────────────
     * Accounts-এর `depends_on` ফাঁকা — বাকি সবাই তার উপর দাঁড়ায়। এখানে
     * `CapitalEntry` লিখলে চক্রাকার নির্ভরতা, আর [[BoundariesTest]]
     * ঠিকই ধরত।
     *
     * ⭐ তাই ধরনটা থেকে ক্লাসে পৌঁছানো হয় [[DrillResolver]] দিয়ে, ঠিক
     * যেভাবে খতিয়ানের সারি তার উৎস-নথি খুঁজে পায়। এই সেবা কেবল
     * [[SettledByAVoucher]] চুক্তিটা চেনে।
     *
     * ── ⓘ তিনটা নীরব পথ, আর তিনটাই ইচ্ছাকৃত ────────────────────────
     * ধরন নেই · ধরনটা কোনো মডিউল ঘোষণা করেনি · ক্লাসটা চুক্তিটা প্রয়োগ
     * করে না — তিন ক্ষেত্রেই কিছুই হয় না, ভাউচারটা স্বাভাবিকভাবে বসে।
     *
     * ⚠️ এটা ঝুঁকি বহন করে: ঘোষণা ভুলে গেলে হুকটা নীরবে কিছুই করবে না।
     * কিন্তু বিকল্পটা আরও খারাপ — হাতে খোলা একটা সাধারণ রসিদে `against_*`
     * খালি থাকে, আর সেখানে ছুঁড়লে রোজকার কাজই বন্ধ হত। তাই শর্তটা
     * চুক্তির ডকেই লেখা, যেখানে যিনি প্রয়োগ করবেন তিনি পড়বেন।
     */
    private function settle(Voucher $voucher, bool $settled): void
    {
        $type = (string) ($voucher->against_type ?? '');
        $id = (int) ($voucher->against_id ?? 0);

        if ($type === '' || $id <= 0) {
            return;
        }

        $class = app(DrillResolver::class)->map()[$type] ?? null;

        if ($class === null) {
            return;
        }

        /*
         * ⚠️ ক্লাসটাকে **দুইটাই** হতে হবে — একটা Eloquent মডেল আর
         * চুক্তিটার প্রয়োগকারী।
         *
         * ⓘ কেবল `is_subclass_of(..., SettledByAVoucher::class)` দেখে
         * `$class::query()` ডাকা যায় না: চুক্তিতে `query()` নেই, তাই
         * ওটা একটা সত্যিকারের ধরন-ভুল ছিল আর স্ট্যাটিক বিশ্লেষণ ঠিকই
         * ধরেছে। সারাইটা টীকা নয় — একটা **সত্যিকারের নমুনা** বানিয়ে
         * দুইটা শর্তই যাচাই করা, ঠিক যেভাবে
         * [[App\Core\Services\PartyRegistry::modelFor()]] করে।
         */
        $model = new $class;

        if (! $model instanceof Model || ! $model instanceof SettledByAVoucher) {
            return;
        }

        $document = $model->newQuery()->find($id);

        if (! $document instanceof SettledByAVoucher) {
            return;
        }

        $settled
            ? $document->settleWith((int) $voucher->id)
            : $document->unsettle((int) $voucher->id);
    }

    /**
     * বাতিল করা — বিপরীত এন্ট্রি দিয়ে, মুছে নয় (নিয়ম ৫)।
     *
     * মূল এন্ট্রিগুলো লেজারে থেকে যায়, আর তার পাশে সমান-উল্টো এন্ট্রি বসে।
     * মুছে দিলে ছাপা কাগজের নম্বরটা আর কোনো রেকর্ডের সাথে মিলত না, আর
     * অডিটে দেখা যেত একটা নম্বর ইস্যু হয়েছে কিন্তু কিছুই ঘটেনি।
     */
    public function cancel(Voucher $voucher, string $reason, ?string $onDate = null): Voucher
    {
        if ($voucher->isCancelled()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.already_cancelled'),
            ]);
        }

        if (blank($reason)) {
            throw ValidationException::withMessages([
                'cancel_reason' => __('accounts::validation.cancel_reason_required'),
            ]);
        }

        return DB::transaction(function () use ($voucher, $reason, $onDate) {
            // খসড়া কখনো লেজারে বসেনি, তাই ফেরানোরও কিছু নেই
            if ($voucher->isPosted()) {
                $this->posting->reverse(
                    Voucher::SOURCE_TYPES[$voucher->type],
                    $voucher->id,
                    $onDate ?? now()->toDateString(),
                    $reason,
                );
            }

            $voucher->forceFill([
                'status' => DocumentStatus::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                // ব্যাংক লেনদেনের নম্বরটা আবার খালি হয় — ভুল ভাউচার
                // বাতিল করে একই TrxID নিয়ে সঠিকটা তোলা রোজকার কাজ, আর
                // ধরে রাখলে ওই টাকাটা আর কখনো খাতায় উঠত না। নম্বরটা
                // `instrument_no`-তে থেকেই যায়, শুধু জোড়াটা ছাড়া পায়
                'money_account_id' => null,
            ])->save();

            /*
             * ⚠️ নথিটা আবার খসড়া — নাহলে ভুল করে কাটা একটা রসিদ বাতিল
             * করার পরেও অর্থের সারিটা "পাওয়া গেছে" বলে বসে থাকত, আর
             * টাকাটা দ্বিতীয়বার কেউ চাইত না।
             */
            $this->settle($voucher, settled: false);

            return $voucher->fresh(['lines']);
        });
    }

    /**
     * সহজ ফর্ম থেকে দুই লাইনের ভাউচার।
     *
     * আদায়, পরিশোধ, খরচ ও কন্ট্রা — চারটাই আসলে "এখান থেকে ওখানে"।
     * ব্যবহারকারী দুইটা খাত ও একটা অঙ্ক দেয়; কে ডেবিট আর কে ক্রেডিট
     * সেটা এখানে ঠিক হয়, একবারের জন্য।
     *
     * এই একটা জায়গাই DMS-এর কন্ট্রা-বাগটার উত্তর: ওখানে প্রতিটা পর্দায়
     * আলাদা করে দিক ঠিক করা হত, আর একটায় উল্টো লেখা ছিল।
     *
     * @return list<array<string, mixed>>
     */
    public function twoLineEntry(string $type, int $fromAccountId, int $toAccountId, string $amount, ?string $narration = null, ?string $charge = null): array
    {
        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('accounts::validation.amount_must_be_positive'),
            ]);
        }

        if ($fromAccountId === $toAccountId) {
            throw ValidationException::withMessages([
                'to_account_id' => __('accounts::validation.same_account_both_sides'),
            ]);
        }

        /*
         * "to" সবসময় ডেবিট, "from" সবসময় ক্রেডিট।
         *
         * টাকা যেখানে গেল সেটা বাড়ল (ডেবিট), যেখান থেকে এল সেটা কমল
         * (ক্রেডিট)। চারটা ধরনেই একই কথা:
         *
         *   আদায়   — টাকা এল গ্রাহক থেকে, গেল ক্যাশে
         *   পরিশোধ — টাকা এল ক্যাশ থেকে, গেল সরবরাহকারীর হিসাবে
         *   খরচ    — টাকা এল ক্যাশ থেকে, গেল খরচের খাতে
         *   কন্ট্রা  — টাকা এল ক্যাশ থেকে, গেল ব্যাংকে
         *
         * পর্দার লেবেল আলাদা হতে পারে, কিন্তু হিসাবটা এক।
         */
        if (bccomp($charge ?? '0', '0', 4) <= 0) {
            return [
                ['account_id' => $toAccountId, 'debit' => $amount, 'credit' => '0', 'narration' => $narration],
                ['account_id' => $fromAccountId, 'debit' => '0', 'credit' => $amount, 'narration' => $narration],
            ];
        }

        return $this->withCharge($toAccountId, $fromAccountId, $amount, (string) $charge, $narration);
    }

    /**
     * ব্যাংক বা MFS চার্জ কেটে রাখলে তিনটা সারি।
     *
     * ── ⭐ মালিকের নিয়ম, ১৪ সেপ্টেম্বর ২০২৬ ─────────────────────────
     * **যা পাঠানো হলো তাই মূলধন**, যা ঢুকল তা নয়।
     *
     *   ৮,০০০ পাঠালেন · ২০ কাটল
     *   → ব্যাংক ডেবিট ৭,৯৮০ · চার্জ ডেবিট ২০ · মূলধন ক্রেডিট ৮,০০০
     *
     * ⛔ চার্জটা আলাদা সারি না করলে দুইটা খারাপ পথের একটা নিতে হত: হয়
     * মূলধন ৭,৯৮০ লেখা (⚠️ তখন বিনিয়োগকারীর অংশ % ভুল, আর ওটা সোজা
     * মুনাফা ভাগের হিসাব), নয় চার্জটা কোথাও না লেখা (⚠️ তখন খাতা ২০
     * টাকা মিলত না)।
     *
     * @return list<array<string, mixed>>
     */
    private function withCharge(
        int $toAccountId,
        int $fromAccountId,
        string $amount,
        string $charge,
        ?string $narration,
    ): array {
        if (bccomp($charge, $amount, 4) >= 0) {
            throw ValidationException::withMessages([
                'charge_amount' => __('accounts::validation.charge_eats_the_whole_amount'),
            ]);
        }

        $into = Account::query()->find($toAccountId);

        /*
         * ⚠️ চার্জের খাতটা **কোডে হাতে লেখা নয়** — টাকা যে ধরনের খাতে
         * ঢুকছে তা থেকে আসে।
         *
         * ── কেন এই পার্থক্যটা রাখতেই হবে ────────────────────────────
         * ছকের মন্তব্যে কারণটা আগেই লেখা: **বিকাশ ক্যাশ-আউটে চার্জ কাটে,
         * ব্যাংক কাটে না**। দুইটা এক খাতে গেলে *"বিকাশে বছরে কত গেল"*
         * প্রশ্নের উত্তর আর বের করা যেত না — আর ডিপোর মালিকের কাছে
         * ওটাই বছরের সবচেয়ে দামি সংখ্যাগুলোর একটা।
         *
         * ⛔ নগদে চার্জ হয় না। কেউ নগদের খাতে চার্জ লিখলে সেটা নীরবে
         * ব্যাংক-চার্জের খাতে বসানোর চেয়ে থেমে যাওয়াই ভালো — নাহলে
         * ঐ খাতটায় এমন টাকা জমত যা কোনো ব্যাংক কোনোদিন কাটেনি।
         */
        $code = match (true) {
            $into?->isMfs() === true => StandardChart::MFS_CHARGES,
            $into?->isBank() === true => StandardChart::BANK_CHARGES,
            default => null,
        };

        if ($code === null) {
            throw ValidationException::withMessages([
                'charge_amount' => __('accounts::validation.charge_needs_a_bank_or_mfs'),
            ]);
        }

        $chargeAccount = StandardChart::find($code);

        if ($chargeAccount === null) {
            throw ValidationException::withMessages([
                'charge_amount' => __('accounts::validation.charge_account_missing', ['code' => $code]),
            ]);
        }

        return [
            [
                'account_id' => $toAccountId,
                'debit' => bcsub($amount, $charge, 4),
                'credit' => '0',
                'narration' => $narration,
            ],
            [
                'account_id' => (int) $chargeAccount->id,
                'debit' => $charge,
                'credit' => '0',
                'narration' => $narration,
            ],
            [
                'account_id' => $fromAccountId,
                'debit' => '0',
                'credit' => $amount,
                'narration' => $narration,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(Voucher $voucher, array $lines): void
    {
        $clean = [];

        foreach ($lines as $index => $line) {
            $accountId = (int) ($line['account_id'] ?? 0);

            if ($accountId === 0) {
                continue;
            }

            $debit = $this->money($line['debit'] ?? 0);
            $credit = $this->money($line['credit'] ?? 0);

            // দুই দিকেই শূন্য এমন সারি বাদ — ফর্মে খালি সারি রাখা
            // স্বাভাবিক, আর সেগুলো সেভ করলে ভাউচারে অর্থহীন লাইন জমত
            if (bccomp($debit, '0', 4) === 0 && bccomp($credit, '0', 4) === 0) {
                continue;
            }

            $clean[] = [
                'account_id' => $accountId,
                'party_type' => $line['party_type'] ?? null,
                'party_id' => $line['party_id'] ?? null,
                'cost_center_id' => $line['cost_center_id'] ?? null,
                'debit' => $debit,
                'credit' => $credit,
                'narration' => $line['narration'] ?? null,
                'sort_order' => $index,
            ];
        }

        if ($clean === []) {
            throw ValidationException::withMessages([
                'lines' => __('accounts::validation.no_lines'),
            ]);
        }

        $voucher->lines()->delete();
        $voucher->lines()->createMany($clean);
        $voucher->load('lines');

        $voucher->forceFill(['amount' => $voucher->totals()['debit']])->save();
    }

    /**
     * ব্যাংক বা MFS-এ গেলে লেনদেনের নম্বর লাগে, আর সেটা একবারই।
     *
     * ── কেন নিশ্চিত করার সময়, এন্ট্রির সময় নয় ───────────────────────
     * এন্ট্রির মুহূর্তে হিসাবরক্ষক লিখছেন "করিম স্টোরকে ৫০,০০০ দেব" —
     * বিকাশের TrxID তখনো **জন্মায়ইনি**। তখন বাধ্যতামূলক করলে মানুষ
     * `0` বা `-` বসিয়ে এগিয়ে যেতেন, আর **ভুল নম্বর কোনো নম্বর না
     * থাকার চেয়ে খারাপ**: ব্যাংক মেলানোর সময় ওটা দেখে সবাই ভাবে
     * মিলে গেছে।
     *
     * নিশ্চিতকরণ সেই মুহূর্ত যখন টাকা সত্যিই নড়ে। অনুমোদনের ধাপ থাকলে
     * সেটা এর আগেই ঘটে, তাই অনুমোদনকারীও নম্বরটা বসাতে পারেন — আর
     * থ্রেশহোল্ডের নিচে অনুমোদন না লাগলেও পাহারাটা থেকে যায়।
     *
     * ── নগদে চাওয়া হয় না, ব্যাংক ও MFS-এ হয় ─────────────────────────
     * নগদের কোনো TrxID নেই। চাইলে প্রতিটা নগদ ভাউচারে একটা বানানো
     * নম্বর বসত। ব্যাংকে চেক বা রেফারেন্স নম্বর থাকে, MFS-এ TrxID —
     * দুইটাই সত্যিকারের নম্বর, তাই দুইটাতেই চাওয়া হয়।
     *
     * ⛔ **আচরণ বদলায়নি।** পুরনো `is_bank` পতাকাটাই ব্যাংক ও MFS
     * দুইটাকে বোঝাত, তাই `[BANK, MFS]` লেখাটা সেই একই নিয়মের সৎ
     * বানান — নতুন কোনো নিয়ম নয়।
     *
     * ── কী আটকায়, আর কী আটকায় না ───────────────────────────────────
     * আটকায়: **একই ব্যাংক লেনদেন দুইবার খাতায় ওঠা** — হিসাবরক্ষক
     * তুললেন, ম্যানেজারও তুললেন। আটকায় না: একই বিলের বিপরীতে দুইটা
     * আলাদা পাঠানো — ওটা বিলের বরাদ্দের কাজ, আলাদা পাহারা।
     */
    private function assertBankReferenceIsFree(Voucher $voucher): void
    {
        $account = $voucher->lines
            ->map(fn (VoucherLine $line) => $line->account)
            ->first(fn (?Account $a) => $a !== null && ($a->isBank() || $a->isMfs()));

        // নগদ, বা টাকার কোনো ব্যাংক-খাত নেই (জাবেদা) — প্রশ্নই ওঠে না
        if ($account === null) {
            return;
        }

        $reference = trim((string) $voucher->instrument_no);

        if ($reference === '') {
            throw ValidationException::withMessages([
                'instrument_no' => __('accounts::validation.bank_reference_required', [
                    'account' => $account->label(),
                ]),
            ]);
        }

        $twin = Voucher::query()
            ->where('money_account_id', $account->id)
            ->where('instrument_no', $reference)
            ->whereKeyNot($voucher->id)
            ->first();

        if ($twin !== null) {
            throw ValidationException::withMessages([
                'instrument_no' => __('accounts::validation.bank_reference_used', [
                    'reference' => $reference,
                    'no' => $twin->document_no,
                ]),
            ]);
        }

        // খাতটা মাথায় বসে, কারণ অনন্যতার ইনডেক্সও মাথার উপর — লাইনে
        // বসালে দুই লাইনের ভাউচারে নিয়মটা কোন লাইনের তা বলা যেত না
        $voucher->forceFill(['money_account_id' => $account->id])->save();
    }

    /**
     * যে খাতগুলো কারো নামে বসে থাকে — পাওনা ও দেনার গোটা পরিবার।
     *
     * ── কেন কোড নয়, পরিবার ─────────────────────────────────────────
     * ছকটা ক্রেতা নিজে বাড়াতে পারেন, আর প্রদেয় ইতিমধ্যেই চার ঘরে ভাগ
     * হয়েছে (মালের সরবরাহকারী · পরিবহন · হাম্মালি · সেবা)। ⛔ একটা
     * কোড ধরে মেলালে ঠিক ওই ভাগ হওয়ার দিন নিয়মটা নিভে যেত — যেমন
     * `PAYABLE` ধরে যাচাই করতে গিয়ে একবার হয়েছিল।
     *
     * ⚠️ `selfAndDescendants()` কেবল `id` ও `parent_id` আনে — বাকি
     * ঘরগুলো `null`। তাই এখানে **কেবল id** মেলানো হয়; `code` বা
     * `is_group` দেখতে গেলে চারটা `null`-এর সাথে তুলনা করে নীরবে ভুল
     * উত্তর আসত।
     *
     * ⓘ গ্রুপ খাত তালিকায় থাকলেও ক্ষতি নেই — গ্রুপে দাখিলা বসেই না,
     * `assertLinesArePostable()` তার আগেই আটকায়।
     *
     * @return list<int>
     */
    private function accountsThatHoldAParty(): array
    {
        $ids = [];

        foreach ([StandardChart::RECEIVABLE, StandardChart::PAYABLE_GROUP] as $code) {
            $root = StandardChart::find($code);

            if ($root === null) {
                continue;
            }

            foreach ($root->selfAndDescendants() as $account) {
                $ids[] = (int) $account->id;
            }
        }

        return $ids;
    }

    /**
     * পোস্ট করার আগে শেষ যাচাই।
     *
     * PostingEngine ভারসাম্য দেখে, কিন্তু "গ্রুপ খাতে এন্ট্রি বসে না" ও
     * "নিষ্ক্রিয় খাতে নতুন এন্ট্রি নয়" — এগুলো হিসাবের ছকের নিয়ম, আর
     * সেগুলো এই মডিউলের দায়িত্ব।
     */
    private function assertLinesArePostable(Voucher $voucher): void
    {
        foreach ($voucher->lines as $line) {
            $account = $line->account;

            if ($account === null) {
                throw ValidationException::withMessages([
                    'lines' => __('accounts::validation.account_missing'),
                ]);
            }

            if ($account->is_group) {
                throw ValidationException::withMessages([
                    'lines' => __('accounts::validation.group_cannot_take_entries'),
                ]);
            }

            if (! $account->is_active) {
                throw ValidationException::withMessages([
                    'lines' => __('accounts::validation.inactive_account', ['name' => $account->label()]),
                ]);
            }
        }

        if (! $voucher->isBalanced()) {
            $t = $voucher->totals();

            throw ValidationException::withMessages([
                'lines' => __('accounts::validation.not_balanced', [
                    'debit' => Money::format($t['debit']),
                    'credit' => Money::format($t['credit']),
                ]),
            ]);
        }
    }

    private function assertEditable(Voucher $voucher): void
    {
        if (! $voucher->isEditable()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.posted_cannot_edit', ['no' => $voucher->document_no]),
            ]);
        }
    }

    private function assertType(mixed $type): string
    {
        if (! in_array($type, Voucher::TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => __('accounts::validation.unknown_voucher_type'),
            ]);
        }

        return $type;
    }

    /**
     * তারিখটা কোন অর্থবছরে পড়ে।
     *
     * PostingEngine নিজেও এটা করে, আর ওখানেই শেষ কথা। এখানে আগে করা
     * হয় শুধু একটা কারণে: খসড়া সংরক্ষণের সময়ও বছরটা জানা দরকার, আর
     * তখনো পোস্টিং হয়নি। ভুলের বার্তাটাও এখানে ব্যবহারকারীর ভাষায় আসে —
     * engine-এর বার্তা ডেভেলপারের জন্য লেখা।
     */
    private function resolveFinancialYear(Carbon $date): FinancialYear
    {
        $year = FinancialYear::forDate($date);

        if ($year === null) {
            throw ValidationException::withMessages([
                'trx_date' => __('accounts::validation.no_financial_year', ['date' => DateFormat::format($date)]),
            ]);
        }

        if ($year->is_closed) {
            throw ValidationException::withMessages([
                'trx_date' => __('accounts::validation.year_closed', ['year' => $year->name]),
            ]);
        }

        return $year;
    }

    /**
     * খতিয়ানে ঢোকার আগে টাকার রূপ ঠিক করা।
     *
     * ── কেন এটা দেখানোর ফরম্যাটিং নয় ────────────────────────────────
     * এই মানটা পর্দায় যায় না, **খাতায় যায়**। আগে এখানে
     * `number_format((float) $value, 4)` ছিল — অর্থাৎ ভাউচারের প্রতিটা
     * অঙ্ক ডেবিট-ক্রেডিট মেলানোর আগেই একবার float হয়ে আসত। যে জায়গাটা
     * সবচেয়ে বেশি নির্ভুলতা দাবি করে, ঠিক সেখানেই।
     */
    private function money(mixed $value): string
    {
        return Money::round($value, 4);
    }

    /**
     * টাকার খাতগুলো — আদায়, পরিশোধ ও কন্ট্রার ড্রপডাউনে যা দেখাবে।
     *
     * @return Collection<int, Account>
     */
    public function moneyAccounts(): Collection
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->get();
    }
}
