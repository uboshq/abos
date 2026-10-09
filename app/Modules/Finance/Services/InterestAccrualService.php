<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Concerns\ReadsTheRowUnderLock;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\InterestAccrual;
use App\Modules\Finance\Support\ActingBranches;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ ব্যাংক ঋণের মাসিক সুদ জমা — অর্থ-মডিউলের পরিকল্পনা ৩.৩, ৬ অক্টোবর ২০২৬: "মাস শেষে সুদ খরচ নিজে বসে, ব্যাংক কাটার
 * আগেই" (সমন্বয়কের অনুমোদিত নকশা ক)।
 *
 * ── ⭐ উল্টো দাখিলার নিয়ম (reversing entries) ─────────────────────────────────
 * মাস শেষে যে সুদ জমেছে কিন্তু ব্যাংক এখনো কাটেনি: Dr ৫৩১০ সুদ খরচ / Cr ২১৪৫ প্রদেয় সুদ, মাসের শেষ দিনে। পরের মাসের প্রথম
 * দিনে ঠিক উল্টো (Dr ২১৪৫ / Cr ৫৩১০)। ব্যাংক যেদিন কিস্তি কাটে, সুদ আগের মতোই খরচে বসে — কিস্তির ভাউচার বদলাতে হয় না,
 * আর খরচ একবারই পড়ে: প্রতিটা মাসে খরচ = সেই মাসের দেওয়া সুদ − আগের মাসের জমা + এই মাসের জমা, অর্থাৎ যে মাসে সুদ জমল সেই
 * মাসে (IAS 1 / IFRS 9-এর জমা-ভিত্তি)।
 *
 * ── অঙ্ক ──────────────────────────────────────────────────────────────────────
 *   · মেয়াদি / LTR / লিজ — মাসের শেষ দিনে খাতার বাকি আসল × হার × (শেষ কিস্তির দিন বা মঞ্জুরি থেকে মাসের শেষ পর্যন্ত দিন) ÷ ৩৬৫
 *   · CC — মাসের প্রতিটা দিনের তোলা জের × হার ÷ ৩৬৫, যোগ (দৈনিক জের)
 *   জের আসে [[BankFacilityService::owedOn()]] থেকে — ঋণের পাতা, রিপোর্ট আর বিবরণী যেটা পড়ে।
 *
 * ── ⛔ পাহারা ───────────────────────────────────────────────────────────────────
 *   · এক ঋণে এক মাস একবারই (অনন্য সূচক + সারিতে তালা); · কেবল শেষ হওয়া মাস; · ছকের সই মানে — ঋণের নিজের সইয়ের ছক
 *   ([[FinanceSignature::BANK_FACILITY]]); ছক বন্ধ থাকলে (UB) সাথে সাথে খাতায়। উল্টো দাখিলা জমার নিজের জোড়া, তাই আলাদা
 *   সই চায় না।
 */
final class InterestAccrualService
{
    use ReadsTheRowUnderLock;

    public function __construct(
        private readonly BankFacilityService $facilities,
        private readonly VoucherService $vouchers,
        private readonly FinanceSignature $signature,
    ) {}

    /**
     * এই মাসে কোন ঋণে কত সুদ জমবে — কিছু না লিখে (পর্দার আগাম দেখা, আর [[run()]]-এর হিসাব)।
     *
     * @return list<array{facility: BankFacility, days: int, base: string, rate: string, amount: string, done: bool}>
     */
    public function preview(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth()->startOfDay();
        $done = InterestAccrual::query()->where('for_month', $start->toDateString())->pluck('bank_facility_id')
            ->map(fn ($id) => (int) $id)->all();
        $rows = [];

        /*
         * ⛔ নাগালের শাখা, হেডারের নয় — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ ([[ActingBranches]])। ⓘ আগে এক শাখা বাছা থাকলে নতুন
         * জমা বসত কেবল সেই শাখায়, অথচ [[reverseBefore()]] সব শাখার আগের জমা উল্টাত — বাকি শাখার সুদ সে মাসে খরচ থেকে উধাও।
         */
        $facilities = ActingBranches::narrow(BankFacility::query()->live(), 'fin_bank_facilities.branch_id')
            ->where('kind', '!=', BankFacility::GUARANTEE)
            ->where('interest_rate', '>', 0)
            ->where(fn ($q) => $q->whereNull('sanctioned_on')->orWhere('sanctioned_on', '<=', $end->toDateString()))
            ->orderBy('id')->get();

        foreach ($facilities as $facility) {
            $rate = bcadd((string) $facility->interest_rate, '0', 4);
            [$days, $base, $amount] = $facility->kind === BankFacility::CC
                ? $this->daily($facility, $start, $end, $rate)
                : $this->sinceLastInstalment($facility, $end, $rate);

            if ($days < 1 || bccomp($amount, '0', 2) <= 0) {
                continue;
            }

            $rows[] = [
                'facility' => $facility,
                'days' => $days,
                'base' => $base,
                'rate' => $rate,
                'amount' => $amount,
                'done' => in_array((int) $facility->id, $done, true),
            ];
        }

        return $rows;
    }

