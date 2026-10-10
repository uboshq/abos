<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Concerns\ReadsTheRowUnderLock;
use App\Core\Services\OpenPeriod;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
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
     * @return array{accrued: int, held: int, failed: list<string>} `failed` — যে চুক্তিগুলো বসেনি, নম্বর আর কারণসহ
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
        $failed = [];

        $contracts = RentalContract::query()->active()
            ->whereDate('starts_on', '<=', $end->toDateString())
            ->whereDate('ends_on', '>=', $start->toDateString())
            ->orderBy('id')->get();

        foreach ($contracts as $contract) {
            /*
             * ⛔ এক চুক্তির ভুল বাকি কোম্পানির মাস থামায় না — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬। ⓘ আগে একটা চুক্তির খাত মুছে
             * যাওয়া বা ভুল শর্তে ব্যতিক্রম উঠলে তার পরের সব চুক্তির মাস বসত না, আর বার্তা বলত কেবল ঐ একটার কথা। প্রতিটা
             * চুক্তি নিজের লেনদেনে, তাই ভুলটা কেবল সেই চুক্তির — নাম ধরে জানানো হয়, বাকিরা চলে।
             */
            try {
                // ⛔ চুক্তির কোনো টাকা সইয়ের অপেক্ষায় — এবার নয়, পরের চালে (cloud/finance-fixes রিভিউ ⛔২; [[assertNothingWaiting()]])
                $this->assertNothingWaiting($contract);

                // ⭐ মাসটা আগাম দেওয়া — অগ্রিম থেকে খরচে সরানো (অডিট ⛔৬); অন্যভাবে দেওয়া বা বসানো মাস আগের মতোই বাদ
                if (! $this->accrued($contract, $start) && $contract->adjustments()->whereDate('for_month', $start->toDateString())->exists()) {
                    $accrued += $this->release($contract, $start);

                    continue;
                }

                if ($this->monthTaken($contract, $start)) {
                    continue;
                }

                $wasHeld = DB::transaction(function () use ($contract, $start): ?bool {
                    $this->lockFresh($contract);

                    // ⛔ তালার পরে আবার — দুইজন একসাথে একই মাস চালালেও একবারই
                    if (! $contract->isActive() || $this->monthTaken($contract, $start)) {
                        return null;
                    }

                    $this->assertNothingWaiting($contract);

                    $amount = bcadd($contract->rentFor($start), '0', 2);

                    if (bccomp($amount, '0', 2) <= 0) {
                        return null;
                    }

                    $on = $contract->starts_on->gt($start) ? $contract->starts_on->copy() : $start->copy();

                    $voucher = $this->vouchers->create([
                        'type' => Voucher::JOURNAL,
                        'is_adjusting' => true, // ⭐ মাসশেষের সমন্বয় (ভাউচারের পরিকল্পনা ৩ঘ, ৭ অক্টোবর ২০২৬)
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

                    // ⛔ ছক থাকলে খসড়া, সই হলে খাতায় ([[finishSigned()]])
                    return $this->signature->postOrHold($voucher, FinanceSignature::RENTAL, $amount);
                });

                // ⓘ গোনা লেনদেনের পরে — ভেতরে ভুল উঠে সব ফিরে গেলে "বসল" গোনা হত না
                $accrued += $wasHeld === null ? 0 : 1;
                $held += $wasHeld === true ? 1 : 0;
            } catch (\Throwable $e) {
                $failed[] = $this->failure($contract, $e);
            }
        }

        return ['accrued' => $accrued, 'held' => $held, 'failed' => $failed];
    }

    /** একটা চুক্তি বসল না — নম্বর আর কারণ; যাচাইয়ের বার্তা যেমন আছে, বাকি ভুল লগে যায় আর পর্দায় সাধারণ কথা */
    private function failure(RentalContract $contract, \Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            $why = (string) collect($e->errors())->flatten()->first();
        } else {
            report($e);
            $why = __('finance::message.rent_accrual_contract_broke');
        }

        return __('finance::message.rent_accrual_contract_failed', [
            'no' => $contract->document_no ?? '#'.$contract->id, 'who' => $contract->counterparty, 'why' => $why,
        ]);
    }

    /** মাসটা কি আগেই বসেছে, বা দেওয়া হয়ে গেছে */
    private function monthTaken(RentalContract $contract, Carbon $start): bool
    {
        return $this->accrued($contract, $start)
            || $contract->adjustments()->whereDate('for_month', $start->toDateString())->exists();
    }

    private function accrued(RentalContract $contract, Carbon $start): bool
    {
        return RentalAccrual::query()->where('rental_contract_id', $contract->id)->whereDate('for_month', $start->toDateString())->exists();
    }

    /**
     * ⭐ আগাম দেওয়া মাস এলো — অগ্রিম ভাড়া (১১৩৭) থেকে খরচে, মাসের প্রথম দিনে (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ ⛔৬)।
     *
     * ⓘ কেবল যখন মাসের পরিশোধ খাতায় বসেছে আর তাতে ১১৩৭-এ ডেবিট আছে — অঙ্ক সেই ডেবিটই। সই বাকি থাকলে এখনো নয় (পরের চালে)।
     * সই চায় না: টাকা আর নড়ে না, সই পরিশোধেই পড়েছে (বীমার অগ্রিমের একই নিয়ম, সমন্বয়কের সিদ্ধান্ত ক)। মাসের সারি বসে, তাই
     * একবারই।
     *
     * @return int ১ সরানো হলে, নাহলে ০
     */
    private function release(RentalContract $contract, Carbon $start): int
    {
        return DB::transaction(function () use ($contract, $start): int {
            $this->lockFresh($contract);

            if ($this->accrued($contract, $start)) {
                return 0;
            }

            $this->assertNothingWaiting($contract);

            $paid = $contract->adjustments()->whereDate('for_month', $start->toDateString())->with('voucher.lines')->first()?->voucher;

            if ($paid === null || $paid->status !== DocumentStatus::CONFIRMED) {
                return 0;
            }

            $amount = bcadd((string) $paid->lines->where('account_id', self::prepaid()->id)->sum('debit'), '0', 2);

            if (bccomp($amount, '0', 2) <= 0) {
                return 0;
            }

            $voucher = $this->vouchers->create([
                'type' => Voucher::JOURNAL,
                'is_adjusting' => true, // ⭐ মাসশেষের সমন্বয় (ভাউচারের পরিকল্পনা ৩ঘ, ৭ অক্টোবর ২০২৬)
                'branch_id' => $contract->branch_id,
                'trx_date' => $start->toDateString(),
                'narration' => __('finance::message.rent_prepaid_release_narration', [
                    'who' => $contract->counterparty, 'month' => $start->translatedFormat('F Y'),
                ]),
                'against_type' => RentalContract::drillSourceType(),
                'against_id' => $contract->id,
            ], [
                ['account_id' => $contract->expense_account_id, 'debit' => $amount, 'credit' => '0'],
                ['account_id' => self::prepaid()->id, 'debit' => '0', 'credit' => $amount],
            ]);

            $this->vouchers->post($voucher);

            RentalAccrual::query()->create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $contract->branch_id,
                'rental_contract_id' => $contract->id,
                'for_month' => $start->toDateString(),
                'amount' => $amount,
                'voucher_id' => $voucher->id,
                'created_by' => auth()->id(),
            ]);

            return 1;
        });
    }

    /**
     * ⛔ চুক্তির কোনো টাকা সইয়ের অপেক্ষায় থাকলে মাস বসে না — cloud/finance-fixes-এর রিভিউ ⛔২, ১০ অক্টোবর ২০২৬।
     *
     * ⓘ বন্ধের ফেরত সইয়ের অপেক্ষায় থাকলে সেটা অগ্রিম ভাড়ার (১১৩৭) বাকিটা ফেরতে গুনে রেখেছে; এর মাঝে এখানে আগাম মাস ১১৩৭ থেকে
     * খরচে সরালে সই পড়ার দিন ফেরতও ১১৩৭-এ জমা হত — একই টাকা দুবার। ⓘ চুক্তির নিজের নিয়মটাই
     * ([[RentalContractService::isWaiting()]], `awaits_signature_first`); থামা চুক্তি "বসেনি"-র তালিকায় নাম আর কারণসহ ওঠে,
     * বাকিরা চলে, আর সই পড়ার পরের চালে মাসটা বসে।
     */
    private function assertNothingWaiting(RentalContract $contract): void
    {
        if (app(RentalContractService::class)->isWaiting($contract)) {
            throw ValidationException::withMessages([
                'status' => __('finance::validation.awaits_signature_first'),
            ]);
        }
    }

    /** ১১৩৭ অগ্রিম ভাড়া — না থাকলে (পুরনো কোম্পানি) ছক একবার বসিয়ে নেয় */
    public static function prepaid(): Account
    {
        $account = StandardChart::find(StandardChart::PREPAID_RENT);

        if ($account === null) {
            app(StandardChart::class)->install();
            $account = StandardChart::find(StandardChart::PREPAID_RENT);
        }

        if ($account === null) {
            throw ValidationException::withMessages([
                'for_month' => __('finance::validation.chart_head_missing', ['code' => StandardChart::PREPAID_RENT]),
            ]);
        }

        return $account;
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
