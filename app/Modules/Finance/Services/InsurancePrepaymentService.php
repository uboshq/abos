<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Concerns\ReadsTheRowUnderLock;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Models\InsurancePremium;
use App\Modules\Finance\Models\InsurancePrepayment;
use App\Modules\Finance\Support\ActingBranches;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ বীমার প্রিমিয়াম মাসে মাসে খরচ — অর্থ-মডিউলের পরিকল্পনা ৬.৩, ৬ অক্টোবর ২০২৬ (সমন্বয়কের উত্তর প্র২: অগ্রিম বীমা,
 * উল্টো দাখিলায়; সই নিয়ে সমন্বয়কের সিদ্ধান্ত ক)।
 *
 * ── ⭐ উল্টো দাখিলার নিয়ম (reversing entries) ─────────────────────────────────
 * প্রিমিয়াম দেওয়ার দিন পুরোটা খরচে বসে, যেমন বসত — পরিশোধ ভাউচার বদলায় না। মাস শেষে মেয়াদের যে অংশ এখনো আসেনি,
 * সেটা খরচ থেকে অগ্রিমে সরে: Dr 1136 অগ্রিম বীমা / Cr খরচের খাত, মাসের শেষ দিনে। পরের মাসের প্রথম দিনে ঠিক উল্টো। ফল:
 * প্রতিটা মাসে খরচ = সেই মাসে মেয়াদের যত দিন গেল তার ভাগ (IAS 1-এর জমা-ভিত্তি), আর মাস শেষের উদ্বৃত্তপত্রে না-যাওয়া
 * অংশটা সম্পদ।
 *
 * ── অঙ্ক ──────────────────────────────────────────────────────────────────────
 *   অগ্রিম = কিস্তির অঙ্ক × (মাস শেষের পরে মেয়াদের দিন) ÷ (মেয়াদের মোট দিন), পয়সায় গোল; মেয়াদ মাস শেষের পরে শুরু হলে
 *   পুরো কিস্তি। ⓘ কেবল দেওয়া কিস্তি — যার পরিশোধ ভাউচার খাতায় আর মাস শেষের আগে বা সেদিন।
 *
 * ── ⭐ খরচের খাত পরিশোধ ভাউচারের, ছকের নয় ─────────────────────────────────
 * পরিশোধ ভাউচারে ব্যবহারকারী নিজে খাত বাছেন (প্রায়ই 5221, কখনো অন্য)। অগ্রিম সেই খাত থেকেই সরে — নইলে 5221 ঋণাত্মক
 * হত আর আসল খাতটা পুরো খরচ দেখাত। খাত = টাকার খাত নয় এমন সবচেয়ে বড় ডেবিট সারি (ব্যাংক চার্জের সারি ছোট, তাই বাদ
 * পড়ে)। ⛔ সেটা খরচের খাত না হলে (যেমন সরাসরি অগ্রিমে বা দেনায় বসানো) কিস্তিটা বাদ — খরচেই যা নেই, তা সরানো যায় না।
 *
 * ── ⛔ পাহারা ───────────────────────────────────────────────────────────────────
 *   · এক কিস্তিতে এক মাস একবারই (অনন্য সূচক + সারিতে তালা); · কেবল শেষ হওয়া মাস; · বন্ধ মাস বা পেছনের জানালার বাইরে
 *   খাতা নিজেই থামায় ([[OpenPeriod::assertOpen()]], [[PostingEngine]]) — আর গোটা মাস এক লেনদেনে, তাই আধা-বসা হয় না;
 *   · সই নেই (সমন্বয়কের সিদ্ধান্ত ক): আগেই সই পেয়ে দেওয়া খরচের ভাগ মাত্র, নগদ নড়ে না — বোতাম কেবল বীমা চালানোর চাবিতে।
 */
final class InsurancePrepaymentService
{
    use ReadsTheRowUnderLock;

    public function __construct(private readonly VoucherService $vouchers) {}

