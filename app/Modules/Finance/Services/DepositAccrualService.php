<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Concerns\ReadsTheRowUnderLock;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositAccrual;
use App\Modules\Finance\Reports\DepositReports;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ আমানতের মাসিক অর্জিত মুনাফা — অর্থ-মডিউলের পরিকল্পনা ৪.২, ৬ অক্টোবর ২০২৬ (সমন্বয়কের সিদ্ধান্ত প্র১: "মাস শেষে অর্জিত
 * মুনাফা খাতায় বসবে, বোতাম দিয়ে, ছকের সই মানবে")। ব্যাংক ঋণের সুদ জমার হুবহু নিয়ম ([[InterestAccrualService]]), যাতে দুই
 * দিক এক রকম চলে।
 *
 * ── ⭐ উল্টো দাখিলার নিয়ম (reversing entries) ─────────────────────────────────
 * মাস শেষে যে মুনাফা জমেছে কিন্তু ব্যাংক এখনো দেয়নি: Dr ১১৬৫ অর্জিত মুনাফা / Cr ৪৩১০ সুদ আয়, মাসের শেষ দিনে। পরের মাসের
 * প্রথম দিনে ঠিক উল্টো। ব্যাংক যেদিন মুনাফা দেয় বা জমা ভাঙানো হয়, আয় আগের মতোই বসে ([[DepositService::payout()]],
 * [[DepositService::close()]]) — সেই ভাউচার বদলাতে হয় না, আর আয় একবারই পড়ে, যে মাসে অর্জিত সেই মাসে।
 *
 * ── অঙ্ক ──────────────────────────────────────────────────────────────────────
 * মাসের শেষ দিনে "জমা সুদ" রিপোর্টের অর্জিত ঘর ([[DepositReports::ACCRUED]]) — শেষ তোলার পর থেকে মোট, আসলের প্রতিটা চলাচল
 * নিজের দিন থেকে, মেয়াদ পর্যন্ত। ⓘ তাই রিপোর্ট আর খাতা কখনো আলাদা কথা বলে না। উৎসে কর এখানে নয় — সেটা টাকা পাওয়ার দিনে।
 *
 * ── ⛔ পাহারা ───────────────────────────────────────────────────────────────────
 *   · কেবল ব্যবসার জমা — মালিকের নামের জমার মুনাফা ব্যবসার আয় নয় (খাতায় উত্তোলন)
 *   · এক জমায় এক মাস একবারই (অনন্য সূচক + সারিতে তালা); কেবল শেষ হওয়া মাস
 *   · ছকের সই মানে — জমার নিজের ছক ([[FinanceSignature::DEPOSIT]]); নতুন ধরন নয়, তাই ডিপ্লয়ে নতুন ছক বসে না। উল্টো দাখিলা জমার
 *     নিজের জোড়া, আলাদা সই চায় না।
 */
final class DepositAccrualService
{
    use ReadsTheRowUnderLock;

    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly FinanceSignature $signature,
    ) {}

    /**
     * মাসের শেষে কোন জমায় কত মুনাফা জমেছে — কিছু না লিখে।
     *
     * @return list<array{deposit: Deposit, base: string, rate: string, amount: string, done: bool}>
     */
    public function preview(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth()->toDateString();
        $done = DepositAccrual::query()->where('for_month', $start->toDateString())->pluck('deposit_id')
            ->map(fn ($id) => (int) $id)->all();

        $rows = app(ReportEngine::class)->run(DepositReports::ACCRUED, ['from' => $end, 'to' => $end], 1, 100000)->rows;
        $amounts = [];

        foreach ($rows as $row) {
            $row = (array) $row;

            if (bccomp((string) $row['accrued'], '0', 2) > 0) {
                $amounts[(int) $row['source_id']] = $row;
            }
        }

        $out = [];

        foreach (Deposit::query()->whereKey(array_keys($amounts))->where('held_by', Deposit::BUSINESS)->orderBy('id')->get() as $deposit) {
            $row = $amounts[(int) $deposit->id];
            $out[] = [
                'deposit' => $deposit,
                'base' => bcadd((string) $row['principal'], '0', 4),
                'rate' => bcadd((string) $row['profit_rate'], '0', 4),
                'amount' => bcadd((string) $row['accrued'], '0', 2),
                'done' => in_array((int) $deposit->id, $done, true),
            ];
        }

        return $out;
    }

    /**
     * ⭐ মাসটা বসানো — আগের মাসগুলোর না-উল্টানো জমা আগে উল্টায়, তারপর এই মাসের প্রতিটা জমার মুনাফা (যেটা এখনো বসেনি)।
     *
     * @return array{accrued: int, reversed: int, held: int}
     */
    public function run(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth()->startOfDay();

        if ($end->gte(Carbon::today())) {
            throw ValidationException::withMessages([
                'month' => __('finance::deposit_report.accrual_month_not_over'),
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
                $deposit = $row['deposit'];
                $this->lockFresh($deposit);

                // ⛔ তালার পরে আবার দেখা — একই সময়ে দুইজন একই মাস চালালেও একবারই
                if (DepositAccrual::query()->where('deposit_id', $deposit->id)->where('for_month', $start->toDateString())->exists()) {
                    return false;
                }

                $voucher = $this->vouchers->create([
                    'type' => Voucher::JOURNAL,
                    'is_adjusting' => true, // ⭐ মাসশেষের সমন্বয় (ভাউচারের পরিকল্পনা ৩ঘ, ৭ অক্টোবর ২০২৬)
                    'trx_date' => $end->toDateString(),
                    'narration' => __('finance::deposit_report.accrual_narration', [
                        'month' => $start->translatedFormat('F Y'), 'deposit' => $deposit->document_no, 'institution' => $deposit->institution,
                    ]),
                    'against_type' => Deposit::drillSourceType(),
                    'against_id' => $deposit->id,
                ], [
                    ['account_id' => $this->account(StandardChart::ACCRUED_INTEREST)->id, 'debit' => $row['amount'], 'credit' => '0'],
                    ['account_id' => $this->account(StandardChart::INTEREST_INCOME)->id, 'debit' => '0', 'credit' => $row['amount']],
                ]);

                DepositAccrual::query()->create([
                    'company_id' => CompanyContext::id(),
                    'branch_id' => $deposit->branch_id,
                    'deposit_id' => $deposit->id,
                    'for_month' => $start->toDateString(),
                    'base' => $row['base'],
                    'rate' => $row['rate'],
                    'amount' => $row['amount'],
                    'voucher_id' => $voucher->id,
                    'created_by' => auth()->id(),
                ]);

                $accrued++;

                // ⛔ ছক থাকলে খসড়া, সই হলে খাতায় ([[finishSigned()]])
                return $this->signature->postOrHold($voucher, FinanceSignature::DEPOSIT, $row['amount']);
            });

            $held += $wasHeld ? 1 : 0;
        }

        return ['accrued' => $accrued, 'reversed' => $reversed, 'held' => $held];
    }

    /** আগের মাসগুলোর খাতায় বসা অথচ না-উল্টানো জমা — পরের মাসের প্রথম দিনে উল্টো দাখিলা */
    private function reverseBefore(Carbon $start): int
    {
        $count = 0;

        $open = DepositAccrual::query()
            ->whereNull('reversal_voucher_id')
            ->where('for_month', '<', $start->toDateString())
            ->with(['voucher', 'deposit'])
            ->orderBy('for_month')->orderBy('id')->get();

        foreach ($open as $accrual) {
            if ($accrual->voucher === null || in_array($accrual->voucher->status, [DocumentStatus::DRAFT, DocumentStatus::CANCELLED], true)) {
                continue;
            }

            DB::transaction(function () use ($accrual, &$count): void {
                $fresh = $accrual;
                $this->lockFresh($fresh);

                if ($fresh->reversal_voucher_id !== null) {
                    return;
                }

                $amount = bcadd((string) $fresh->amount, '0', 2);

                $reversal = $this->vouchers->create([
                    'type' => Voucher::JOURNAL,
                    'is_adjusting' => true, // ⭐ মাসশেষের সমন্বয় (ভাউচারের পরিকল্পনা ৩ঘ, ৭ অক্টোবর ২০২৬)
                    'trx_date' => $fresh->for_month->copy()->endOfMonth()->addDay()->toDateString(),
                    'narration' => __('finance::deposit_report.accrual_reversal_narration', [
                        'month' => $fresh->for_month->translatedFormat('F Y'), 'deposit' => (string) $accrual->deposit?->document_no,
                    ]),
                    'against_type' => Deposit::drillSourceType(),
                    'against_id' => $fresh->deposit_id,
                ], [
                    ['account_id' => $this->account(StandardChart::INTEREST_INCOME)->id, 'debit' => $amount, 'credit' => '0'],
                    ['account_id' => $this->account(StandardChart::ACCRUED_INTEREST)->id, 'debit' => '0', 'credit' => $amount],
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

            DepositAccrual::query()->where('voucher_id', $voucher->id)->whereNull('reversal_voucher_id')->delete();
        });
    }

    /** এই ভাউচারটা কি কোনো মাসের অর্জিত মুনাফা — সইয়ের শ্রোতা জমার অন্য ভাউচার থেকে আলাদা করে চেনে */
    public static function isAccrual(Voucher $voucher): bool
    {
        return DepositAccrual::query()->where('voucher_id', $voucher->id)->exists();
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