    /**
     * ⭐ মাসটা বসানো — আগের মাসগুলোর না-উল্টানো জমা আগে উল্টায়, তারপর এই মাসের প্রতিটা ঋণের জমা (যেটা এখনো বসেনি)।
     *
     * @return array{accrued: int, reversed: int, held: int}
     */
    public function run(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth()->startOfDay();

        if ($end->gte(Carbon::today())) {
            throw ValidationException::withMessages([
                'month' => __('finance::bank_loan_report.accrual_month_not_over'),
            ]);
        }

        $reversed = $this->reverseBefore($start);
        $accrued = 0;
        $held = 0;

        foreach ($this->preview($month) as $row) {
            if ($row['done']) {
                continue;
            }

            $wasHeld = DB::transaction(function () use ($row, $start, $end, &$accrued) {
                $facility = $row['facility'];
                $this->lockFresh($facility);

                // ⛔ তালার পরে আবার দেখা — একই সময়ে দুইজন একই মাস চালালেও একবারই
                if (InterestAccrual::query()->where('bank_facility_id', $facility->id)->where('for_month', $start->toDateString())->exists()) {
                    return false;
                }

                $voucher = $this->vouchers->create([
                    'type' => Voucher::JOURNAL,
                    'is_adjusting' => true, // ⭐ মাসশেষের সমন্বয় (ভাউচারের পরিকল্পনা ৩ঘ, ৭ অক্টোবর ২০২৬)
                    // ⛔ ঋণের শাখায় — জমার সারির একই শাখা; হেডারের শাখায় বসলে ২১৪৫ শাখা ধরে কখনো উল্টাত না (পুনঃঅডিট, ৯ অক্টোবর ২০২৬)
                    'branch_id' => $facility->branch_id,
                    'trx_date' => $end->toDateString(),
                    'narration' => __('finance::bank_loan_report.accrual_narration', [
                        'month' => $start->translatedFormat('F Y'), 'facility' => trim($facility->bank.' · '.$facility->document_no, ' ·'),
                        'days' => $row['days'],
                    ]),
                    'against_type' => BankFacility::drillSourceType(),
                    'against_id' => $facility->id,
                ], [
                    ['account_id' => $this->account(StandardChart::INTEREST_EXPENSE)->id, 'debit' => $row['amount'], 'credit' => '0'],
                    ['account_id' => $this->account(StandardChart::INTEREST_PAYABLE)->id, 'debit' => '0', 'credit' => $row['amount']],
                ]);

                InterestAccrual::query()->create([
                    'company_id' => CompanyContext::id(),
                    'branch_id' => $facility->branch_id,
                    'bank_facility_id' => $facility->id,
                    'for_month' => $start->toDateString(),
                    'days' => $row['days'],
                    'base' => $row['base'],
                    'rate' => $row['rate'],
                    'amount' => $row['amount'],
                    'voucher_id' => $voucher->id,
                    'created_by' => auth()->id(),
                ]);

                $accrued++;

                // ⛔ ছক থাকলে খসড়া, সই হলে খাতায় ([[finishSigned()]])
                return $this->signature->postOrHold($voucher, FinanceSignature::BANK_FACILITY, $row['amount']);
            });

            $held += $wasHeld ? 1 : 0;
        }

        return ['accrued' => $accrued, 'reversed' => $reversed, 'held' => $held];
    }

    /**
     * আগের মাসগুলোর খাতায় বসা অথচ না-উল্টানো জমা — পরের মাসের প্রথম দিনে উল্টো দাখিলা।
     */
    private function reverseBefore(Carbon $start): int
    {
        $count = 0;

        // ⛔ আগাম দেখার একই শাখাগুলো ([[preview()]]) — যা বসানো যায়, কেবল তা-ই উল্টায়
        $open = ActingBranches::narrow(InterestAccrual::query(), 'fin_interest_accruals.branch_id')
            ->whereNull('reversal_voucher_id')
            ->where('for_month', '<', $start->toDateString())
            // ⓘ ভাউচারের শাখার দেয়াল ছাড়া — হেডারে অন্য শাখা থাকলে জমার ভাউচার "নেই" হয়ে উল্টো দাখিলা চুপচাপ বাদ পড়ত
            ->with(['voucher' => fn ($q) => $q->withoutGlobalScope('user-branch'), 'facility'])
            ->orderBy('for_month')->orderBy('id')->get();

        foreach ($open as $accrual) {
            if ($accrual->voucher === null || $accrual->voucher->status === DocumentStatus::DRAFT || $accrual->voucher->status === DocumentStatus::CANCELLED) {
                continue;
            }

            DB::transaction(function () use ($accrual, &$count): void {
                $fresh = $accrual;
                $this->lockFresh($fresh);

                if ($fresh->reversal_voucher_id !== null) {
                    return;
                }

                $on = $fresh->for_month->copy()->endOfMonth()->addDay()->toDateString();

                $amount = bcadd((string) $fresh->amount, '0', 2);
                $facility = $accrual->facility;

                $reversal = $this->vouchers->create([
                    'type' => Voucher::JOURNAL,
                    'is_adjusting' => true, // ⭐ মাসশেষের সমন্বয় (ভাউচারের পরিকল্পনা ৩ঘ, ৭ অক্টোবর ২০২৬)
                    'branch_id' => $fresh->branch_id,
                    'trx_date' => $on,
                    'narration' => __('finance::bank_loan_report.accrual_reversal_narration', [
                        'month' => $fresh->for_month->translatedFormat('F Y'),
                        'facility' => trim(($facility?->bank ?? '').' · '.($facility?->document_no ?? ''), ' ·'),
                    ]),
                    'against_type' => BankFacility::drillSourceType(),
                    'against_id' => $fresh->bank_facility_id,
                ], [
                    ['account_id' => $this->account(StandardChart::INTEREST_PAYABLE)->id, 'debit' => $amount, 'credit' => '0'],
                    ['account_id' => $this->account(StandardChart::INTEREST_EXPENSE)->id, 'debit' => '0', 'credit' => $amount],
                ]);

                // ⓘ জমার নিজের জোড়া — জমাটা সই পেয়েছে, উল্টোটা আলাদা সই চায় না
                $this->vouchers->post($reversal);
                $fresh->forceFill(['reversal_voucher_id' => $reversal->id])->save();
                $count++;
            });
        }

        return $count;
    }