    /**
     * এই মাস শেষে কোন কিস্তির কত অগ্রিম — কিছু না লিখে।
     *
     * @return list<array{premium: InsurancePremium, policy: InsurancePolicy, days_left: int, days_total: int,
     *     amount: string, expense_account_id: int, done: bool}>
     */
    public function preview(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth()->startOfDay();
        $done = InsurancePrepayment::query()->where('for_month', $start->toDateString())->pluck('premium_id')
            ->map(fn ($id) => (int) $id)->all();
        $rows = [];

        $premiums = InsurancePremium::query()
            ->where('status', InsurancePremium::POSTED)
            ->whereNotNull('voucher_id')
            ->where('period_to', '>', $end->toDateString())
            // ⛔ নাগালের শাখা, হেডারের নয় — উল্টো দাখিলার একই সারি ([[reverseBefore()]], [[ActingBranches]]; পুনঃঅডিট, ৯ অক্টোবর ২০২৬)
            ->whereIn('policy_id', ActingBranches::narrow(InsurancePolicy::query(), 'fin_insurance_policies.branch_id')->select('id'))
            // ⓘ ভাউচারের শাখার দেয়াল ছাড়া — নইলে হেডারের শাখাই আবার ফিরে আসত, অন্য শাখার কিস্তি বাদ পড়ত
            ->whereHas('voucher', fn ($q) => $q->withoutGlobalScope('user-branch')->whereIn('status', DocumentStatus::POSTED)->where('trx_date', '<=', $end->toDateString()))
            ->with(['policy', 'voucher' => fn ($q) => $q->withoutGlobalScope('user-branch'), 'voucher.lines.account'])
            ->orderBy('period_from')->orderBy('id')->get();

        foreach ($premiums as $premium) {
            $account = $this->expenseLine($premium->voucher);

            if ($account === null) {
                continue;
            }

            $from = $premium->period_from->copy()->startOfDay();
            $to = $premium->period_to->copy()->startOfDay();
            $total = (int) $from->diffInDays($to) + 1;
            $left = (int) ($from->gt($end) ? $total : $end->diffInDays($to));
            $amount = $left >= $total
                ? bcadd((string) $premium->amount, '0', 2)
                : bcdiv(bcmul((string) $premium->amount, (string) $left, 8), (string) $total, 2);

            if ($left < 1 || bccomp($amount, '0', 2) <= 0) {
                continue;
            }

            $rows[] = [
                'premium' => $premium,
                'policy' => $premium->policy,
                'days_left' => $left,
                'days_total' => $total,
                'amount' => $amount,
                'expense_account_id' => $account,
                'done' => in_array((int) $premium->id, $done, true),
            ];
        }

        return $rows;
    }

    /**
     * ⭐ মাসটা বসানো — আগের মাসগুলোর না-উল্টানো অগ্রিম আগে উল্টায়, তারপর এই মাসের প্রতিটা কিস্তির অগ্রিম (যেটা এখনো
     * বসেনি)। গোটা কাজ এক লেনদেনে: বন্ধ মাসে খাতা থামালে কিছুই বসে না।
     *
     * @return array{prepaid: int, reversed: int}
     */
    public function run(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth()->startOfDay();

        if ($end->gte(Carbon::today())) {
            throw ValidationException::withMessages([
                'month' => __('finance::insurance.prepaid_month_not_over'),
            ]);
        }

        return DB::transaction(function () use ($month, $start, $end): array {
            $reversed = $this->reverseBefore($start);
            $prepaid = 0;
            $asset = $this->account(StandardChart::PREPAID_INSURANCE);

            foreach ($this->preview($month) as $row) {
                if ($row['done']) {
                    continue;
                }

                $premium = $row['premium'];
                $this->lockFresh($premium);

                // ⛔ তালার পরে আবার দেখা — একই সময়ে দুইজন একই মাস চালালেও একবারই
                if (InsurancePrepayment::query()->where('premium_id', $premium->id)->where('for_month', $start->toDateString())->exists()) {
                    continue;
                }

                $policy = $row['policy'];
                $paper = InsurancePrepayment::query()->create([
                    'company_id' => CompanyContext::id(),
                    'branch_id' => $policy->branch_id,
                    'policy_id' => $policy->id,
                    'premium_id' => $premium->id,
                    'for_month' => $start->toDateString(),
                    'days_left' => $row['days_left'],
                    'days_total' => $row['days_total'],
                    'amount' => $row['amount'],
                    'expense_account_id' => $row['expense_account_id'],
                    'created_by' => auth()->id(),
                ]);

                $voucher = $this->vouchers->create([
                    'type' => Voucher::JOURNAL,
                    'is_adjusting' => true, // ⭐ মাসশেষের সমন্বয় (ভাউচারের পরিকল্পনা ৩ঘ, ৭ অক্টোবর ২০২৬)
                    // ⛔ পলিসির শাখায় — হেডারের শাখায় নয় (পুনঃঅডিট, ৯ অক্টোবর ২০২৬)
                    'branch_id' => $policy->branch_id,
                    'trx_date' => $end->toDateString(),
                    'narration' => __('finance::insurance.prepaid_narration', [
                        'month' => $start->translatedFormat('F Y'), 'policy' => $policy->policy_no,
                        'left' => $row['days_left'], 'total' => $row['days_total'],
                    ]),
                    'against_type' => InsurancePrepayment::drillSourceType(),
                    'against_id' => $paper->id,
                ], [
                    ['account_id' => $asset->id, 'debit' => $row['amount'], 'credit' => '0'],
                    ['account_id' => $row['expense_account_id'], 'debit' => '0', 'credit' => $row['amount']],
                ]);

                // ⓘ সই নেই — সমন্বয়কের সিদ্ধান্ত ক (৬ অক্টোবর ২০২৬): আগেই সই পাওয়া খরচের ভাগ, নগদ নড়ে না
                $this->vouchers->post($voucher);
                $paper->forceFill(['voucher_id' => $voucher->id])->save();
                $prepaid++;
            }

            return ['prepaid' => $prepaid, 'reversed' => $reversed];
        });
    }

