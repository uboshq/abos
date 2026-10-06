<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Concerns\ReadsTheRowUnderLock;
use App\Core\Services\OpenPeriod;
use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\RentalAccrual;
use App\Modules\Finance\Models\RentalContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ মাসের ভাড়া মাসের শুরুতেই খরচে — মালিকের সিদ্ধান্ত প্র২, ৬ অক্টোবর ২০২৬ (সমন্বয়কের মারফত): "প্রতি মাসের শুরুতে খরচে বসবে
 * (accrual): Dr ৫২০২ / Cr নতুন প্রদেয় ভাড়া; দেওয়ার দিন প্রদেয় শোধ হবে। a4-এর উল্টো-দাখিলার ধাঁচ নয়, এটা আসল প্রদেয় (দেওয়া পর্যন্ত
 * দায় থাকে)। ছকের সই, এক মাস একবার, বন্ধ মাসে নয়।"
 *
 * ── ⭐ দাখিলা ─────────────────────────────────────────────────────────────────
 * মাসের প্রথম দিনে (চুক্তি মাসের মাঝে শুরু হলে শুরুর দিনে): Dr চুক্তির খরচের খাত / Cr ২১৪১ প্রদেয় ভাড়া, ঐ মাসের দরে
 * ([[RentalContract::rentFor()]])। দেওয়ার দিন মাসের সারি ([[RentalContractService::adjustMonth()]]) Dr ২১৪১ দিয়ে শোধ করে, আর
 * দেওয়া ভাড়া বসানো অঙ্ক থেকে আলাদা হলে কেবল তফাতটা খরচে। ⓘ তাই খরচ একবারই পড়ে, যে মাসের ভাড়া সেই মাসে; না দেওয়া পর্যন্ত
 * স্থিতিপত্রে দায় দেখায়। ⛔ উল্টো দাখিলা নয় — পরের মাসে নিজে উল্টালে দায়টা মুছে যেত, অথচ টাকা তখনো দেওয়া হয়নি।
 *
 * ── ⛔ পাহারা ───────────────────────────────────────────────────────────────────
 *   · এক চুক্তিতে এক মাস একবারই (অনন্য চাবি + সারিতে তালা); মাসটা আগেই দেওয়া হয়ে থাকলে (অগ্রিম) বসে না — খরচ তখন দেওয়ার
 *     ভাউচারেই পড়েছে
 *   · চলতি মাস পর্যন্ত — সামনের মাসের ভাড়া এখনো খরচ নয়
 *   · বন্ধ মাসে নয় ([[OpenPeriod]]) — খসড়াও নয়, কারণ সই পড়ার দিন খাতা ওটা ঢোকাতে পারত না
 *   · ছকের সই ভাড়ার নিজের ছকে ([[FinanceSignature::RENTAL]]); নতুন ধরন নয়, তাই ডিপ্লয়ে নতুন ছক বসে না
 */
