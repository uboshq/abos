<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Concerns\ReadsTheRowUnderLock;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\FinancialYear;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashCount;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * নগদ গণনা — হাতে যা আছে, খাতায় যা থাকার কথা।
 *
 * মিললে কোনো এন্ট্রি হয় না, শুধু রেকর্ড থাকে যে ওই দিন গোনা হয়েছিল।
 *
 * না মিললে পার্থক্যটা একটা জাবেদা হয়ে বসে — কারণ খাতার সংখ্যাটা তখন
 * মিথ্যা, আর মিথ্যা রেখে দিলে পরদিনের গণনাও মিলবে না, আর কোন দিনের
 * ভুল তা আর বলা যাবে না। কম পড়লে সেটা খরচ; বেশি হলে অন্যান্য আয়।
 * দুইটাই কাউকে দোষ না দিয়ে হিসাবটা সত্যি রাখে।
 */
final class CashCountService
{
    use ReadsTheRowUnderLock;

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly VoucherService $vouchers,
        private readonly DocumentApproval $approvals,
    ) {}

    /**
     * গণনা সংরক্ষণ — এখনো কোনো সমন্বয় হয় না।
     *
     * @param  array<string, mixed>  $data
     * @param  array<int|string, int|string|null>  $counts  নোটের সংখ্যা
     */
    public function record(array $data, array $counts): CashCount
    {
        return DB::transaction(function () use ($data, $counts) {
            $till = CashTill::query()->find($data['cash_till_id'] ?? null);

            if ($till === null) {
                throw ValidationException::withMessages([
                    'cash_till_id' => __('accounts::validation.till_not_found'),
                ]);
            }

            $trxDate = Carbon::parse($data['trx_date'] ?? now());

            // গোনা টাকাটা নোটের হিসাব থেকেই, ব্যবহারকারীর লেখা মোট থেকে
            // নয় — নাহলে কাগজটা নিজের সাথেই অসঙ্গত হতে পারত
            $counted = CashCount::totalOf($counts);

            // খাতার সংখ্যা ওই তারিখ পর্যন্ত, আজ পর্যন্ত নয়: পুরনো তারিখের
            // গণনা লিখলে আজকের ব্যালেন্সের সাথে মেলানোটা অর্থহীন হত
            $expected = $till->balance($trxDate->toDateString());

            return CashCount::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $till->branch_id ?? CompanyContext::branchId(),
                'financial_year_id' => $this->year($trxDate)->id,
                'document_no' => $this->numbers->next('CC'),
                'trx_date' => $trxDate->toDateString(),
                'cash_till_id' => $till->id,
                'counted_amount' => $counted,
                'expected_amount' => $expected,
                'difference' => bcsub($counted, $expected, 4),
                'denominations' => $this->cleanCounts($counts),
                'narration' => $data['narration'] ?? null,
                'status' => DocumentStatus::DRAFT,
                'counted_by' => $data['counted_by'] ?? auth()->id(),
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * গণনা অনুমোদন — পার্থক্য থাকলে এখনই সমন্বয় বসে।
     *
     * অনুমোদন আলাদা ধাপ, কারণ পার্থক্য মানে কারও হাতে টাকা কম বা বেশি,
     * আর সেটা ক্যাশিয়ার নিজেই নিষ্পত্তি করে ফেললে গণনার কোনো মানে থাকে
     * না। যিনি গুনেছেন আর যিনি অনুমোদন করছেন — দুইজনের নামই থাকে।
     */
    public function approve(CashCount $count): CashCount
    {
        $this->assertNotApproved($count);
        $this->assertSomeoneElseApproves($count);

        /*
         * ⭐ অনুমোদন — মালিকের সিদ্ধান্ত, ১৮ সেপ্টেম্বর ২০২৬।
         *
         * ── ⛔ কেন গনার মানা সই চায় ──────────────────────
         * এই মুহূর্তেই গনা টাকা আর খাতার টাকার **পার্থক্যটা
         * খাতায় বসে যায়** — অর্থাৎ ঘাটতিটা ক্ষমা পেয়ে যায়।
         *
         * ⚠️ যিনি গুনলেন আর যিনি ক্ষমা করলেন — একজন হলে ক্যাশিয়ার
         * নিজেই নিজের ঘাটতি মুছে দিতে পারেন, আর কেউ জানবে না।
         *
         * ⓘ অঙ্ক হিসেবে পার্থক্যটাই যায় — মিলে গেলে শূন্য, তাই
         * সীমা বসালে মিলে যাওয়া গণনা কখনো আটকাবে না।
         */
        $this->approvals->assertClear(
            document: $count,
            module: 'accounts',
            action: 'cash_count',
            field: 'status',
            amount: ltrim((string) ($count->difference ?? '0'), '-'),
            reason: $count->narration,
        );

        return DB::transaction(function () use ($count) {
            /*
             * ⛔ সারিতে তালা দিয়ে অবস্থা আবার — ১ অক্টোবর ২০২৬ ([[ACashShortfallWasForgivenTwiceTest]])।
             * ⓘ উপরের যাচাই হাতের কপি থেকে, লেনদেনের বাইরে: পুরনো পাতা থেকে দ্বিতীয় অনুমোদনে ঘাটতির
             * সমন্বয় আবার বসত — ১,০০০ টাকার ঘাটতি ২,০০০ হয়ে মুছত।
             */
            $this->lockFresh($count);
            $this->assertNotApproved($count);

            /*
             * ⭐ এই গণনার পরে অনুমোদিত অন্য গণনার সমন্বয় বাদ — অডিট গ৭, ৪ অক্টোবর ২০২৬।
             *
             * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────
             * একই দিনে একই বাক্স দুবার গোনা হলে দুটো গণনাই একই ঘাটতি দেখায় (খাতা তখনো বদলায়নি)। প্রথমটা
             * অনুমোদনে ঘাটতি খরচে বসায়; দ্বিতীয়টা পুরনো পার্থক্য নিয়েই আবার বসাত — ১,০০০-এর ঘাটতি ২,০০০
             * হয়ে খরচে উঠত।
             * ⭐ এখন: এই গণনা লেখার পরে অন্য যে গণনা (এই বাক্স, এই তারিখ বা আগের) অনুমোদিত হয়ে খাতা বদলেছে,
             * তার সমন্বয়টা "খাতা বলে"-তে যোগ হয়, আর পার্থক্য আবার গোনা হয়। মিলে গেলে কিছুই বসে না।
             */
            $this->allowForLaterAdjustments($count);
            $this->assertTheBooksStillSayTheSame($count);

            if (! $count->matches()) {
                $count->forceFill(['adjustment_voucher_id' => $this->adjustmentFor($count)->id])->save();
            }

            $count->forceFill([
                'status' => DocumentStatus::CONFIRMED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ])->save();

            return $count->fresh();
        });
    }

    /**
     * ⛔ যিনি গুনলেন তিনি নিজের গোনা মানেন না — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️১৪; [[ACashCountIsApprovedBySomeoneElseTest]])।
     *
     * ⓘ অনুমোদনেই তফাত খাতায় বসে — ঘাটতি ক্ষমা পায়। সইয়ের ছক চালু না থাকলে আগে গণনাকারী নিজেই "মানলাম" চাপতে পারতেন, আর
     * জিম্মা চালু থাকলে সমন্বয় টিলের নিয়মে আটকাত বলে কার্যত ধারক (যিনি গোনেন) ছাড়া কেউ পারতেনও না। এখন অন্য কেউ — সমন্বয়টা
     * ব্যবস্থার কাগজ, টিলের নিয়ম পেরোয় (`origin` = [[Voucher::ORIGIN_CASH_COUNT]])। মালিক (সুপার অ্যাডমিন) আগের মতো সব পারেন,
     * সইয়ের ইঞ্জিনের একই নিয়ম।
     */
    private function assertSomeoneElseApproves(CashCount $count): void
    {
        $user = auth()->user();

        if ($user === null || (int) $count->counted_by !== (int) $user->id
            || $user->roles->contains('name', PermissionSyncer::SUPER_ADMIN_ROLE)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => __('accounts::validation.count_needs_another_approver'),
        ]);
    }

    /**
     * ⛔ গোনার পরে খাতা বদলালে অনুমোদন থামে — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️১৪)।
     *
     * ⓘ "খাতা বলে" নেওয়া হয় লেখার মুহূর্তে। পরে সেই তারিখে বা আগে কোনো ভাউচার বসলে (পেছনের তারিখের রসিদ, দেরিতে পাকা খসড়া)
     * পুরনো অঙ্কে তফাতটা ভুল — অথচ অনুমোদনে সেটাই ঘাটতি বা উদ্বৃত্ত হয়ে খাতায় বসত। নীরবে নতুন অঙ্ক নেওয়াও ঠিক নয়: গোনার পরে
     * একই দিনে বসা রসিদ তখন "ঘাটতি" হয়ে খরচে উঠত। তাই থামা, আর আবার গোনা। অন্য গণনার সমন্বয় আগেই ধরা ([[allowForLaterAdjustments()]])।
     */
    private function assertTheBooksStillSayTheSame(CashCount $count): void
    {
        $now = $count->till->balance($count->trx_date->toDateString());

        if (bccomp($now, (string) $count->expected_amount, 2) === 0) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => __('accounts::validation.count_books_moved', [
                'then' => Money::format((string) $count->expected_amount),
                'now' => Money::format($now),
            ]),
        ]);
    }

    /** অন্য গণনার পরের সমন্বয় ধরে "খাতা বলে" আর পার্থক্য নতুন করে — [[approve()]]-এর তালার ভেতরে ডাকা। */
    private function allowForLaterAdjustments(CashCount $count): void
    {
        $since = CashCount::query()
            ->where('cash_till_id', $count->cash_till_id)
            ->whereKeyNot($count->getKey())
            ->where('status', DocumentStatus::CONFIRMED)
            ->whereNotNull('adjustment_voucher_id')
            ->where('trx_date', '<=', $count->trx_date->toDateString())
            ->where('approved_at', '>=', $count->created_at)
            ->lockForUpdate()
            ->get(['difference'])
            ->reduce(fn (string $sum, CashCount $c) => bcadd($sum, (string) $c->difference, 4), '0');

        if (bccomp($since, '0', 4) === 0) {
            return;
        }

        $expected = bcadd((string) $count->expected_amount, $since, 4);

        $count->forceFill([
            'expected_amount' => $expected,
            'difference' => bcsub((string) $count->counted_amount, $expected, 4),
        ])->save();
    }

    /**
     * পার্থক্যের জাবেদা।
     *
     * কম পড়লে: খরচ ডেবিট, নগদ ক্রেডিট — টাকাটা নেই, তাই খাতা থেকেও যায়।
     * বেশি হলে: নগদ ডেবিট, অন্যান্য আয় ক্রেডিট।
     *
     * খাতগুলো কোড ধরে খোঁজা, নাম ধরে নয় — নাম বদলানো যায়, কোড নয়।
     */
    private function adjustmentFor(CashCount $count): Voucher
    {
        $cash = (int) $count->till->account_id;
        $shortage = ! $count->isSurplus();

        $other = $shortage
            ? $this->accountOr('5299', Account::EXPENSE)   // বিবিধ খরচ
            : $this->accountOr('4300', Account::INCOME);   // অন্যান্য আয়

        // ঘাটতি বা উদ্বৃত্তের অঙ্কটা ভাউচারে যাচ্ছে — তাই bcmath, float নয়
        $amount = Money::round(ltrim((string) $count->difference, '-'), 4);

        $note = __('accounts::message.count_adjustment', [
            'no' => $count->document_no,
            'till' => $count->till->name(),
        ]);

        $voucher = $this->vouchers->create(
            [
                'type' => Voucher::JOURNAL,
                'trx_date' => $count->trx_date->toDateString(),
                'narration' => $note,
                'branch_id' => $count->branch_id,
                // ⓘ ব্যবস্থার কাগজ — টিলের নিয়ম পেরোয় ([[VoucherService::assertCashLandsInOwnTill()]])
                'origin' => Voucher::ORIGIN_CASH_COUNT,
            ],
            $shortage
                ? [
                    ['account_id' => $other->id, 'debit' => $amount, 'credit' => '0', 'narration' => $note],
                    ['account_id' => $cash, 'debit' => '0', 'credit' => $amount, 'narration' => $note],
                ]
                : [
                    ['account_id' => $cash, 'debit' => $amount, 'credit' => '0', 'narration' => $note],
                    ['account_id' => $other->id, 'debit' => '0', 'credit' => $amount, 'narration' => $note],
                ],
        );

        return $this->vouchers->post($voucher);
    }

    /**
     * প্রমিত ছকের খাতটা — না থাকলে থামা।
     *
     * ⛔ আগে না পেলে ওই ধরনের কোড-ক্রমে প্রথম খাতে বসত — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️১৪)। ⓘ ঘাটতি তখন "ভাড়া" বা
     * "বেতন"-এর মতো যেকোনো খাতে উঠত, আর কেউ খুঁজে পেত না। এখন খাতের কোড বলে থামে — ছকে যোগ করে আবার অনুমোদন।
     */
    private function accountOr(string $code, string $type): Account
    {
        $account = StandardChart::find($code);

        if ($account !== null && ! $account->is_group && $account->is_active) {
            return $account;
        }

        throw ValidationException::withMessages([
            'difference' => __('accounts::validation.adjustment_account_missing', ['code' => $code, 'type' => __('accounts::type.'.$type)]),
        ]);
    }

    /**
     * শুধু ধনাত্মক সংখ্যাগুলো, নোট অনুসারে সাজানো।
     *
     * @param  array<int|string, int|string|null>  $counts
     * @return array<int, int>
     */
    private function cleanCounts(array $counts): array
    {
        $out = [];

        foreach (CashCount::DENOMINATIONS as $note) {
            $qty = (int) ($counts[$note] ?? 0);

            if ($qty > 0) {
                $out[$note] = $qty;
            }
        }

        return $out;
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

    private function assertNotApproved(CashCount $count): void
    {
        if ($count->isApproved()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.count_already_approved'),
            ]);
        }
    }
}