    /** আগের মাসগুলোর খাতায় বসা অথচ না-উল্টানো অগ্রিম — পরের মাসের প্রথম দিনে উল্টো দাখিলা */
    private function reverseBefore(Carbon $start): int
    {
        $count = 0;
        $asset = null;

        // ⛔ আগাম দেখার একই শাখাগুলো ([[preview()]]) — হেডারে এক শাখা বাছা থাকলেও বাকি শাখার অগ্রিম নীরবে উল্টাত না
        $open = ActingBranches::narrow(InsurancePrepayment::query(), 'fin_insurance_prepayments.branch_id')
            ->whereNull('reversal_voucher_id')
            ->where('for_month', '<', $start->toDateString())
            ->with(['voucher' => fn ($q) => $q->withoutGlobalScope('user-branch'), 'policy'])
            ->orderBy('for_month')->orderBy('id')->get();

        foreach ($open as $paper) {
            if ($paper->voucher === null || ! $paper->voucher->isPosted()) {
                continue;
            }

            $this->lockFresh($paper);

            if ($paper->reversal_voucher_id !== null) {
                continue;
            }

            $asset ??= $this->account(StandardChart::PREPAID_INSURANCE);
            $amount = bcadd((string) $paper->amount, '0', 2);

            $reversal = $this->vouchers->create([
                'type' => Voucher::JOURNAL,
                'is_adjusting' => true, // ⭐ মাসশেষের সমন্বয় (ভাউচারের পরিকল্পনা ৩ঘ, ৭ অক্টোবর ২০২৬)
                'branch_id' => $paper->branch_id,
                'trx_date' => $paper->for_month->copy()->endOfMonth()->addDay()->toDateString(),
                'narration' => __('finance::insurance.prepaid_reversal_narration', [
                    'month' => $paper->for_month->translatedFormat('F Y'), 'policy' => (string) $paper->policy?->policy_no,
                ]),
                'against_type' => InsurancePrepayment::drillSourceType(),
                'against_id' => $paper->id,
            ], [
                ['account_id' => $paper->expense_account_id, 'debit' => $amount, 'credit' => '0'],
                ['account_id' => $asset->id, 'debit' => '0', 'credit' => $amount],
            ]);

            $this->vouchers->post($reversal);
            $paper->forceFill(['reversal_voucher_id' => $reversal->id])->save();
            $count++;
        }

        return $count;
    }

    /**
     * পরিশোধ ভাউচারের খরচের খাত — টাকার খাত নয় এমন সবচেয়ে বড় ডেবিট সারি; সেটা খরচের খাত না হলে (অগ্রিম বীমা বা
     * অন্য সম্পদ, দেনা) `null`।
     */
    private function expenseLine(?Voucher $voucher): ?int
    {
        $line = $voucher?->lines
            ->filter(fn (VoucherLine $l) => bccomp((string) $l->debit, '0', 4) > 0 && $l->account !== null && ! $l->account->isMoney())
            ->reduce(fn (?VoucherLine $best, VoucherLine $l) => $best === null || bccomp((string) $l->debit, (string) $best->debit, 4) > 0 ? $l : $best);

        if ($line === null || $line->account->type !== Account::EXPENSE) {
            return null;
        }

        return (int) $line->account_id;
    }

    /** ছকের খাত — না থাকলে (পুরনো কোম্পানি) ছক একবার বসিয়ে নেয়; [[StandardChart::install()]] কেবল যা নেই তা-ই বসায় */
    private function account(string $code): Account
    {
        $account = StandardChart::find($code);

        if ($account === null) {
            app(StandardChart::class)->install();
            $account = StandardChart::find($code);
        }

        if ($account === null) {
            throw ValidationException::withMessages([
                'month' => __('finance::validation.chart_head_missing', ['code' => $code]),
            ]);
        }

        return $account;
    }
}