    /** ⭐ শেষ সই পড়ল — খসড়া জমা খাতায় ([[FinishTheFinancePaperOnTheLastSignature]]); দুইবার খবর এলেও একবার */
    public function finishSigned(Voucher $voucher): void
    {
        DB::transaction(function () use ($voucher): void {
            $this->lockFresh($voucher);

            if ($voucher->isDraft()) {
                $this->vouchers->post($voucher);
            }
        });
    }

    /** ⭐ সইকারী "না" বললেন — খসড়া বাতিল, আর মাসের সারি মোছা, যাতে মাসটা ঠিক করে আবার চালানো যায় */
    public function dropRefused(Voucher $voucher, string $reason): void
    {
        DB::transaction(function () use ($voucher, $reason): void {
            $this->lockFresh($voucher);

            if ($voucher->isDraft()) {
                $this->vouchers->cancel($voucher, $reason);
            }

            InterestAccrual::query()->where('voucher_id', $voucher->id)->whereNull('reversal_voucher_id')->delete();
        });
    }

    /** এই ভাউচারটা কি কোনো মাসের সুদ জমা — সইয়ের শ্রোতা ঋণের অন্য ভাউচার থেকে আলাদা করে চেনে */
    public static function isAccrual(Voucher $voucher): bool
    {
        return InterestAccrual::query()->where('voucher_id', $voucher->id)->exists();
    }

    /**
     * মেয়াদি ধরনের ঋণ — শেষ কিস্তির দিন (বা মঞ্জুরি) থেকে মাসের শেষ পর্যন্ত, খাতার বাকি আসলের উপর।
     *
     * @return array{0: int, 1: string, 2: string} দিন, জের, সুদ
     */
    private function sinceLastInstalment(BankFacility $facility, Carbon $end, string $rate): array
    {
        $owed = $this->facilities->owedOn($facility, $end->toDateString());

        if (bccomp($owed, '0', 4) <= 0) {
            return [0, '0', '0'];
        }

        $from = $facility->sanctioned_on === null ? null : Carbon::parse($facility->sanctioned_on)->startOfDay();

        foreach ($this->facilities->datedSchedule($facility, $end)['rows'] ?? [] as $row) {
            if ($row['due_on'] !== null && $row['due_on'] <= $end->toDateString()) {
                $due = Carbon::parse($row['due_on'])->startOfDay();
                $from = $from === null || $due->gt($from) ? $due : $from;
            }
        }

        if ($from === null) {
            return [0, '0', '0'];
        }

        $days = (int) $from->diffInDays($end);
        $amount = bcdiv(bcmul(bcmul($owed, $rate, 8), (string) $days, 8), '36500', 2);

        return [$days, bcadd($owed, '0', 4), $amount];
    }

    /**
     * CC — মাসের প্রতিটা দিনের তোলা জের (ঋণাত্মক নয়) × হার ÷ ৩৬৫, যোগ; জের = দিনগুলোর গড়।
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function daily(BankFacility $facility, Carbon $start, Carbon $end, string $rate): array
    {
        $sum = '0';
        $days = 0;

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $owed = $this->facilities->owedOn($facility, $day->toDateString());
            $sum = bcadd($sum, bccomp($owed, '0', 4) > 0 ? $owed : '0', 4);
            $days++;
        }

        if (bccomp($sum, '0', 4) <= 0) {
            return [0, '0', '0'];
        }

        return [$days, bcdiv($sum, (string) $days, 4), bcdiv(bcmul($sum, $rate, 8), '36500', 2)];
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
