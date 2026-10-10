<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Contracts\SettledByAVoucher;
use App\Core\Contracts\SettlementTerms;
use App\Core\Engines\Audit\AuditEngine;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\OpenPeriod;
use App\Core\Services\PermissionSyncer;
use App\Core\Services\RevisionKeeper;
use App\Core\Services\SettingsService;
use App\Core\Support\Actor;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\DocumentRevision;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Models\User;
use App\Modules\Accounts\Events\VoucherPosted;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankStatementLine;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\InterCompanyTransfer;
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
    // ⭐ লেখক ≠ পাকাকারী — অংশ ৩গ ([[writerMayNotPost()]])
    public const MAKER_CHECKER = 'accounts.voucher_maker_checker';

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
    public function create(array $data, array $lines, bool $byHand = false): Voucher
    {
        return DB::transaction(function () use ($data, $lines, $byHand) {
            $type = $this->assertType($data['type'] ?? null);

            $this->assertNoChequeReceived($type, $data['instrument'] ?? null);

            // ⓘ ধরনের ছাঁচ কেবল হাতে লেখা ভাউচারে — ব্যবস্থার নিজের পোস্টিং (নগদ গণনার ঘাটতি, আন্তঃকোম্পানি, ভাড়ার জমা…) তার সেবার ছাঁচ মানে
            if ($byHand) {
                $this->assertTemplate($type, $lines, [$data['party_type'] ?? null, $data['party_id'] ?? null]);
            }

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

                /*
                 * ⓘ কোথা থেকে এল — কেবল সেবা থেকে ভরা হয় (কাউন্টার)।
                 * ⚠️ ফর্মের যাচাইয়ে ঘরটা নেই, তাই কেউ হাতে পাঠিয়ে
                 * কাউন্টারের নিয়মে ঢুকতে পারে না।
                 */
                'origin' => $data['origin'] ?? null,
                'narration' => $data['narration'] ?? null,
                'instrument' => $data['instrument'] ?? null,
                'instrument_no' => $data['instrument_no'] ?? null,
                'instrument_date' => $data['instrument_date'] ?? null,
                'from_bank' => $data['from_bank'] ?? null,
                'from_account_no' => $data['from_account_no'] ?? null,

                /*
                 * ── ⛔ একই ভুল, দ্বিতীয়বার — ১৫ সেপ্টেম্বর ২০২৬ ─────────
                 * উপরের মন্তব্যটা ঠিক এই ফাঁদের কথাই বলেছিল, আর তবু
                 * ১৪ তারিখে যোগ হওয়া **বারোটা ঘরের একটাও** এই তালিকায়
                 * বসানো হয়নি।
                 *
                 * ⚠️ ফল হুবহু যা লেখা ছিল: ঘরগুলো ফর্মে আছে, যাচাইয়ে
                 * আছে, কলামও আছে — কিন্তু **নতুন ভাউচারে সেভ হয় না**।
                 * সম্পাদনায় বসে, কারণ `update()` স্প্রেড করে। ⓘ তাই
                 * অভিযোগটা হত ঐ অদ্ভুত আকারেই: "নতুন রসিদে ওয়ালেট
                 * থাকে না, কিন্তু এডিট করলে বসে"।
                 *
                 * ⭐ ধরা পড়েছে মালিক নমুনা আর পর্দা পাশাপাশি রেখে
                 * দেখানোয়, কোনো টেস্টে নয়।
                 */
                'carried_by' => $data['carried_by'] ?? null,
                'moved_at' => $data['moved_at'] ?? null,
                'note_counts' => $data['note_counts'] ?? null,
                'wallet' => $data['wallet'] ?? null,
                'wallet_medium' => $data['wallet_medium'] ?? null,
                'counterparty_phone' => $data['counterparty_phone'] ?? null,
                'charge_borne_by' => $data['charge_borne_by'] ?? 'us',
                'transfer_mode_id' => $data['transfer_mode_id'] ?? null,
                'from_branch' => $data['from_branch'] ?? null,
                'from_account_name' => $data['from_account_name'] ?? null,
                'deposit_slip_no' => $data['deposit_slip_no'] ?? null,
                'lands_on' => $data['lands_on'] ?? null,
                'reverse_on' => $data['reverse_on'] ?? null,
                // ⭐ মাসশেষের সমন্বয় — কেবল জাবেদায়; অন্য ধরনে দাগ বসে না (ভাউচারের পরিকল্পনা ৩ঘ, ৭ অক্টোবর ২০২৬)
                'is_adjusting' => $type === Voucher::JOURNAL && (bool) ($data['is_adjusting'] ?? false),
                // ⓘ কেবল ব্যবস্থার উল্টো জাবেদা বসায় ([[AdjustingReversals]]) — পর্দার নিয়মে ঘরটা নেই
                'reversal_of_id' => $data['reversal_of_id'] ?? null,

                /*
                 * ── খরচ ভাউচারের নিজের ছয়টা, ১৫ সেপ্টেম্বর ২০২৬ ────────
                 * নমুনার চৌদ্দটা ঘরের মধ্যে এগুলোই ভাউচারের সারিতে বসে;
                 * বাকিগুলো হয় আগে থেকেই ছিল, নয় আলাদা টেবিলে
                 * ([[VoucherBillShare]])।
                 */
                'cost_centre_id' => $data['cost_centre_id'] ?? null,
                'expense_account_id' => $data['expense_account_id'] ?? null,
                'bill_no' => $data['bill_no'] ?? null,

                /*
                 * ⛔ এই দুইটা তিন দিন পর্দায় ছিল আর কখনো সেভ হত না —
                 * কলাম না থাকায় Eloquent চুপচাপ ফেলে দিত।
                 */
                'payee_type_id' => $data['payee_type_id'] ?? null,
                'payee_name' => $data['payee_name'] ?? null,
                'gross_amount' => $data['gross_amount'] ?? null,
                'ait_amount' => $data['ait_amount'] ?? 0,
                'vds_amount' => $data['vds_amount'] ?? 0,

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
     * এই খরচটা কোন কোন চালানের ঘাড়ে — আর কার কতটা।
     *
     * ── ⭐ কেন "replace", "add" নয় ───────────────────────────────────
     * সম্পাদনার সময় ব্যবহারকারী একটা চালানের টিক তুলে নিতে পারেন।
     * ⓘ কেবল যোগ করলে তোলা টিকটা সারিতে থেকে যেত, আর ঐ মালের দামে
     * একটা খরচ বসে থাকত যেটা কেউ আর চায় না।
     *
     * ── ⚠️ শূন্য ভাগ বাদ ────────────────────────────────────────────
     * টিক দেওয়া কিন্তু অঙ্ক না লেখা মানে ব্যবহারকারী এখনো ঠিক করেননি।
     * ⛔ শূন্য টাকার সারি রাখলে চালানটা "খরচ বসেছে" দেখাত, অথচ কিছুই
     * বসেনি — আর পরেরবার সে চালানটা ছাঁকনিতে লুকিয়ে যেত।
     *
     * @param  list<array<string, mixed>>  $shares
     */
    public function replaceBillShares(Voucher $voucher, array $shares, string $basis = 'qty'): void
    {
        $voucher->billShares()->delete();

        $keep = [];

        foreach ($shares as $row) {
            $billId = (int) ($row['purchase_bill_id'] ?? 0);
            $amount = (string) ($row['share_amount'] ?? '0');

            if ($billId === 0 || bccomp($amount, '0', 4) <= 0) {
                continue;
            }

            // ⓘ একই চালান দুই সারিতে — যোগ করে একটা (টেবিলে এক ভাউচারে এক চালান একবারই)
            $keep[$billId] = bcadd($keep[$billId] ?? '0', $amount, 4);
        }

        $this->assertSharesFit($voucher, $keep);

        foreach ($keep as $billId => $amount) {
            $voucher->billShares()->create([
                'purchase_bill_id' => $billId,
                'share_amount' => $amount,
                'basis' => $basis,
            ]);
        }
    }

    /**
     * ⛔ চালানের ভাগ ভাউচারের খরচের বেশি নয়, আর বাতিল বা অন্য কোম্পানির চালানে নয় — Accounts-Finance অডিট ম৫, ৪ অক্টোবর ২০২৬।
     *
     * ⚠️ আগে যা আসত তাই বসত: ১,০০০ টাকার গাড়িভাড়া দুই চালানে ৮০০ + ৮০০ ভাগ হত, আর মালের দামে ১,৬০০ উঠত; বাতিল চালানেও
     * ভাগ বসত, যে মাল কখনো আসেনি তার দামে খরচ। ⓘ সীমা = খরচের অঙ্ক — পরিশোধ আর খরচে চার্জ বাইরে যোগ হয়
     * ([[paidWithCharge()]]), তাই ডেবিটের মোট থেকে চার্জ বাদ।
     *
     * @param  array<int, string>  $shares  চালান → অঙ্ক
     */
    private function assertSharesFit(Voucher $voucher, array $shares): void
    {
        if ($shares === []) {
            return;
        }

        $bills = DB::table('pur_bills')
            ->where('company_id', CompanyContext::id())
            ->whereNull('deleted_at')
            ->whereIn('id', array_keys($shares))
            ->get(['id', 'document_no', 'status'])
            ->keyBy('id');

        foreach (array_keys($shares) as $billId) {
            $bill = $bills->get($billId);

            if ($bill === null || $bill->status === DocumentStatus::CANCELLED) {
                throw ValidationException::withMessages([
                    'bill_shares' => __('accounts::validation.share_on_a_dead_bill', ['no' => $bill->document_no ?? '#'.$billId]),
                ]);
            }
        }

        // ⓘ নতুন করে — সম্পাদনার পথে হাতের মডেলে আগের সারি থাকে
        $voucher->load('lines');
        $ceiling = $voucher->totals()['debit'];

        if (in_array($voucher->type, [Voucher::PAYMENT, Voucher::EXPENSE], true)) {
            $ceiling = bcsub($ceiling, (string) ($voucher->charge_amount ?? '0'), 4);
        }

        $total = array_reduce($shares, fn (string $sum, string $amount) => bcadd($sum, $amount, 4), '0');

        if (bccomp($total, $ceiling, 4) > 0) {
            throw ValidationException::withMessages([
                'bill_shares' => __('accounts::validation.shares_over_the_expense', [
                    'shared' => Money::format($total),
                    'amount' => Money::format($ceiling),
                ]),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(Voucher $voucher, array $data, array $lines, bool $byHand = false): Voucher
    {
        $this->assertEditable($voucher);

        $this->assertNoChequeReceived($voucher->type,
            array_key_exists('instrument', $data) ? $data['instrument'] : $voucher->instrument);

        if ($byHand) {
            $this->assertTemplate((string) $voucher->type, $lines, [
                array_key_exists('party_type', $data) ? $data['party_type'] : $voucher->party_type,
                array_key_exists('party_id', $data) ? $data['party_id'] : $voucher->party_id,
            ]);
        }

        return DB::transaction(fn () => $this->writeHeaderAndLines($voucher, $data, $lines));
    }

    /**
     * লেজারে বসানো।
     *
     * এখান থেকেই ভাউচারটা আসল হয়ে ওঠে — তার আগ পর্যন্ত সেটা শুধু একটা
     * খসড়া, কোনো হিসাবে নেই। PostingEngine নিজেই দেখে ডেবিট-ক্রেডিট
     * মিলছে কি না, বছর খোলা আছে কি না, আর আগে বসানো হয়েছে কি না।
     */
    /**
     * @param  bool  $byHand  ⓘ মানুষ ভাউচারের পর্দা থেকে পাকা করছেন ([[VoucherController]]) — লেখক ≠ পাকাকারী কেবল তখন
     *                        ([[assertAnotherHandPosts()]]); সিস্টেমের এক ধাপে লেখা-পাকা ভাউচার আর শেষ সইয়ের পরে
     *                        নিজে-পাকা আগের মতো।
     */
    public function post(Voucher $voucher, bool $byHand = false): Voucher
    {
        if ($voucher->isPosted()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.already_posted', ['no' => $voucher->document_no]),
            ]);
        }

        // ⚠️ নিয়মের আগে লেখা খসড়াও — টাকা খাতায় ওঠে ঠিক এই মুহূর্তে
        $this->assertNoChequeReceived($voucher->type, $voucher->instrument);

        if ($voucher->isCancelled()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.cancelled_cannot_post'),
            ]);
        }

        if ($byHand) {
            $this->assertAnotherHandPosts($voucher);
        }

        $voucher->load('lines.account');

        $this->assertLinesArePostable($voucher);
        $this->assertNoChequeInHandByHand($voucher);
        $this->assertCashLandsInOwnTill($voucher);
        $this->assertTheWayMatchesTheAccount($voucher);

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
            /*
             * ⛔ সারি তালা দিয়ে অবস্থা আবার পড়া — Accounts-Finance অডিট ম২, ৪ অক্টোবর ২০২৬।
             * ⚠️ উপরের পরখ হাতে ধরা মডেল দেখে। একই মুহূর্তে কেউ "বাতিল" চাপলে দুইজনেই "খসড়া" দেখতেন: বাতিল কিছু ফেরাত না
             * (খাতায় তখনো কিছু নেই), আর পোস্ট খাতায় বসাত — শেষে কাগজ "বাতিল", অথচ টাকা খাতায়। ⓘ এখন দ্বিতীয়জন অপেক্ষা
             * করেন, তারপর আসল অবস্থা দেখে থামেন ([[cancel()]]-এও একই তালা)।
             */
            $status = $this->lockedStatus($voucher);

            if ($status !== DocumentStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => $status === DocumentStatus::CANCELLED
                        ? __('accounts::validation.cancelled_cannot_post')
                        : __('accounts::validation.already_posted', ['no' => $voucher->document_no]),
                ]);
            }

            /*
             * ⛔ লেনদেন নম্বর লেনদেনের ভেতরে — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️১৩; [[ATransactionNumberIsCheckedAcrossBranchesTest]])।
             * ⓘ যাচাইটা খাতা-জোড়া (`money_account_id`) মাথায় লিখেও দেয়। আগে লেনদেনের বাইরে ছিল: পরে টাকা না থাকায় পোস্ট থামলেও
             * খসড়ায় জোড়াটা রয়ে যেত, আর সঠিক ভাউচারে একই TrxID আর বসত না। এখন পোস্ট থামলে জোড়াও ফেরে।
             */
            $this->assertBankReferenceIsFree($voucher);

            // ⚠️ লেনদেনের ভিতরে, খাতায় তোলার আগে — তালা আর মাপা একই লেনদেনে
            $this->assertMoneyIsThere($voucher);
            $this->assertAgainstFits($voucher);

            $this->posting->post(
                Voucher::SOURCE_TYPES[$voucher->type],
                $voucher->id,
                $voucher->trx_date,
                $this->ledgerLines($voucher, $ownable),
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

            /*
             * ⓘ অন্য মডিউলকে জানানো — লেনদেন পাকা হওয়ার পরে, আগে নয়।
             * ⚠️ আগে জানালে শ্রোতা এমন ভাউচার দেখত যেটা একটু পরে
             * রোলব্যাক হয়ে যেতে পারে (অনুমোদনে আটকানো, মাসের তালা)।
             * [[VoucherPosted]]-এ কারণ লেখা।
             */
            DB::afterCommit(fn () => event(VoucherPosted::from($voucher)));

            return $voucher->fresh(['lines']);
        });
    }

    /**
     * ⭐ "কোন কাগজের বিপরীতে" — কাগজটা নিজেই বলে কোন ভাউচার তাকে মেটাতে পারে (গ২, Accounts-Finance অডিট,
     * ৪ অক্টোবর ২০২৬; [[SettlementTerms]])।
     *
     * ⛔ আগে ঘরটায় যা লেখা থাকত তা-ই মানা হত: ১ টাকার রসিদে ৫ লাখের মূলধন "এসেছে", সইয়ের অপেক্ষায় থাকা উত্তোলন
     * "পরিশোধিত", এক গ্রাহকের টাকায় আরেক গ্রাহকের বিল শোধ।
     *
     * ⓘ মাপা হয়:
     *  · কাগজটা আছে, এই কোম্পানিতে।
     *  · এখনো খোলা।
     *  · ভাউচারের ধরন মেলে।
     *  · অঙ্ক হুবহু, বা বিলের বেলায় বাকির বেশি নয়।
     *  · পক্ষ মেলে।
     *
     * ⓘ অঙ্ক = আসল টাকা: পরিশোধে ব্যাংক চার্জ আলাদা ডেবিট সারিতে বসে ([[paidWithCharge()]]), তাই সেটা বাদ। রসিদে
     * চার্জ টাকার ভেতর থেকেই কাটে, তাই ডেবিটের মোটই অঙ্ক।
     *
     * ⓘ তালাসহ পড়া, লেনদেনের ভিতরে — একই বিলের দুই রসিদ একসাথে এলে দুইজনেই পুরো বাকি দেখত।
     *
     * ⓘ কাউন্টারের ভাউচার এখানে মাপা হয় না: কাগজ আর টাকা কাউন্টার একসাথেই লেখে, আর বিলের চেয়ে বেশি টাকা সেখানে
     * অগ্রিম হিসেবে ইচ্ছাকৃত। ঘরটা ফর্মে নেই, তাই হাতে পাঠিয়েও ঐ পথে ঢোকা যায় না ([[create()]]-এর `origin`)।
     *
     * ⓘ যে কাগজ কেবল সূত্র — কিছু মেটায় না (ঋণ সুবিধা, ভাড়ার চুক্তি) — তার কেবল থাকা মাপা হয়। ⛔ আর যে কাগজ মেটে
     * ([[SettledByAVoucher]]) অথচ শর্ত বলে না, তার বিপরীতে লেখাই যায় না — নীরবে মেনে নিলে ফাঁকটা ফিরত।
     */
    private function assertAgainstFits(Voucher $voucher): void
    {
        $type = (string) ($voucher->against_type ?? '');
        $id = (int) ($voucher->against_id ?? 0);

        if (($type === '' && $id <= 0) || $voucher->origin === Voucher::ORIGIN_COUNTER) {
            return;
        }

        $class = app(DrillResolver::class)->map()[$type] ?? null;
        $model = $class === null ? null : new $class;
        $document = $model instanceof Model && $id > 0 ? $model->newQuery()->lockForUpdate()->find($id) : null;

        if ($document === null) {
            throw ValidationException::withMessages(['against_id' => __('accounts::validation.against_unknown')]);
        }

        if (! $document instanceof SettlementTerms) {
            if ($document instanceof SettledByAVoucher) {
                throw ValidationException::withMessages(['against_id' => __('accounts::validation.against_unknown')]);
            }

            return;
        }

        $terms = $document->settlementTerms();

        if (! $terms['open']) {
            throw ValidationException::withMessages(['against_id' => __('accounts::validation.against_closed')]);
        }

        if ($terms['voucher_type'] !== $voucher->type) {
            throw ValidationException::withMessages(['against_id' => __('accounts::validation.against_wrong_type')]);
        }

        $debit = $voucher->totals()['debit'];
        $paid = $voucher->type === Voucher::RECEIPT ? $debit : bcsub($debit, (string) ($voucher->charge_amount ?? '0'), 4);
        $cmp = bccomp($paid, (string) $terms['amount'], 4);

        if (($terms['up_to'] ?? false) ? $cmp > 0 : $cmp !== 0) {
            throw ValidationException::withMessages(['against_id' => __('accounts::validation.against_wrong_amount', [
                'amount' => Money::format($paid),
                'expected' => Money::format((string) $terms['amount']),
            ])]);
        }

        $partyType = $terms['party_type'] ?? null;

        if ($partyType === null) {
            return;
        }

        $hasParty = $voucher->party_type !== null && $voucher->party_id !== null;

        if ($hasParty
            ? ($voucher->party_type !== $partyType || (int) $voucher->party_id !== (int) ($terms['party_id'] ?? 0))
            : ($terms['party_required'] ?? false)) {
            throw ValidationException::withMessages(['against_id' => __('accounts::validation.against_wrong_party')]);
        }
    }

    /**
     * ⛔ ব্যাংকে মেলানো ভাউচার বাতিল নয় — ম৩। ⓘ [[AccountsReversalService]]-এর একই নিয়ম, একই বার্তা; এখানে বসায় প্রতিটা বাতিলের
     * পথ ঢাকা পড়ে (ভাউচারের পর্দা, উল্টো কাগজ, আন্তঃকোম্পানি, অন্য মডিউল)।
     */
    private function assertNotReconciled(Voucher $voucher): void
    {
        $lineIds = $voucher->lines->pluck('id');

        if ($voucher->lines->whereNotNull('reconciliation_id')->isNotEmpty()
            || BankStatementLine::query()->withoutGlobalScopes()->whereIn('matched_line_id', $lineIds)->exists()) {
            throw ValidationException::withMessages([
                'cancel_reason' => __('accounts::reversal.reconciled', ['no' => $voucher->document_no]),
            ]);
        }
    }

    /**
     * ভাউচারের অবস্থা, সারি তালা দিয়ে — ম২। ⓘ হাতে থাকা মডেল ধরেই খোঁজা, তাই কোম্পানির স্কোপ লাগে না (আন্তঃকোম্পানির
     * দুই পাশ অন্য কোম্পানির প্রসঙ্গেও এখান দিয়ে যায়)।
     */
    private function lockedStatus(Voucher $voucher): string
    {
        return (string) Voucher::query()->withoutGlobalScopes()
            ->whereKey($voucher->getKey())
            ->lockForUpdate()
            ->value('status');
    }

    /** দুই পাশ একসাথে উল্টানোর ভিতরে আছি কি না — [[cancellingBothSides()]] */
    private static int $bothSides = 0;

    /**
     * ⭐ আন্তঃকোম্পানির দুই পাশ একসাথে — কেবল [[InterCompanyService::reverse()]] এর ভিতর দিয়ে (গ১২, ৪ অক্টোবর ২০২৬)।
     *
     * @template T
     *
     * @param  \Closure(): T  $work
     * @return T
     */
    public static function cancellingBothSides(\Closure $work): mixed
    {
        self::$bothSides++;

        try {
            return $work();
        } finally {
            self::$bothSides--;
        }
    }

    /**
     * ⛔ আন্তঃকোম্পানি লেনদেনের এক পাশ একা বাতিল বা উল্টো নয় — গ১২, Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬।
     *
     * ⓘ আগে ভাউচারের পর্দা থেকে এক পাশ (দেওয়ার বা পাওয়ার) একা বাতিল করা যেত: অন্য কোম্পানিতে টাকা পাওয়া থেকেই যেত,
     * আর দুই কোম্পানির চলতি হিসাব শূন্যে মিলত না। ⓘ দুই পাশ একসাথে উল্টায় [[InterCompanyService::reverse()]];
     * উল্টো কাগজের পথও ([[AccountsReversalService::reverseVoucher()]]) এখান দিয়েই যায়, তাই সেটাও থামে।
     *
     * ⓘ সারিটা অন্য কোম্পানির হতে পারে (পাওয়ার পাশ), তাই কোম্পানির ছাঁকনি তুলে খোঁজা — আর মেলানো হয় ভাউচারটা ঐ
     * পাশের কোম্পানিরই কি না।
     */
    private function assertNotOneSideOfInterCompany(Voucher $voucher): void
    {
        if (self::$bothSides > 0) {
            return;
        }

        $transfer = InterCompanyTransfer::query()->withoutGlobalScopes()
            ->where(fn ($q) => $q->where('out_voucher_id', $voucher->id)->orWhere('in_voucher_id', $voucher->id))
            ->get()
            ->first(fn (InterCompanyTransfer $t) => ((int) $t->out_voucher_id === (int) $voucher->id && (int) $t->company_id === (int) $voucher->company_id)
                || ((int) $t->in_voucher_id === (int) $voucher->id && (int) $t->counter_company_id === (int) $voucher->company_id));

        if ($transfer !== null) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.inter_company_one_side', ['no' => $voucher->document_no]),
            ]);
        }
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
     * ⭐ পোস্ট হওয়া ভাউচারের সংশোধন — মালিক, ৩ অক্টোবর ২০২৬।
     *
     * *"মাস ক্লোজ না হওয়া পর্যন্ত সুপার অ্যাডমিন সব পোস্টেড কাগজ এডিট করতে পারবেন।"*
     *
     * ⓘ পাহারা, তালা, এক লেনদেন, আগে-পরের ছবি — সব [[RevisionKeeper::edit()]]-এর। এখানে কেবল
     * ভাউচারের নিজের অংশ: মাথা আর সারি লেখা। নম্বর আর ধরন বদলায় না ([[writeHeaderAndLines()]]),
     * অবস্থাও না — পোস্ট হওয়া ভাউচার পোস্ট হয়েই থাকে।
     *
     * @param  array<string, mixed>  $data  খসড়ার সম্পাদনার একই যাচাই করা ঘর
     * @param  list<array<string, mixed>>  $lines
     */
    public function editPosted(
        Voucher $voucher,
        User $user,
        string $reason,
        array $data,
        array $lines,
        // ⓘ খরচের চালান-ভাগ — সংশোধনের একই লেনদেনে, নইলে ভাগ পুরনো অঙ্কে থাকত ([[replaceBillShares()]])
        ?array $billShares = null,
        string $basis = 'qty',
    ): DocumentRevision {
        // ⛔ যে ভাউচার অন্য কাগজের সাথে বাঁধা, তা এখান দিয়ে নয় — কারণসহ থামে (পাকা ভাউচার সম্পাদনা, মালিক ৫ অক্টোবর ২০২৬)
        $why = $this->whyNotRevisable($voucher);

        if ($why !== null) {
            throw ValidationException::withMessages(['edit' => $why]);
        }

        $this->assertNoChequeReceived($voucher->type,
            array_key_exists('instrument', $data) ? $data['instrument'] : $voucher->instrument);

        return app(RevisionKeeper::class)->edit($voucher, $user, $reason, function (Voucher $locked) use ($data, $lines, $billShares, $basis): void {
            $this->writeHeaderAndLines($locked, [...$data, 'status' => $locked->status], $lines);

            if ($billShares !== null) {
                $this->replaceBillShares($locked, $billShares, $basis);
            }
        });
    }

    /**
     * ⭐ পাকা ভাউচার কেন এখান দিয়ে সম্পাদনা করা নিরাপদ নয় — `null` মানে নিরাপদ (মালিকের আদেশ, ৫ অক্টোবর ২০২৬)।
     *
     * ⓘ সুইচ, সুপার অ্যাডমিন আর খোলা মাস দেখে [[PostedEdit]]; এটা দেখে ভাউচারটা অন্য কিছুর সাথে বাঁধা কি না। বাঁধা থাকলে
     * এখানে অঙ্ক বদলালে অন্য পাশটা পুরনো অঙ্কে থেকে যেত — তাই পর্দা বোতামের জায়গায় এই কারণটাই দেখায়, চুপচাপ লুকায় না।
     *  · কাউন্টারের বিল থেকে আসা রসিদ/পরিশোধ — বিলটা সম্পাদনা করতে হয়
     *  · অন্য কাগজ নিষ্পন্ন করে (`against`) — সেই কাগজের অঙ্ক আর অবস্থা এই টাকার উপর দাঁড়িয়ে
     *  · আন্তঃকোম্পানির এক পাশ — দুই পাশ একসাথে না বদলালে দুই কোম্পানি আর মেলে না
     *  · ব্যাংকে মেলানো — মেলানোটা পুরনো অঙ্কে দাঁড়িয়ে
     */
    public function whyNotRevisable(Voucher $voucher): ?string
    {
        if ($voucher->origin === Voucher::ORIGIN_COUNTER) {
            return __('accounts::revision.from_counter', ['no' => $voucher->document_no]);
        }

        if (filled($voucher->against_type) && (int) $voucher->against_id > 0) {
            return __('accounts::revision.settles_a_paper', ['no' => $voucher->document_no, 'paper' => $this->againstLabel($voucher)]);
        }

        $interCompany = InterCompanyTransfer::query()->withoutGlobalScopes()
            ->where(fn ($q) => $q->where('out_voucher_id', $voucher->id)->orWhere('in_voucher_id', $voucher->id))
            ->exists();

        if ($interCompany) {
            return __('accounts::revision.inter_company_side', ['no' => $voucher->document_no]);
        }

        $voucher->loadMissing('lines');

        if ($voucher->lines->whereNotNull('reconciliation_id')->isNotEmpty()
            || BankStatementLine::query()->withoutGlobalScopes()->whereIn('matched_line_id', $voucher->lines->pluck('id'))->exists()) {
            return __('accounts::revision.reconciled', ['no' => $voucher->document_no]);
        }

        return null;
    }

    /** বাঁধা কাগজের নম্বর — না পাওয়া গেলে ধরনের নাম */
    private function againstLabel(Voucher $voucher): string
    {
        $class = app(DrillResolver::class)->map()[(string) $voucher->against_type] ?? null;
        $document = $class !== null ? (new $class)->newQuery()->find((int) $voucher->against_id) : null;

        return $document !== null && method_exists($document, 'drillDocumentNo')
            ? (string) $document->drillDocumentNo()
            : (string) $voucher->against_type;
    }

    /**
     * সংশোধনের পরে আবার খাতায় — [[post()]]-এর একই যাচাই, একই সারি; অবস্থা, সই আর ঘটনা নয়।
     *
     * ⚠️ অবস্থা আগে থেকেই "পোস্ট হয়েছে", আর অনুমোদনকারী যিনি ছিলেন তিনিই থাকেন — সংশোধনকারীর নাম
     * সংশোধনের সারিতে ([[DocumentRevision]])। ⓘ `VoucherPosted` আবার ছোড়া হয় না: শ্রোতারা (মূলধনের
     * খাতা) একই রসিদ দুইবার পেলে দুইবার লিখত।
     */
    public function repostAfterRevision(Voucher $voucher): void
    {
        $voucher->load('lines.account');

        $this->assertNoChequeReceived($voucher->type, $voucher->instrument);
        $this->assertLinesArePostable($voucher);
        $this->assertNoChequeInHandByHand($voucher);
        $this->assertCashLandsInOwnTill($voucher);
        $this->assertTheWayMatchesTheAccount($voucher);
        $this->assertBankReferenceIsFree($voucher);
        $this->assertMoneyIsThere($voucher);

        $this->posting->post(
            Voucher::SOURCE_TYPES[$voucher->type],
            $voucher->id,
            $voucher->trx_date,
            $this->ledgerLines($voucher, $this->accountsThatHoldAParty()),
            documentNo: $voucher->document_no,
            branchId: $voucher->branch_id,
        );

        $voucher->forceFill(['amount' => $voucher->totals()['debit']])->save();
    }

    /**
     * মাথা আর সারি লেখা — খসড়ার সম্পাদনা আর পোস্ট হওয়া ভাউচারের সংশোধন, দুই পথের একই অংশ।
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeHeaderAndLines(Voucher $voucher, array $data, array $lines): Voucher
    {
        $trxDate = Carbon::parse($data['trx_date'] ?? $voucher->trx_date);

        $voucher->update([
            // ⛔ কেবল মাথার ঘর — জাবেদার পর্দা `lines` পাঠায়, আর তা মাথায় ঢালতে গেলে খসড়া সম্পাদনা ৫০০ দিত (৭ অক্টোবর ২০২৬;
            // সংশোধনের পথ একই ছাঁকনি নেয়, [[VoucherController::saveRevision()]])
            ...array_intersect_key($data, array_flip($voucher->getFillable())),
            // ⓘ সমন্বয়ের দাগ কেবল জাবেদায় — সম্পাদনাতেও ([[create()]]-এর একই শর্ত)
            ...(array_key_exists('is_adjusting', $data)
                ? ['is_adjusting' => $voucher->type === Voucher::JOURNAL && (bool) $data['is_adjusting']]
                : []),
            // ধরন ও নম্বর কখনো বদলায় না: নম্বরটা সিরিজ থেকে এসেছে আর
            // ধরন বদলালে ওই সিরিজটাই ভুল হয়ে যেত
            'type' => $voucher->type,
            'document_no' => $voucher->document_no,
            'trx_date' => $trxDate->toDateString(),
            'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
        ]);

        $this->replaceLines($voucher, $lines);

        return $voucher->fresh(['lines']);
    }

    /**
     * খাতার সারিগুলো — পোস্ট আর সংশোধনের পরে আবার বসানো, দুই পথের একটাই সংজ্ঞা (৩ অক্টোবর ২০২৬)।
     *
     * ⛔ দুই জায়গায় লিখলে হেডারের পক্ষ কোন সারিতে নামে সেই নিয়মটা ([[post()]]-এর মন্তব্য) একদিন এক
     * পথে বদলাত আর অন্যটায় না — আর সংশোধিত ভাউচারের খাতা আসলটার থেকে আলাদা বসত।
     *
     * @param  array<int, list<string>>  $ownable  যে খাতগুলো কারো নামে বসে, আর কোন ধরনের ([[accountsThatHoldAParty()]])
     * @return list<array<string, mixed>>
     */
    private function ledgerLines(Voucher $voucher, array $ownable): array
    {
        /*
         * ⓘ মাথার পক্ষ নামে কেবল সেই সারিতে, যার খাত ঐ ধরনের পক্ষ নেয় — খাতের ধর্ম ([[Account::takesParty()]])। ⛔ নাহলে গ্রাহক
         * মাথায় থাকা রসিদের মূলধন-সারিতে (৩১০০ কেবল মানুষ নেয়) গ্রাহক বসতে যেত, আর ইঞ্জিন পুরো পোস্ট আটকাত।
         */
        $lands = fn (VoucherLine $line): bool => $voucher->party_type !== null
            && in_array((string) $voucher->party_type, $ownable[(int) $line->account_id] ?? [], true);

        return $voucher->lines->map(fn (VoucherLine $line) => [
            'account_id' => $line->account_id,
            'debit' => $line->debit,
            'credit' => $line->credit,
            'party_type' => $line->party_type ?? ($lands($line) ? $voucher->party_type : null),
            'party_id' => $line->party_id ?? ($lands($line) ? $voucher->party_id : null),
            'cost_center_id' => $line->cost_center_id,
            'narration' => $line->narration ?? $voucher->narration,
            'source_line_id' => $line->id,
        ])->all();
    }

    /**
     * বাতিল করা — বিপরীত এন্ট্রি দিয়ে, মুছে নয় (নিয়ম ৫)।
     *
     * মূল এন্ট্রিগুলো লেজারে থেকে যায়, আর তার পাশে সমান-উল্টো এন্ট্রি বসে।
     * মুছে দিলে ছাপা কাগজের নম্বরটা আর কোনো রেকর্ডের সাথে মিলত না, আর
     * অডিটে দেখা যেত একটা নম্বর ইস্যু হয়েছে কিন্তু কিছুই ঘটেনি।
     */
    /**
     * @param  string|null  $paperNo  উল্টো সারির কাগজ-নম্বর — উল্টো কাগজ (REV-…) হলে তারটা ([[AccountsReversalService]]); না দিলে ভাউচারেরটা
     */
    public function cancel(Voucher $voucher, string $reason, ?string $onDate = null, ?string $paperNo = null): Voucher
    {
        if ($voucher->isCancelled()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.already_cancelled'),
            ]);
        }

        $this->assertNotOneSideOfInterCompany($voucher);

        if (blank($reason)) {
            throw ValidationException::withMessages([
                'cancel_reason' => __('accounts::validation.cancel_reason_required'),
            ]);
        }

        return DB::transaction(function () use ($voucher, $reason, $onDate, $paperNo) {
            /*
             * ⛔ সারি তালা দিয়ে আসল অবস্থা — ম২। ⚠️ হাতে ধরা মডেল "খসড়া" বললেও মাঝে কেউ পোস্ট করে থাকতে পারেন; তখন
             * খাতার দাখিলা না ফিরিয়েই কাগজ "বাতিল" হত। আর দুইজন একসাথে বাতিল চাপলে দাখিলা দুইবার উল্টাত।
             */
            $status = $this->lockedStatus($voucher);

            if ($status === DocumentStatus::CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => __('accounts::validation.already_cancelled'),
                ]);
            }

            $voucher->status = $status;

            // খসড়া কখনো লেজারে বসেনি, তাই ফেরানোরও কিছু নেই
            if ($voucher->isPosted()) {
                /*
                 * ⛔ Accounts-Finance অডিট ম৩, ৪ অক্টোবর ২০২৬ — দুইটা পাহারা, উল্টানোর আগে:
                 *  · ব্যাংকে মেলানো ভাউচার এখান দিয়ে বাতিল হয় না। ⚠️ আগে কেবল উল্টো কাগজের পথ ([[AccountsReversalService]])
                 *    এটা দেখত; ভাউচারের পর্দা, স্থানান্তর বা অন্য মডিউল সরাসরি এখানে এলে আগের মাসের মেলানো চুপচাপ ভুল হত।
                 *  · উল্টানোয় যে নগদ খাত থেকে টাকা বেরোয়, সেখানে টাকা থাকতে হবে। ⚠️ রসিদের টাকা খরচ হয়ে যাওয়ার পরে রসিদ
                 *    বাতিল করলে কাউন্টার ঋণাত্মক হত — পোস্টের সময় যে নিয়ম ([[assertMoneyIsThere()]]), বাতিলেও সেটাই।
                 */
                $voucher->load('lines.account');
                $this->assertNotReconciled($voucher);
                $this->assertMoneyIsThere($voucher, reversing: true, on: $onDate ?? now()->toDateString());

                $this->posting->reverse(
                    Voucher::SOURCE_TYPES[$voucher->type],
                    $voucher->id,
                    $onDate ?? now()->toDateString(),
                    $reason,
                    documentNo: $paperNo,
                );
            }

            $this->cancelItsAutoReversal($voucher, $reason, $onDate);

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
     * ⛔ আসল সমন্বয় বাতিল হলে তার নিজে-বসা উল্টোটাও যায় — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (হিসাব, ঠিক ১)।
     *
     * ⓘ সমন্বয় জাবেদা তার তারিখে নিজে উল্টায় ([[AdjustingReversals]]), উল্টোটা `reversal_of_id`-এ আসলটাকে চেনে। ⚠️ আগে
     * আসলটা বাতিল করলে কেবল আসলটাই ফিরত — উল্টোটা খাতায় থেকে যেত, আর দুই খাতে উল্টো দিকে একই অঙ্ক বসে থাকত: বকেয়া
     * আয় বাতিল করলে পরের মাসের আয় সেই অঙ্কে কমেই থাকত।
     * ⭐ এখন একই লেনদেনে উল্টোটাও বাতিল হয় (তারও দাখিলা ফেরে) — দুই খাতের মোট ফল শূন্য। কোনো একটা না পারলে (বন্ধ মাস,
     * ব্যাংকে মেলানো) দুইটার কোনোটাই বাতিল হয় না, আর কারণটা বাংলায় ফেরে।
     * ⓘ উল্টোটার বাতিল-তারিখ তার নিজের তারিখের আগে পড়ে না — পেছনের তারিখের উল্টো কাগজেও খাতায় ক্রম ঠিক থাকে।
     */
    private function cancelItsAutoReversal(Voucher $voucher, string $reason, ?string $onDate): void
    {
        if (! $voucher->is_adjusting || $voucher->reversal_of_id !== null) {
            return;
        }

        $reversal = Voucher::acrossBranches()
            ->where('reversal_of_id', $voucher->id)
            ->where('status', '!=', DocumentStatus::CANCELLED)
            ->first();

        if ($reversal === null) {
            return;
        }

        $on = $onDate ?? now()->toDateString();

        if ($reversal->trx_date !== null && $reversal->trx_date->toDateString() > $on) {
            $on = $reversal->trx_date->toDateString();
        }

        if (($lock = app(OpenPeriod::class)->lockOn($on)) !== null) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.adjusting_reversal_month_locked', [
                    'no' => $reversal->document_no,
                    'month' => $lock->label(),
                ]),
            ]);
        }

        $this->cancel($reversal, __('accounts::voucher.adjusting_reversal_cancel_reason', [
            'no' => $voucher->document_no,
            'reason' => $reason,
        ]), $on);
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

        if (in_array($type, [Voucher::PAYMENT, Voucher::EXPENSE], true)) {
            return $this->paidWithCharge($toAccountId, $fromAccountId, $amount, (string) $charge, $narration);
        }

        return $this->withCharge($toAccountId, $fromAccountId, $amount, (string) $charge, $narration);
    }

    /**
     * টাকা **বেরোনোর** সময় ব্যাংক বা MFS যা কাটে — তিনটা সারি, ২৮ সেপ্টেম্বর ২০২৬।
     *
     * ── ⭐ সমন্বয়কারীর সিদ্ধান্ত (abos-69) ──────────────────────────────
     * চার্জ কোম্পানির, সরবরাহকারীর নয়:
     *
     *   বিকাশে ৯০০ দেওয়া · চার্জ ৫
     *   → দেনা ডেবিট ৯০০ · ৫২১১ ডেবিট ৫ · বিকাশ ক্রেডিট ৯০৫
     *
     * ⛔ আগে সব চার্জ [[withCharge()]]-এ যেত, যা লেখা হয়েছিল টাকা **ঢোকার**
     * জন্য: চার্জের খাত বাছত যেখানে টাকা গেল তা দেখে — পরিশোধে সেটা দেনার
     * খাত, তাই ভুলবার্তা দিয়ে থামত, আর সরাসরি ক্রয়ের "এখনই দেওয়া"-র চার্জ
     * খাতায় উঠতই না। ⓘ এখানে খাত আসে যেখান থেকে টাকা বেরোল তা দেখে।
     * ⚠️ দেনা কমে কেবল অঙ্কে — চার্জ দেনায় মিশলে সরবরাহকারীর খাতায় এমন
     * টাকা "পরিশোধ" দেখাত যা তিনি কোনোদিন পাননি।
     *
     * @return list<array<string, mixed>>
     */
    private function paidWithCharge(
        int $toAccountId,
        int $fromAccountId,
        string $amount,
        string $charge,
        ?string $narration,
    ): array {
        $chargeAccount = $this->chargeAccountFor(Account::query()->find($fromAccountId));

        return [
            [
                'account_id' => $toAccountId,
                'debit' => $amount,
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
                'credit' => bcadd($amount, $charge, 4),
                'narration' => $narration,
            ],
        ];
    }

    /**
     * চার্জের খরচের খাত — টাকার খাত ব্যাংক হলে ৫২১০, MFS হলে ৫২১১।
     *
     * ⛔ নগদে চার্জ হয় না ([[withCharge()]]-এর মন্তব্য), তাই অন্য সব খাতে থামে।
     */
    private function chargeAccountFor(?Account $money): Account
    {
        $code = match (true) {
            $money?->isMfs() === true => StandardChart::MFS_CHARGES,
            $money?->isBank() === true => StandardChart::BANK_CHARGES,
            default => null,
        };

        if ($code === null) {
            throw ValidationException::withMessages([
                'charge_amount' => __('accounts::validation.charge_needs_a_bank_or_mfs'),
            ]);
        }

        return StandardChart::find($code)
            ?? throw ValidationException::withMessages([
                'charge_amount' => __('accounts::validation.charge_account_missing', ['code' => $code]),
            ]);
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

        $chargeAccount = $this->chargeAccountFor(Account::query()->find($toAccountId));

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
    /**
     * নগদ কেবল নিজের ক্যাশবাক্সে — সার্ভারের দিকের তালা।
     *
     * ── ⭐ মালিকের নিয়ম, ২১ সেপ্টেম্বর ২০২৬ ────────────────────────
     * *"cash e sudu tar nijer cash accounts e taka nite parbe, tai
     * app er dorkar nai — din sese emnite tar kachtekei buje nibe"*।
     *
     * ⚠️ নগদে অনুমোদন তুলে দেওয়ার **শর্তটাই** এই সীমা। ⛔ শর্তটা না
     * বসিয়ে অনুমোদন তুললে যে কেউ যেকোনো ক্যাশ খাতে টাকা বসাতে পারত,
     * আর দিন শেষে মেলানোর সময় ধরাই পড়ত না।
     *
     * ── ⓘ পর্দা ছাঁকা যথেষ্ট নয় ───────────────────────────────────
     * ফর্মে তালিকাটা ছাঁকা হয়েছে, কিন্তু সেটা কেবল **দেখানো**।
     * ⛔ এই অ্যাপে একবার রপ্তানি "বন্ধ" করা হয়েছিল শুধু বোতাম লুকিয়ে,
     * আর ঠিকানা টাইপ করলেই ফাইল নামত। তাই তালাটা এখানেও।
     *
     * ── ⓘ কারা ছাড় পান ───────────────────────────────────────────
     * ⚠️ কনসোল ও সিডারে কোনো ব্যবহারকারী নেই — তখন নিয়মটা চলে না,
     * নাহলে মাইগ্রেশন ও ইমপোর্ট ভাঙত। ⓘ আর যাঁর কোনো বাক্স **নেই**
     * তাঁর জন্য নিয়মটা খাটে: তিনি কোনো নগদ খাতেই বসাতে পারবেন না,
     * কারণ টাকাটা কার হেফাজতে তার উত্তর থাকে না।
     */
    private function assertCashLandsInOwnTill(Voucher $voucher): void
    {
        $userId = (int) (auth()->id() ?? 0);

        /*
         * ⓘ নগদ গোনার সমন্বয় ব্যবস্থার কাগজ — কেউ বাক্সে টাকা নেননি বা দেননি, গোনা আর খাতার তফাত খাতায় ওঠে মাত্র; অনুমোদন দেন
         * গণনাকারী ছাড়া অন্য কেউ, যিনি সাধারণত বাক্সের ধারক নন। পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️১৪;
         * [[ACashCountIsApprovedBySomeoneElseTest]])। চিহ্নটা কেবল [[CashCountService]] বসায় — ফর্ম বা অনুরোধ থেকে আসে না।
         */
        if ($userId === 0 || $voucher->origin === Voucher::ORIGIN_CASH_COUNT) {
            return;
        }

        /*
         * ⭐ প্রতিটা নগদ সারি — গ৩, Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬।
         *
         * ⛔ আগে কেবল প্রথম নগদ সারি দেখা হত: "Dr নিজের বাক্স / Cr সহকর্মীর বাক্স" লিখলে প্রথমটা নিজের, নিয়ম
         * খুশি — আর সহকর্মীর বাক্স খালি হত সই ছাড়া।
         */
        $cashLines = $voucher->lines
            ->map(fn (VoucherLine $line) => $line->account)
            ->filter(fn (?Account $a) => $a !== null && $a->isCash())
            ->unique('id');

        foreach ($cashLines as $cash) {
            if (CashTill::mayUse($userId, (int) $cash->id)) {
                continue;
            }

            throw ValidationException::withMessages([
                'lines' => CashTill::heldByAnyone()
                    ? __('accounts::validation.cash_not_your_till', ['account' => $cash->label()])
                    : __('accounts::validation.no_till_of_your_own'),
            ]);
        }
    }

    /**
     * টিল বা ওয়ালেটে যা নেই তা ভাউচার দিয়েও বেরোয় না — ২৭ সেপ্টেম্বর ২০২৬।
     *
     * ⛔ লাইভ QA (hp2, TCL): ক্রয়ের পরিশোধে পাহারা বসল ([[PaymentService]]),
     * কিন্তু পরিশোধ, খরচ আর কন্ট্রা ভাউচার একই টিল থেকে টাকা বের করে — ওরা
     * এই দরজায় আসে। ⓘ নিয়ম এক জায়গায় ([[CashOnHand]]): নগদ আর MFS শূন্যের
     * নিচে নয়, ব্যাংক (CC/OD) নামতে পারে।
     *
     * ⓘ খাত ধরে **নিট** বের-হওয়া (ক্রেডিট − ডেবিট) মাপা হয়: একই টিলের দুই
     * সারি একে অন্যকে কাটে, আর টিলে টাকা ঢোকানো ভাউচার কখনো আটকায় না।
     * ⚠️ তালা id-এর ক্রমে — দুইটা কন্ট্রা উল্টো দিকে একই দুই টিল ছুঁলে
     * অন্য ক্রমে তালা নিলে একে অন্যের জন্য অপেক্ষায় আটকে যেত।
     */
    private function assertMoneyIsThere(Voucher $voucher, bool $reversing = false, ?string $on = null): void
    {
        $cash = app(CashOnHand::class);
        $out = [];

        foreach ($voucher->lines as $line) {
            if ($line->account === null || ! $cash->guards($line->account)) {
                continue;
            }

            $id = (int) $line->account_id;
            // ⓘ উল্টানোয় দিক উল্টো — যে খাতে টাকা ঢুকেছিল, সেখান থেকেই বেরোয় (ম৩)
            $leaving = $reversing
                ? bcsub((string) $line->debit, (string) $line->credit, 4)
                : bcsub((string) $line->credit, (string) $line->debit, 4);
            $out[$id] = bcadd($out[$id] ?? '0', $leaving, 4);
        }

        ksort($out);

        foreach ($out as $id => $amount) {
            if (bccomp($amount, '0', 4) <= 0) {
                continue;
            }

            $account = $voucher->lines->firstWhere('account_id', $id)->account;
            $cash->lock($account);

            $short = $cash->shortfall($account, $amount, $on ?? $voucher->trx_date?->toDateString());

            if ($short !== null) {
                throw ValidationException::withMessages([
                    'lines' => __('accounts::validation.not_enough_money_in', [
                        'account' => $account->label(),
                        'held' => Money::format(bcsub($amount, $short, 4)),
                        'amount' => Money::format($amount),
                    ]),
                ]);
            }
        }
    }

    /**
     * যে মাধ্যমে টাকা এল বা গেল, টাকাটা সেই ধরনের খাতেই — ২৭ সেপ্টেম্বর ২০২৬।
     *
     * ⛔ লাইভে TCL-এ ধরা: "নগদ" বাছা, আর নগদ মূলধন বসল ১১০৫-০১ বিকাশে।
     * সার্ভার মাধ্যম আর খাতের ধরন কখনো মেলাত না, তাই দিনশেষে নগদ মেলে না
     * আর বিকাশে এমন টাকা দেখায় যা কখনো আসেনি।
     *
     * ⓘ কেবল পর্দার পাঁচটা মাধ্যমে (`Voucher::INSTRUMENTS`)। ⚠️ কাউন্টার
     * এই ঘরে পেমেন্ট-পদ্ধতির **কোড** বসায় (যেমন `CHQ`, `BKASH`) — ওগুলো এই
     * তালিকায় নেই, তাই কাউন্টারের পথ অপরিবর্তিত।
     */
    private function assertTheWayMatchesTheAccount(Voucher $voucher): void
    {
        $way = (string) $voucher->instrument;

        if (! in_array($way, Voucher::INSTRUMENTS, true)
            || ! in_array($voucher->type, [Voucher::RECEIPT, Voucher::PAYMENT, Voucher::EXPENSE], true)) {
            return;
        }

        $money = $voucher->lines
            ->map(fn (VoucherLine $line) => $line->account)
            ->first(fn (?Account $a) => $a !== null && $a->money_kind !== null);

        if ($money === null) {
            return;
        }

        $fits = match ($way) {
            'cash' => $money->isCash(),
            'mfs' => $money->isMfs(),
            default => $money->isBank(),   // transfer · cheque · card
        };

        if (! $fits) {
            throw ValidationException::withMessages([
                'instrument' => __('accounts::validation.way_does_not_fit_account', [
                    'way' => __('accounts::instrument.'.$way),
                    'account' => $money->label(),
                ]),
            ]);
        }
    }

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

        /*
         * ⛔ অর্থহীন নম্বর নয় — ২৭ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ নম্বরটা পোস্টের মুহূর্তে চাওয়ার কারণই ছিল যে আগে চাইলে মানুষ
         * `0` বসিয়ে এগিয়ে যেতেন। ⚠️ তাই এখানেও সেই ফাঁক বন্ধ: অন্তত চারটা
         * অক্ষর বা অঙ্ক, আর কেবল শূন্য বা চিহ্ন নয়। ⓘ দুইবার ব্যবহারের
         * পাহারা নিচে আগের মতোই।
         */
        $meaningful = preg_replace('/[^\p{L}\p{N}]/u', '', $reference);

        if (mb_strlen($meaningful) < 4 || preg_match('/^0+$/', $meaningful) === 1) {
            throw ValidationException::withMessages([
                'instrument_no' => __('accounts::validation.bank_reference_meaningless', [
                    'reference' => $reference,
                ]),
            ]);
        }

        /*
         * ⛔ সব শাখা জুড়ে — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️১৩)। ⓘ টাকার খাতা আর অনন্যতার ইনডেক্স গোটা কোম্পানির, অথচ খোঁজ
         * ছিল দেখার শাখার দেয়ালে: অন্য শাখার ভাউচারে একই TrxID থাকলে পরিষ্কার বার্তার বদলে ডেটাবেসের ভাঙা ভুল আসত।
         */
        $twin = Voucher::acrossBranches()
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
     * ⭐ যে খাতগুলো কারো নামে বসে, আর কোন ধরনের পক্ষ — খাতের নিজের "পক্ষ রাখে" ধর্ম থেকে (cb, অডিট হিসাব ⚠️১২, 9a5d265e;
     * fe-র "এক সত্য", ৭ অক্টোবর ২০২৬)।
     *
     * ⓘ দুই জায়গায় খাটে: পোস্টে মাথার পক্ষ কোন সারিতে নামে ([[ledgerLines()]] — কেবল যে খাত ঐ ধরন নেয়), আর হাতে লেখা জাবেদায়
     * "পক্ষ লাগবে" ([[assertTemplate()]])। ⛔ আগে এখানে চারটা পরিবারের নিজের তালিকা ছিল (১১১০, ২১১০, ১১৭০, ১১৩১) — খাতের
     * ধর্ম আর এই তালিকা একদিন আলাদা হতো। ⓘ ধর্ম গ্রুপসহ ফেরে; গ্রুপে দাখিলা বসেই না ([[assertLinesArePostable()]])।
     *
     * @return array<int, list<string>> খাতের id => যে ধরনের পক্ষ নেয়
     */
    private function accountsThatHoldAParty(): array
    {
        // ⓘ দল বাদ — দলে দাখিলা বসেই না, আর সারির খাত সবসময় ঘর ([[MoneyNeverLandsOnAGroupAccountTest]])
        return Account::query()->holdingParty()->postable()->get(['id', 'party_types'])
            ->mapWithKeys(fn (Account $a) => [(int) $a->id => array_values((array) $a->party_types)])
            ->all();
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

    /**
     * ১১০৪ "হাতে চেক" কেবল কাউন্টার আর রেজিস্টার ভরে — হাতের ভাউচার নয়, ২৮ সেপ্টেম্বর ২০২৬।
     *
     * ⛔ ফাঁকটা: [[assertNoChequeReceived()]] কেবল আদায়ের "চেক" মাধ্যম দেখে।
     * জাবেদায় সরাসরি Dr ১১০৪ লিখলে বা অন্য মাধ্যম বেছে ১১০৪-এ টাকা নিলে
     * খাতায় "হাতে চেক" বাড়ত, অথচ রেজিস্টারে কোনো চেক নেই — জমা, পাশ
     * বা ফেরতের কোনো বোতাম ওই টাকা কোনোদিন ছুঁতে পারত না।
     *
     * ⓘ ছাড় কেবল কাউন্টারের (`origin` = counter): ওর পোস্টের পরেই চেকটা
     * রেজিস্টারে ওঠে ([[DirectSaleService::postCounterVoucher()]])। রেজিস্টার
     * নিজে খাতায় লেখে PostingEngine দিয়ে, এই দরজা দিয়ে নয়।
     */
    private function assertNoChequeInHandByHand(Voucher $voucher): void
    {
        if ($voucher->origin === Voucher::ORIGIN_COUNTER) {
            return;
        }

        $intoChequesInHand = $voucher->lines->contains(
            fn (VoucherLine $line) => $line->account?->code === StandardChart::CHEQUES_IN_HAND
                && bccomp((string) $line->debit, '0', 4) > 0,
        );

        if ($intoChequesInHand) {
            throw ValidationException::withMessages([
                'lines' => __('accounts::validation.cheque_only_through_register'),
            ]);
        }
    }

    /**
     * রসিদ ভাউচারে চেক নয় — মালিকের নিয়ম, ২৬ সেপ্টেম্বর ২০২৬।
     *
     * ⛔ আগে "চেক" বাছলে টাকা সেদিনই সরাসরি ব্যাংকে বা নগদে বসত, চেকের
     * খাতায় কোনো সারি ছাড়া — পাশের অপেক্ষা নেই, ফেরত লেখার উপায়ও নেই।
     * ⭐ গৃহীত চেক ঢোকে কেবল চেকের খাতা দিয়ে ([[ChequeService::create()]]),
     * আর খাতায় বসে পাশের দিন। ⓘ নিজের দেওয়া চেক (পরিশোধ) আগের মতোই চলে।
     */
    private function assertNoChequeReceived(string $type, mixed $instrument): void
    {
        if ($type === Voucher::RECEIPT && $instrument === 'cheque') {
            throw ValidationException::withMessages([
                'instrument' => __('accounts::validation.cheque_only_through_register'),
            ]);
        }
    }

    /**
     * ⭐ ধরনের ছাঁচ — ভাউচারের আন্তর্জাতিক পরিকল্পনা, অংশ ৩ক (৭ অক্টোবর ২০২৬)।
     *
     *   জাবেদা  — নগদ/ব্যাংক/মোবাইল খাত নয় (টাকা নড়লে সেটা কনট্রা, আদায় বা পরিশোধ)
     *   কনট্রা  — কেবল নগদ/ব্যাংক/মোবাইল খাত (নিজের টাকা এক জায়গা থেকে আরেক জায়গায়); ছাড় কেবল বদলির চার্জের খাত
     *   আদায়   — টাকা ঢোকে: অন্তত একটা টাকার খাতে ডেবিট, কোনো টাকার খাতে ক্রেডিট নয়
     *   পরিশোধ  — টাকা বেরোয়: অন্তত একটা টাকার খাতে ক্রেডিট, কোনো টাকার খাতে ডেবিট নয়
     *   খরচ    — টাকা বেরোয় **অথবা** দেনা তৈরি হয় (পর্দার "বাকিতে" দল; পক্ষ বাধ্যতামূলক, [[VoucherRequest]]), টাকার খাতে
     *            ডেবিট কোনোটাতেই নয় (fe, ৭ অক্টোবর ২০২৬: বিকল্প ক১ — বাকিতে সেবার খরচ আন্তর্জাতিক নিয়মে সরবরাহকারীর বিল)
     *
     * ⭐ আর জাবেদায় পাওনা ও দেনার নিয়ন্ত্রণ-খাতের প্রতিটা সারি কারো নামে — সারিতে বা মাথায় পক্ষ (sub-ledger নিয়ম, fe, ৭
     * অক্টোবর ২০২৬)। ⛔ নাহলে মালিকহীন দেনা বা পাওনা বসে: প্রদেয়ের তালিকায় টাকা আছে, কাকে দিতে হবে লেখা নেই।
     *
     * ⛔ ভুল ধরনে নগদ-প্রবাহ আর রিপোর্ট ভুল হয়। ⓘ কেবল **হাতে লেখা** নতুন ও খসড়া ভাউচারে ([[create()]], [[update()]] `byHand`,
     * ভাউচারের পর্দা থেকে) — আজকের পাকা ভাউচার আর তার সংশোধন যেমন আছে থাকে; ব্যবস্থার নিজের পোস্টিং (নগদ গণনার ঘাটতি,
     * আন্তঃকোম্পানি, ভাড়ার জমা থেকে কাটা, চার্জসহ বদলি) তার সেবার নিজের ছাঁচে।
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array{0: mixed, 1: mixed}  $headerParty  মাথার পক্ষ (`party_type`, `party_id`) — জাবেদার সারি পক্ষ না বললে এটা নামে
     */
    private function assertTemplate(string $type, array $lines, array $headerParty = [null, null]): void
    {
        $ids = collect($lines)->pluck('account_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $money = Account::query()->whereKey($ids)->whereNotNull('money_kind')->pluck('id')->map(fn ($id) => (int) $id)->all();

        // ⓘ বদলির চার্জ — কনট্রার নিজের খরচ-সারি (ব্যাংক ৫২১০, মোবাইল ৫২১১), পর্দার "চার্জ" ঘর থেকে
        $charges = Account::query()->whereKey($ids)->whereIn('code', [StandardChart::BANK_CHARGES, StandardChart::MFS_CHARGES])
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $otherThanCharge = false;

        $moneyIn = '0';
        $moneyOut = '0';
        $nonMoney = false;

        foreach ($lines as $line) {
            $id = (int) ($line['account_id'] ?? 0);

            if ($id === 0) {
                continue;
            }

            if (in_array($id, $money, true)) {
                $moneyIn = bcadd($moneyIn, $this->money($line['debit'] ?? 0), 4);
                $moneyOut = bcadd($moneyOut, $this->money($line['credit'] ?? 0), 4);
            } else {
                $nonMoney = true;
                $otherThanCharge = $otherThanCharge || ! in_array($id, $charges, true);
            }
        }

        // ⓘ দেনার পরিবারে ক্রেডিট — খরচের "বাকিতে" পথ (ক১)
        $owed = $this->controlAccounts([StandardChart::PAYABLE_GROUP]);
        $owesSomeone = collect($lines)->contains(fn (array $l) => in_array((int) ($l['account_id'] ?? 0), $owed, true)
            && bccomp($this->money($l['credit'] ?? 0), '0', 4) > 0);

        $broken = match ($type) {
            Voucher::JOURNAL => $money !== [] ? 'journal' : null,
            Voucher::CONTRA => $nonMoney && $otherThanCharge ? 'contra' : null,
            Voucher::RECEIPT => bccomp($moneyIn, '0', 4) <= 0 || bccomp($moneyOut, '0', 4) > 0 ? 'receipt' : null,
            Voucher::PAYMENT => bccomp($moneyOut, '0', 4) <= 0 || bccomp($moneyIn, '0', 4) > 0 ? 'payment' : null,
            Voucher::EXPENSE => bccomp($moneyIn, '0', 4) > 0 || (bccomp($moneyOut, '0', 4) <= 0 && ! $owesSomeone) ? 'expense' : null,
            default => null,
        };

        if ($broken !== null) {
            throw ValidationException::withMessages(['lines' => __('accounts::voucher.template_'.$broken)]);
        }

        if ($type !== Voucher::JOURNAL) {
            return;
        }

        /*
         * ⓘ যে খাতগুলো কারো নামে বসে — পাওনা, দেনা, হাতধার, কর্মীর অগ্রিম (fe, ৭ অক্টোবর ২০২৬)। ⭐ খাতার সারিতে পক্ষ বসানোর
         * একই তালিকা ([[accountsThatHoldAParty()]]) — দুই তালিকা একদিন আলাদা হতো। ⓘ cb-র খাতের "পক্ষ রাখে" ধর্ম এলে ওটাই পড়বে।
         */
        $control = array_keys($this->accountsThatHoldAParty());
        $headerHasParty = filled($headerParty[0] ?? null) && filled($headerParty[1] ?? null);

        foreach ($lines as $line) {
            $id = (int) ($line['account_id'] ?? 0);

            if ($headerHasParty || ! in_array($id, $control, true)
                || (filled($line['party_type'] ?? null) && filled($line['party_id'] ?? null))) {
                continue;
            }

            throw ValidationException::withMessages(['lines' => __('accounts::voucher.template_control_needs_party', [
                'account' => (string) Account::query()->whereKey($id)->first()?->label(),
            ])]);
        }
    }

    /**
     * পাওনা/দেনার নিয়ন্ত্রণ-খাতের পরিবার — id-গুলো ([[accountsThatHoldAParty()]]-এর মতো পুরো বংশ)।
     *
     * @param  list<string>  $codes
     * @return list<int>
     */
    private function controlAccounts(array $codes): array
    {
        $ids = [];

        foreach ($codes as $code) {
            foreach (StandardChart::find($code)?->selfAndDescendants() ?? [] as $account) {
                $ids[] = (int) $account->id;
            }
        }

        return $ids;
    }

    /**
     * ⭐ লেখক ≠ পাকাকারী (maker-checker) — ভাউচারের আন্তর্জাতিক পরিকল্পনা, অংশ ৩গ (৭ অক্টোবর ২০২৬)।
     *
     * ⓘ সুইচ `accounts.voucher_maker_checker` চালু থাকলে যিনি ভাউচারটা লিখেছেন (`created_by`) তিনি নিজে পাকা করেন না —
     * পাকা করেন অন্য কেউ। ⓘ মালিক (সুপার অ্যাডমিন) একা করলে আটকায় না, নিরীক্ষায় "নিজের লেখা নিজে পাকা" দাগ পড়ে।
     * সুইচ বন্ধে আজকের আচরণ অবিকল।
     */
    public function writerMayNotPost(Voucher $voucher): bool
    {
        if (! (bool) app(SettingsService::class)->get(self::MAKER_CHECKER, true)) {
            return false;
        }

        $actor = (int) (Actor::userId() ?? 0);

        return $actor !== 0 && $actor === (int) ($voucher->created_by ?? 0) && ! $this->actorIsOwner();
    }

    private function assertAnotherHandPosts(Voucher $voucher): void
    {
        if ($this->writerMayNotPost($voucher)) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.maker_checker', ['no' => $voucher->document_no]),
            ]);
        }

        if ((bool) app(SettingsService::class)->get(self::MAKER_CHECKER, true)
            && (int) (Actor::userId() ?? 0) === (int) ($voucher->created_by ?? 0)) {
            app(AuditEngine::class)->recordAction($voucher, 'maker_checker_override',
                __('accounts::validation.maker_checker', ['no' => $voucher->document_no]));
        }
    }

    private function actorIsOwner(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->roles->contains('name', PermissionSyncer::SUPER_ADMIN_ROLE);
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
        // ⭐ অন্য শাখার টিলের খাত বাদ (৩০ সেপ্টেম্বর ২০২৬) — [[Account::scopeNotAnotherBranchsTill()]]
        return Account::query()->notAnotherBranchsTill()->money()->postable()->active()->orderBy('code')->get();
    }
}