final class RentalAccrualService
{
    use ReadsTheRowUnderLock;

    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly FinanceSignature $signature,
        private readonly OpenPeriod $period,
    ) {}

    /**
     * ⭐ মাসটা বসানো — চালু প্রতিটা চুক্তি, যার মেয়াদে মাসটা পড়ে, আর যার মাসটা এখনো বসেনি বা দেওয়া হয়নি।
     *
     * @return array{accrued: int, held: int}
     */
    public function run(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth()->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();

        if ($start->gt(Carbon::today()->startOfMonth())) {
            throw ValidationException::withMessages([
                'month' => __('finance::message.rent_accrual_future'),
            ]);
        }

        if (($lock = $this->period->lockOn($start)) !== null) {
            throw ValidationException::withMessages([
                'month' => __('finance::message.rent_accrual_closed', ['month' => $lock->label()]),
            ]);
        }

        $accrued = 0;
        $held = 0;

        $contracts = RentalContract::query()->active()
            ->whereDate('starts_on', '<=', $end->toDateString())
            ->whereDate('ends_on', '>=', $start->toDateString())
            ->orderBy('id')->get();

        foreach ($contracts as $contract) {
            if ($this->monthTaken($contract, $start)) {
                continue;
            }

            $wasHeld = DB::transaction(function () use ($contract, $start, &$accrued): ?bool {
                $this->lockFresh($contract);

                // ⛔ তালার পরে আবার — দুইজন একসাথে একই মাস চালালেও একবারই
                if (! $contract->isActive() || $this->monthTaken($contract, $start)) {
                    return null;
                }

                $amount = bcadd($contract->rentFor($start), '0', 2);

                if (bccomp($amount, '0', 2) <= 0) {
                    return null;
                }

                $on = $contract->starts_on->gt($start) ? $contract->starts_on->copy() : $start->copy();

                $voucher = $this->vouchers->create([
                    'type' => Voucher::JOURNAL,
                    'branch_id' => $contract->branch_id,
                    'trx_date' => $on->toDateString(),
                    'narration' => __('finance::message.rent_accrual_narration', [
                        'who' => $contract->counterparty, 'month' => $start->translatedFormat('F Y'),
                    ]),
                    'against_type' => RentalContract::drillSourceType(),
                    'against_id' => $contract->id,
                ], [
                    ['account_id' => $contract->expense_account_id, 'debit' => $amount, 'credit' => '0'],
                    ['account_id' => self::payable()->id, 'debit' => '0', 'credit' => $amount],
                ]);

                RentalAccrual::query()->create([
                    'company_id' => CompanyContext::id(),
                    'branch_id' => $contract->branch_id,
                    'rental_contract_id' => $contract->id,
                    'for_month' => $start->toDateString(),
                    'amount' => $amount,
                    'voucher_id' => $voucher->id,
                    'created_by' => auth()->id(),
                ]);

                $accrued++;

                // ⛔ ছক থাকলে খসড়া, সই হলে খাতায় ([[finishSigned()]])
                return $this->signature->postOrHold($voucher, FinanceSignature::RENTAL, $amount);
            });

            $held += $wasHeld === true ? 1 : 0;
        }

        return ['accrued' => $accrued, 'held' => $held];
    }

    /** মাসটা কি আগেই বসেছে, বা দেওয়া হয়ে গেছে (অগ্রিম দেওয়া মাসের খরচ দেওয়ার ভাউচারেই পড়েছে) */
    private function monthTaken(RentalContract $contract, Carbon $start): bool
    {
        return RentalAccrual::query()->where('rental_contract_id', $contract->id)->whereDate('for_month', $start->toDateString())->exists()
            || $contract->adjustments()->whereDate('for_month', $start->toDateString())->exists();
    }

    /**
     * ⭐ এই মাসের বসানো প্রদেয় — দেওয়ার ভাউচার এটুকু ২১৪১ থেকে শোধ করে ([[RentalContractService::adjustMonth()]])।
     *
     * ⓘ সারি না থাকলে null (মাসটা বসেনি — দেওয়ার ভাউচারেই খরচ)। ⛔ সারি আছে অথচ সই বাকি থাকলে দেওয়া যায় না: প্রদেয়টা তখনো
     * খাতায় নেই, শোধ করলে ২১৪১ উল্টো দিকে যেত।
     */
    public static function settles(RentalContract $contract, Carbon $month): ?string
    {
        $accrual = RentalAccrual::query()->with('voucher')
            ->where('rental_contract_id', $contract->id)->whereDate('for_month', $month->copy()->startOfMonth()->toDateString())
            ->first();

        if ($accrual === null) {
            return null;
        }

        if ($accrual->voucher === null || $accrual->voucher->isDraft()) {
            throw ValidationException::withMessages([
                'for_month' => __('finance::message.rent_accrual_unsigned', ['month' => $accrual->for_month->translatedFormat('F Y')]),
            ]);
        }

        return bcadd((string) $accrual->amount, '0', 4);
    }

    /** ⭐ শেষ সই পড়ল — খসড়া প্রদেয় খাতায় ([[FinishTheFinancePaperOnTheLastSignature]]); দুইবার খবর এলেও একবার */
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

            if (! $voucher->isDraft()) {
                return;
            }

            $this->vouchers->cancel($voucher, $reason);
            RentalAccrual::query()->where('voucher_id', $voucher->id)->delete();
        });
    }

    /** এই ভাউচারটা কি কোনো মাসের প্রদেয় ভাড়া — সইয়ের শ্রোতা চুক্তির অন্য ভাউচার থেকে আলাদা করে চেনে */
    public static function isAccrual(Voucher $voucher): bool
    {
        return RentalAccrual::query()->where('voucher_id', $voucher->id)->exists();
    }

    /** ২১৪১ — না থাকলে (পুরনো কোম্পানি) ছক একবার বসিয়ে নেয়; [[StandardChart::install()]] কেবল যা নেই তা-ই বসায় */
    public static function payable(): Account
    {
        $account = StandardChart::find(StandardChart::RENT_PAYABLE);

        if ($account === null) {
            app(StandardChart::class)->install();
            $account = StandardChart::find(StandardChart::RENT_PAYABLE);
        }

        if ($account === null) {
            throw ValidationException::withMessages([
                'month' => __('finance::validation.chart_head_missing', ['code' => StandardChart::RENT_PAYABLE]),
            ]);
        }

        return $account;
    }
}
