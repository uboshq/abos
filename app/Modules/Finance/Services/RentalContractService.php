<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Concerns\ReadsTheRowUnderLock;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\RentalAccrual;
use App\Modules\Finance\Models\RentalAdjustment;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Models\RentalTerm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ভাড়ার চুক্তি — জামানত দেওয়া, মাসের সমন্বয়, আর শেষে ফেরত।
 *
 * ── এই সার্ভিসটা টাকা রাখে না, টাকা **পোস্ট করে** ───────────────────
 * প্রতিটা ঘটনা একটা ভাউচার হয়ে খতিয়ানে যায়, আর জামানতের ব্যালেন্স
 * খতিয়ান থেকেই পড়া হয়। ⓘ মালিকের স্থায়ী নিয়ম: প্রতিটা সংখ্যা তার
 * উৎসে ক্লিক করে পৌঁছানো যাবে।
 */
class RentalContractService
{
    use ReadsTheRowUnderLock;

    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly NumberSeriesEngine $numbers,

        // ⛔ সই ছাড়া ভাড়ার টাকা নড়ে না — অডিট গ১, ৪ অক্টোবর ২০২৬ ([[FinanceSignature]])
        private readonly FinanceSignature $signature,
    ) {}

    /**
     * চুক্তি খোলা, আর জামানতের টাকাটা পোস্ট করা।
     *
     *     জামানত/অগ্রিম (১১৪০ বা ১১৩০)   ডেবিট  ১২,০০,০০০
     *     নগদ/ব্যাংক                              ক্রেডিট ১২,০০,০০০
     *
     * @param  array<string, mixed>  $data
     */
    public function open(array $data): RentalContract
    {
        $deposit = (string) ($data['deposit_amount'] ?? '0');
        $rent = (string) ($data['monthly_rent'] ?? '0');
        $adjustment = (string) ($data['monthly_adjustment'] ?? '0');
        $term = (int) ($data['term_months'] ?? 0);

        $this->assertTermsMakeSense($deposit, $rent, $adjustment, $term);

        $starts = Carbon::parse((string) ($data['starts_on'] ?? now()->toDateString()))->startOfDay();

        return DB::transaction(function () use ($data, $deposit, $rent, $adjustment, $term, $starts) {
            $contract = RentalContract::create([
                // ⭐ কোন শাখার চুক্তি — না বসালে তালিকার শাখার দেয়াল ওটাকে কোনো শাখায় দেখাত না (১ অক্টোবর ২০২৬)
                'branch_id' => CompanyContext::branchId(),
                /*
                 * ⭐ নথি নম্বর — ১৫ সেপ্টেম্বর ২০২৬-এ যোগ হলো।
                 *
                 * ⛔ কলামটা ছিল, কেউ ভরত না, তাই প্রতিটা চুক্তি `NULL`
                 * নিয়ে বসে থাকত। ⚠️ বাড়িওয়ালা ফোন করলে "কোন চুক্তি"
                 * প্রশ্নের উত্তর দেওয়ার মতো কিছুই ছিল না — আইডি কাগজে
                 * লেখা থাকে না।
                 */
                'document_no' => $this->numbers->next('RNT'),
                'counterparty' => (string) $data['counterparty'],
                'counterparty_phone' => $data['counterparty_phone'] ?? null,
                'subject' => $data['subject'] ?? null,

                /*
                 * ⭐ কার সাথে, আর কী ভাড়া নেওয়া — জোড়া দুইটা,
                 * ২০ সেপ্টেম্বর ২০২৬ (মালিকের নির্দেশ)।
                 *
                 * ⓘ উপরের দুইটা লেখার ঘর তবু ভরা থাকে: পুরনো চুক্তির
                 * নাম ওখানেই, আর তালিকায় নেই এমন জিনিসের জন্য ঘরটা
                 * এখনো একমাত্র পথ। ⚠️ জোড়া না থাকাটাও একটা উত্তর, তাই
                 * ঘর দুইটা ঐচ্ছিক — `null` বসে, আর কিছু ভাঙে না।
                 */
                'party_type' => $data['party_type'] ?? null,
                'party_id' => $data['party_id'] ?? null,
                'subject_type' => $data['subject_type'] ?? null,
                'subject_id' => $data['subject_id'] ?? null,
                'account_id' => $this->depositHead($data)->id,
                'expense_account_id' => $this->expenseHead($data)->id,
                'deposit_amount' => $deposit,
                'monthly_rent' => $rent,
                'monthly_adjustment' => $adjustment,
                'starts_on' => $starts->toDateString(),
                'term_months' => $term,

                /*
                 * ⓘ খালি এলে কলামের ডিফল্টই থাকে (দিন ৫, অগ্রিম ০,
                 * কর ৫%) — পুরনো চুক্তির আচরণ বদলায় না।
                 */
                'rent_day' => (int) ($data['rent_day'] ?? 5),
                'advance_months' => (int) ($data['advance_months'] ?? 0),
                'tax_rate' => ($data['tax_rate'] ?? '') !== '' ? $data['tax_rate'] : 5,

                /*
                 * ⚠️ শেষ দিনটা "শুরু + মেয়াদ" নয়, তার **এক দিন আগে**।
                 *
                 * ১ জানুয়ারি শুরু হওয়া ২৪ মাসের চুক্তি শেষ হয় ৩১
                 * ডিসেম্বরে, ১ জানুয়ারিতে নয়। ⓘ একদিনের ভুল মনে হয়,
                 * কিন্তু ঐ একদিনেই নবায়নের নোটিশের তারিখ ঠিক হয়।
                 */
                'ends_on' => $starts->copy()->addMonths($term)->subDay()->toDateString(),
                'status' => RentalContract::ACTIVE,
                'note' => $data['note'] ?? null,
                // ⭐ বৃদ্ধির কথা থাকলে বছরে কত % — ঐচ্ছিক, কেবল মনে করায় (মালিক, প্র১, ৬ অক্টোবর ২০২৬)
                'increase_percent' => ($data['increase_percent'] ?? '') !== '' ? $data['increase_percent'] : null,
                'created_by' => auth()->id(),
            ]);

            // ⭐ শর্তের প্রথম দফা — শুরুর মাস থেকে ([[RentalTerm]])
            $this->writeTerm($contract, $starts->copy()->startOfMonth(), $rent, $adjustment, null);

            /*
             * জামানতের টাকাটা তখনই পোস্ট হয়, যখন সত্যিই দেওয়া হয়েছে।
             *
             * ⓘ পুরনো চুক্তি বসানোর সময় (বই শুরুর দিন) টাকাটা আগেই
             * দেওয়া হয়ে গেছে আর খোলার ব্যালেন্সে বসেছে — তখন আবার
             * পোস্ট করলে দুইবার হত। তাই ঘরটা ঐচ্ছিক।
             */
            if (filled($data['money_account_id'] ?? null) && bccomp($deposit, '0', 4) > 0) {
                $voucher = $this->vouchers->create(
                    [
                        'type' => Voucher::PAYMENT,
                        // ⛔ চুক্তির শাখায় — চলতি শাখায় নয় (অডিট ৬ অক্টোবর ⛔৫): অন্য শাখা থেকে দিলে ২১৪১ বা জামানত শাখা ধরে কখনো মিলত না
                        'branch_id' => $contract->branch_id,
                        'trx_date' => $starts->toDateString(),
                        'narration' => __('finance::message.rental_deposit_narration', [
                            'who' => $contract->counterparty,
                        ]),

                        // ⓘ কোন চুক্তির টাকা — শেষ সই পড়লে [[finishSigned()]] এই জোড়া ধরে চুক্তিটা খোঁজে
                        'against_type' => RentalContract::drillSourceType(),
                        'against_id' => $contract->id,

                        /*
                         * ⓘ ভাড়ার জামানত প্রায়ই চেকে যায়, তাই এই পথে
                         * নম্বরটা সবচেয়ে বেশি লাগে। ⛔ তবু ঐচ্ছিক —
                         * নগদে হাতে দিলে নম্বর হয় না, আর কখন লাগবে সেটা
                         * [[VoucherService::assertBankReferenceIsFree]] জানে।
                         */
                        'instrument_no' => ($data['instrument_no'] ?? '') ?: null,
                    ],
                    [
                        [
                            'account_id' => $contract->account_id,
                            'debit' => $deposit, 'credit' => '0',
                        ],
                        [
                            'account_id' => $this->moneyAccountId($data),
                            'debit' => '0', 'credit' => $deposit,
                        ],
                    ],
                );

                /*
                 * ⛔ সই-এর আগে চুক্তি চলে না — অডিট গ১, ৪ অক্টোবর ২০২৬। ⓘ জামানত না গেলে মাস কাটা বা
                 * ফেরত পাওয়ার কিছুই নেই; শেষ সই চুক্তিটা চালায় ([[finishSigned()]])।
                 */
                if ($this->signature->postOrHold($voucher, FinanceSignature::RENTAL, $deposit)) {
                    $contract->update(['status' => RentalContract::AWAITING]);
                }
            }

            return $contract->fresh();
        });
    }

    /**
     * এক মাসের ভাড়া — নগদের অংশ আর জামানতের অংশ, এক ভাউচারে।
     *
     *     ভাড়া (৫২০২)      ডেবিট  ৩০,০০০
     *     নগদ (১১০১)              ক্রেডিট ২০,০০০
     *     জামানত (১১৪০)           ক্রেডিট ১০,০০০
     *
     * ⭐ "খ" ধরনে (পুরো মেয়াদের ভাড়া অগ্রিম) নগদের সারিটা শূন্য, তাই
     * বসেই না — দুইটা রূপের জন্য দুইটা কোড লাগেনি।
     *
     * @param  array<string, mixed>  $data
     */
    public function adjustMonth(RentalContract $contract, array $data): RentalAdjustment
    {
        $month = Carbon::parse((string) ($data['for_month'] ?? now()->toDateString()))->startOfMonth();

        $this->assertCanPay($contract, $month);

        $rent = (string) ($data['rent'] ?? $contract->monthly_rent);
        $fromDeposit = (string) ($data['from_deposit'] ?? $contract->monthly_adjustment);
        $cash = bcsub($rent, $fromDeposit, 4);

        if (bccomp($cash, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'from_deposit' => __('finance::validation.rental_adjustment_over_rent'),
            ]);
        }

        /*
         * ⛔ জামানতে যা নেই তা কাটা যায় না।
         *
         * ⚠️ এটাই সেই নীরব ভুলটা যেটা আজ পর্যন্ত কেউ ধরত না: জামানত
         * ফুরিয়ে গেলেও প্রতি মাসে কাটতেই থাকত, আর `১১৪০` ধীরে ধীরে
         * **ঋণাত্মক** হয়ে যেত — একটা সম্পদ খাত, যেটা ঋণাত্মক হতেই
         * পারে না। স্থিতিপত্র তখন চুপচাপ ভুল বলত।
         */
        $this->assertDepositCovers($contract, $fromDeposit);

        /*
         * ⛔ একই মাস দুইবার নয় — আর এই যাচাইটা **কোডেই থাকতে হবে**।
         *
         * ── কী ভাঙা ছিল, ৫ সেপ্টেম্বর ২০২৬ ─────────────────────────
         * টেবিলে `unique(rental_contract_id, for_month, deleted_at)`
         * বসানো ছিল, আর সেটাই যথেষ্ট মনে হয়েছিল। ⛔ নয়: MySQL-এ
         * **NULL কখনো NULL-এর সমান নয়**, তাই `deleted_at` খালি থাকলে
         * unique চাবিটা প্রতিটা সারিকে আলাদা গণ্য করে — একই মাস দশবার
         * বসানো যেত।
         *
         * ⚠️ ধরা পড়েছে টেস্টে: একই মাস দুইবার বসিয়ে ব্যতিক্রম আশা করা
         * হয়েছিল, কিছুই ঘটেনি। ⓘ চাবিটা রাখা হয়েছে (soft delete করা
         * সারির ক্ষেত্রে সে এখনো কাজে লাগে), কিন্তু **আসল পাহারা এটা**।
         *
         * ⭐ দামটা ছোট নয়: একই মাস দুইবার মানে ঐ মাসের ভাড়া দুইবার
         * খরচে বসা, জামানত দুইবার কাটা, আর মুনাফা কম দেখানো।
         */
        $this->assertMonthNotDone($contract, $month);

        // ⛔ মাসের প্রদেয় বসেছে অথচ সই বাকি — আগে সেটা ([[RentalAccrualService::settles()]])
        RentalAccrualService::settles($contract, $month);

        if (bccomp($cash, '0', 4) > 0 && blank($data['money_account_id'] ?? null)) {
            throw ValidationException::withMessages([
                'money_account_id' => __('finance::validation.rental_needs_money_account'),
            ]);
        }

        return DB::transaction(function () use ($contract, $data, $month, $rent, $cash, $fromDeposit) {
            // ⛔ সারিতে তালা দিয়ে তাজা অবস্থা আবার — দ্বিতীয় ক্লিক টাকা আবার বসাত (চূড়ান্ত অডিট ⛔১১)
            $this->lockFresh($contract);
            $this->assertCanPay($contract, $month);
            $this->assertNothingWaiting($contract);
            // ⛔ তালার পরে আবার — দুই ক্লিক একসাথে একই মাস দুইবার বসাত, জামানত দুইবার কাটত
            $this->assertMonthNotDone($contract, $month);
            $this->assertDepositCovers($contract, $fromDeposit);

            /*
             * ⭐ মাসের শুরুতে ভাড়া প্রদেয় হিসেবে বসে থাকলে (মালিকের সিদ্ধান্ত প্র২, ৬ অক্টোবর ২০২৬; [[RentalAccrualService]])
             * দেওয়ার দিন খরচ নয়, ২১৪১ শোধ — বসানো অঙ্কটুকু। দেওয়া ভাড়া আলাদা হলে কেবল তফাতটা খরচে (বেশি হলে Dr, কম হলে Cr)।
             * ⛔ পুরোটা আবার খরচে বসালে মাসের ভাড়া দুইবার খরচ হত, আর ২১৪১ কোনোদিন শূন্যে নামত না।
             */
            $accrued = RentalAccrualService::settles($contract, $month);

            /*
             * ⛔ সামনের মাসের ভাড়া — অগ্রিম, খরচ নয় (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ ⛔৬)। ⓘ আগে মার্চের ভাড়া জানুয়ারিতে দিলে মার্চের
             * খরচ জানুয়ারিতে পড়ত (নগদ ভিত্তি)। এখন ১১৩৭-এ বসে, আর মাসটা এলে মাসের জমা সেটা খরচে সরায় ([[RentalAccrualService::run()]])।
             */
            $ahead = $accrued === null && $month->gt(Carbon::today()->startOfMonth());
            $toExpense = match (true) {
                $ahead => '0',
                $accrued === null => $rent,
                default => bcsub($rent, $accrued, 4),
            };
            $lines = [];

            if ($ahead) {
                $lines[] = [
                    'account_id' => RentalAccrualService::prepaid()->id,
                    'debit' => $rent, 'credit' => '0',
                ];
            }

            if ($accrued !== null) {
                $lines[] = [
                    'account_id' => RentalAccrualService::payable()->id,
                    'debit' => $accrued, 'credit' => '0',
                ];
            }

            if (bccomp($toExpense, '0', 4) !== 0) {
                $lines[] = [
                    'account_id' => $contract->expense_account_id,
                    'debit' => bccomp($toExpense, '0', 4) > 0 ? $toExpense : '0',
                    'credit' => bccomp($toExpense, '0', 4) < 0 ? bcmul($toExpense, '-1', 4) : '0',
                ];
            }

            if (bccomp($cash, '0', 4) > 0) {
                $lines[] = [
                    'account_id' => $this->moneyAccountId($data),
                    'debit' => '0', 'credit' => $cash,
                ];
            }

            if (bccomp($fromDeposit, '0', 4) > 0) {
                $lines[] = [
                    'account_id' => $contract->account_id,
                    'debit' => '0', 'credit' => $fromDeposit,
                ];
            }

            $voucher = $this->vouchers->create(
                [
                    'type' => Voucher::PAYMENT,
                    // ⛔ চুক্তির শাখায় — চলতি শাখায় নয় (অডিট ৬ অক্টোবর ⛔৫): অন্য শাখা থেকে দিলে ২১৪১ বা জামানত শাখা ধরে কখনো মিলত না
                    'branch_id' => $contract->branch_id,
                    'trx_date' => (string) ($data['paid_on'] ?? $month->toDateString()),
                    'narration' => __('finance::message.rental_month_narration', [
                        'who' => $contract->counterparty,
                        'month' => $month->translatedFormat('F Y'),
                    ]),
                    'instrument_no' => ($data['instrument_no'] ?? '') ?: null,
                ],
                $lines,
            );

            // ⓘ মাসের সারি থাকে (মাস আর জামানত আটকে রাখে); "না" হলে [[dropRefused()]] সারিটা সরায়
            $this->signature->postOrHold($voucher, FinanceSignature::RENTAL, $rent);

            return RentalAdjustment::create([
                'branch_id' => $contract->branch_id,
                'rental_contract_id' => $contract->id,
                'for_month' => $month->toDateString(),
                'rent' => $rent,
                'paid_cash' => $cash,
                'from_deposit' => $fromDeposit,
                'voucher_id' => $voucher->id,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * শর্ত বদলানো — ভাড়া বাড়ল, কমল, বা সমন্বয়ের অঙ্ক বদলাল।
     *
     * ── মালিকের কথা, ৫ সেপ্টেম্বর ২০২৬ ──────────────────────────────
     * *"জামানত বাড়ালে ভাড়া কমবে, কোনো সমস্যার কারণে ভাড়া কমলো বা
     * বাড়লো — মানে সব এডিটের ব্যবস্থা রাখতে হবে।"*
     *
     * ── ⭐ কেন গত মাসগুলো নড়ে না ────────────────────────────────────
     * প্রতিটা মাসের সারি **নিজের** ভাড়া-নগদ-সমন্বয় নিজে ধরে রাখে, আর
     * তার ভাউচার খতিয়ানে বসা। তাই শর্ত বদলালে কেবল **সামনের মাসগুলো**
     * বদলায়। ⛔ চুক্তির উপর একটা "চলতি ভাড়া" রেখে সবাই সেটা পড়লে
     * আজকের বদল গত বছরের হিসাবও বদলে দিত, আর বন্ধ মাস নড়ত।
     *
     * ⚠️ "কে কবে কী বদলাল" আলাদা করে রাখা হয় না — [[IsAudited]] প্রতিটা
     * ঘরের আগের ও পরের মান এমনিতেই রাখে (Global Feature ১)। দ্বিতীয়
     * একটা ইতিহাস রাখলে দুইটা একদিন আলাদা কথা বলত।
     *
     * @param  array<string, mixed>  $data
     */
    public function reviseTerms(RentalContract $contract, array $data): RentalContract
    {
        $this->assertActive($contract);

        $rent = (string) ($data['monthly_rent'] ?? $contract->monthly_rent);
        $adjustment = (string) ($data['monthly_adjustment'] ?? $contract->monthly_adjustment);

        if (bccomp($adjustment, $rent, 4) > 0) {
            throw ValidationException::withMessages([
                'monthly_adjustment' => __('finance::validation.rental_adjustment_over_rent'),
            ]);
        }

        /*
         * ⚠️ সীমাটা **এখন যা পড়ে আছে** তার উপর, মূল জামানতের উপর নয়।
         *
         * ছয় মাস কাটার পর জামানত কমে গেছে; মূল অঙ্ক ধরে মাপলে সফটওয়্যার
         * এমন একটা নতুন শর্ত মেনে নিত যেটা মেয়াদের মাঝপথে ফুরিয়ে যেত,
         * আর ভুলটা ধরা পড়ত ঠিক সেই মাসে — যখন আর কিছু করার নেই।
         *
         * ⓘ বাকি মাস গোনা হয় আজ থেকে, কারণ বদলটা আজ থেকেই খাটে।
         */
        $monthsLeft = max(0, (int) now()->startOfMonth()->diffInMonths($contract->ends_on, false) + 1);
        $needed = bcmul($adjustment, (string) $monthsLeft, 4);

        // ⛔ সইয়ের অপেক্ষার মাসের টাকা বাদ দিয়ে — সে টাকা আগেই আটকানো ([[RentalContract::depositFree()]])
        if (bccomp($needed, $contract->depositFree(), 4) > 0) {
            throw ValidationException::withMessages([
                'monthly_adjustment' => __('finance::validation.rental_adjustment_exceeds_deposit', [
                    'whole' => $needed,
                    'deposit' => $contract->depositFree(),
                ]),
            ]);
        }

        /*
         * ⭐ শর্তের নতুন দফা — কোন মাস থেকে (না দিলে চলতি মাস); সেই মাসে আগে থেকে দফা থাকলে সেটাই হালনাগাদ। পুরনো মাস নিজের
         * দরে থাকে (মালিক, প্র১, ৬ অক্টোবর ২০২৬)। ⛔ চুক্তির শুরুর আগের মাসে নয়, মেয়াদের পরের মাসেও নয়।
         */
        $from = Carbon::parse((string) (($data['effective_from'] ?? '') ?: now()->toDateString()))->startOfMonth();

        if ($from->lt($contract->starts_on->copy()->startOfMonth()) || $from->gt($contract->ends_on)) {
            throw ValidationException::withMessages([
                'effective_from' => __('finance::rental_report.term_outside_contract'),
            ]);
        }

        return DB::transaction(function () use ($contract, $data, $rent, $adjustment, $from) {
            $this->writeTerm($contract, $from, $rent, $adjustment, $data['note'] ?? null);

            // ⓘ চুক্তির "এখনকার দর" = আজ খাটা দফা — সামনের মাসের দফা আজকের দর বদলায় না
            $now = $contract->terms()->where('effective_from', '<=', now()->startOfMonth()->toDateString())
                ->reorder('effective_from', 'desc')->first();

            $contract->update([
                'monthly_rent' => $now?->monthly_rent ?? $rent,
                'monthly_adjustment' => $now?->monthly_adjustment ?? $adjustment,
                'increase_percent' => array_key_exists('increase_percent', $data)
                    ? (($data['increase_percent'] ?? '') !== '' ? $data['increase_percent'] : null)
                    : $contract->increase_percent,
                'counterparty_phone' => $data['counterparty_phone'] ?? $contract->counterparty_phone,
                'subject' => $data['subject'] ?? $contract->subject,
                'note' => $data['note'] ?? $contract->note,
            ]);

            return $contract->fresh();
        });
    }

    /** শর্তের এক দফা — এক মাসে একটাই; থাকলে হালনাগাদ */
    private function writeTerm(RentalContract $contract, Carbon $from, string $rent, string $adjustment, ?string $note): void
    {
        RentalTerm::query()->updateOrCreate(
            ['rental_contract_id' => $contract->id, 'effective_from' => $from->toDateString()],
            [
                'company_id' => $contract->company_id, 'branch_id' => $contract->branch_id,
                'monthly_rent' => $rent, 'monthly_adjustment' => $adjustment, 'note' => $note, 'created_by' => auth()->id(),
            ],
        );
    }

    /**
     * জামানতে আরও টাকা — জায়গা বাড়ল, বা ভাড়া কমানোর বিনিময়ে।
     *
     * ── কেন এটা একটা ঘটনা, একটা ঘর সম্পাদনা নয় ─────────────────────
     * ⛔ `deposit_amount` ঘরটা হাতে বাড়িয়ে দিলে খাতায় **টাকাটা যেত
     * না** — চুক্তি বলত বারো লাখ, খতিয়ান বলত দশ, আর দুইটা কোনোদিন
     * মিলত না। ⭐ টাকা নড়লে ভাউচার হয়; ব্যতিক্রম নেই।
     *
     * ⓘ উল্টো দিকটা (জামানত কমিয়ে টাকা ফেরত নেওয়া) এখানে নেই — ওটা
     * চুক্তি শেষের কাজ, আর `close()` সেটা করে। মাঝপথে বাড়িওয়ালা
     * জামানত ফেরত দেন না; দিলে সেটা চুক্তি বদল, আর নতুন চুক্তি।
     *
     * @param  array<string, mixed>  $data
     */
    public function addToDeposit(RentalContract $contract, array $data): RentalContract
    {
        $this->assertActive($contract);

        $amount = (string) ($data['amount'] ?? '0');

        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('finance::validation.rental_amount_positive'),
            ]);
        }

        return DB::transaction(function () use ($contract, $data, $amount) {
            $this->lockFresh($contract);
            $this->assertActive($contract);
            $this->assertNothingWaiting($contract);

            $voucher = $this->vouchers->create(
                [
                    'type' => Voucher::PAYMENT,
                    'branch_id' => $contract->branch_id,
                    'trx_date' => (string) ($data['paid_on'] ?? now()->toDateString()),
                    'narration' => __('finance::message.rental_topup_narration', [
                        'who' => $contract->counterparty,
                    ]),
                    'instrument_no' => ($data['instrument_no'] ?? '') ?: null,
                    'against_type' => RentalContract::drillSourceType(),
                    'against_id' => $contract->id,
                ],
                [
                    [
                        'account_id' => $contract->account_id,
                        'debit' => $amount, 'credit' => '0',
                    ],
                    [
                        'account_id' => $this->moneyAccountId($data),
                        'debit' => '0', 'credit' => $amount,
                    ],
                ],
            );

            // ⛔ সই-এর আগে জামানত বাড়ে না — বাড়ায় শেষ সই ([[finishSigned()]])
            if (! $this->signature->postOrHold($voucher, FinanceSignature::RENTAL, $amount)) {
                $contract->update([
                    'deposit_amount' => bcadd((string) $contract->deposit_amount, $amount, 4),
                ]);
            }

            return $contract->fresh();
        });
    }

    /**
     * চুক্তি শেষ — আর বাকি জামানতটা ফেরত।
     *
     * ⚠️ ফেরতটা ঐচ্ছিক নয়, **ভুলে যাওয়াটাই আসল বিপদ**। তাই বন্ধ করার
     * সময়ই টাকার খাতটা চাওয়া হয়, আর কত ফেরত আসার কথা তা সে নিজেই
     * গোনে — মানুষকে গুনতে দিলে ভুল হত, আর ভুলটা তাঁর ক্ষতি।
     *
     * @param  array<string, mixed>  $data
     */
    public function close(RentalContract $contract, array $data = []): RentalContract
    {
        $this->assertActive($contract);

        $left = $contract->depositLeft();
        $on = (string) ($data['closed_on'] ?? now()->toDateString());

        return DB::transaction(function () use ($contract, $data, $left, $on) {
            // ⛔ সারিতে তালা দিয়ে তাজা অবস্থা আবার — দ্বিতীয় ক্লিক টাকা আবার বসাত (চূড়ান্ত অডিট ⛔১১)
            $this->lockFresh($contract);
            $this->assertActive($contract);
            $this->assertNothingWaiting($contract);

            // ⛔ বসানো অথচ না-দেওয়া মাস থাকলে নয় — ২১৪১-এর দায় চুক্তির সাথে হারাত (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬)
            $this->assertNothingOwed($contract);

            // ⛔ আগাম দেওয়া মাস — শেষের পরের মাসগুলো ফেরত আসে, আগেরগুলো আগে খরচে যায় ([[prepaidToRefund()]])
            $ahead = $this->prepaidToRefund($contract, Carbon::parse($on)->startOfMonth(), $data);

            if ((bccomp($left, '0', 4) > 0 || bccomp($ahead, '0', 4) > 0) && filled($data['money_account_id'] ?? null)) {
                $lines = [[
                    'account_id' => $this->moneyAccountId($data),
                    'debit' => bcadd($left, $ahead, 4), 'credit' => '0',
                ]];

                if (bccomp($left, '0', 4) > 0) {
                    $lines[] = [
                        'account_id' => $contract->account_id,
                        'debit' => '0', 'credit' => $left,
                    ];
                }

                // ⭐ আগাম ভাড়ার ফেরত একই রসিদে — ১১৩৭ শূন্যে নামে, আর জামানতের হিসাব ([[sideOf()]]) আলাদা খাতে বলে নড়ে না
                if (bccomp($ahead, '0', 4) > 0) {
                    $lines[] = [
                        'account_id' => RentalAccrualService::prepaid()->id,
                        'debit' => '0', 'credit' => $ahead,
                    ];
                }

                $voucher = $this->vouchers->create(
                    [
                        'type' => Voucher::RECEIPT,
                        'branch_id' => $contract->branch_id,
                        'trx_date' => $on,
                        'narration' => __('finance::message.rental_refund_narration', [
                            'who' => $contract->counterparty,
                        ]),
                        'instrument_no' => ($data['instrument_no'] ?? '') ?: null,
                        'against_type' => RentalContract::drillSourceType(),
                        'against_id' => $contract->id,
                    ],
                    $lines,
                );

                /*
                 * ⛔ সই-এর আগে চুক্তি শেষ নয়, জামানতও কমে না — অডিট গ১, ৪ অক্টোবর ২০২৬। ⓘ শেষ সই
                 * [[finishSigned()]] দুইটাই করে; ততক্ষণ চুক্তি চলে, আর অপেক্ষার ফেরত নতুন কিছু বসতে দেয় না।
                 */
                if ($this->signature->postOrHold($voucher, FinanceSignature::RENTAL, bcadd($left, $ahead, 4))) {
                    return $contract->fresh();
                }

                /*
                 * ⭐ ফেরতটা জামানতের অঙ্ক থেকেই বাদ যায় — ঠিক যেভাবে
                 * [[addToDeposit()]] ওটা বাড়ায়। উল্টো দিকের একই কাজ।
                 *
                 * ── কী ভাঙা ছিল, ৫ সেপ্টেম্বর ২০২৬ ─────────────────
                 * আগে কেবল অবস্থাটা বদলাত। ⛔ ফলে টাকা ফেরত আসার
                 * পরেও `depositLeft()` বলত ১১,৯০,০০০ পড়ে আছে, আর
                 * ফেরত পাওয়া চুক্তিটা **চিরকাল "ফেরত পাব" তালিকায়**
                 * থেকে যেত। ⓘ ধরা পড়েছে টেস্টে, বন্ধ করার পর
                 * `depositLeft()` শূন্য কি না দেখতে গিয়ে।
                 *
                 * ⚠️ আর একটা নতুন কলাম বসানো হয়নি ইচ্ছে করেই: ওটা
                 * খতিয়ানের দ্বিতীয় কপি হত। এখানে সংখ্যাটা ঠিক ততটাই
                 * কমে যতটা ভাউচারে ফেরত গেছে।
                 *
                 * ⭐ আর ফেরত **না দিয়ে** বন্ধ করলে অঙ্কটা অক্ষত থাকে —
                 * তাই পর্দা তখনো দেখায় টাকাটা বাড়িওয়ালার কাছে পড়ে
                 * আছে, আর সেটাই সঠিক।
                 */
                $contract->update([
                    'deposit_amount' => bcsub((string) $contract->deposit_amount, $left, 4),
                ]);
            }

            $contract->update([
                'status' => RentalContract::CLOSED,
                'closed_on' => $on,
            ]);

            return $contract->fresh();
        });
    }

    /**
     * ⭐ শেষ সই পড়ল — অপেক্ষার ভাউচারটা খাতায়, আর তার ফল চুক্তিতে ([[FinishTheFinancePaperOnTheLastSignature]])।
     *
     * ⓘ চুক্তির জোড়া (`against`) থাকলে: অপেক্ষার চুক্তি চালু হয়, বাড়ানো জামানতে যোগ হয়, ফেরত চুক্তি শেষ
     * করে — সই না থাকলে সাথে সাথে যা হত, ঠিক তাই। জোড়া না থাকলে মাসের সমন্বয়, যার সারি আগেই বসে আছে।
     */
    public function finishSigned(Voucher $voucher): void
    {
        DB::transaction(function () use ($voucher): void {
            $contract = $this->contractOf($voucher);

            if ($contract !== null) {
                $this->lockFresh($contract);
            }

            $this->lockFresh($voucher);

            if (! $voucher->isDraft()) {
                return;
            }

            $this->vouchers->post($voucher);

            if ($contract === null) {
                return;
            }

            $on = $this->sideOf($voucher, (int) $contract->account_id);

            match (true) {
                $contract->status === RentalContract::AWAITING => $contract->update(['status' => RentalContract::ACTIVE]),
                $voucher->type === Voucher::PAYMENT => $contract->update([
                    'deposit_amount' => bcadd((string) $contract->deposit_amount, $on, 4),
                ]),
                default => $contract->update([
                    'deposit_amount' => bcsub((string) $contract->deposit_amount, $on, 4),
                    'status' => RentalContract::CLOSED,
                    'closed_on' => $voucher->trx_date->toDateString(),
                ]),
            };
        });
    }

    /**
     * ⭐ সইকারী "না" বললেন — খসড়া ভাউচার বাতিল।
     *
     * ⓘ মাসের সারি সরে যায় (মাস আর জামানত আবার খালি); খোলার জামানতই "না" হলে চুক্তিটা ভুল করে বসানো
     * চুক্তির মতো সরে যায় ([[RentalContract::CLOSED]]-এর টীকা)। বাড়ানো বা ফেরত "না" হলে চুক্তি যেমন ছিল।
     */
    public function dropRefused(Voucher $voucher, string $reason): void
    {
        DB::transaction(function () use ($voucher, $reason): void {
            $this->lockFresh($voucher);

            if (! $voucher->isDraft()) {
                return;
            }

            $this->vouchers->cancel($voucher, $reason);

            RentalAdjustment::query()->where('voucher_id', $voucher->id)->delete();

            $contract = $this->contractOf($voucher);

            if ($contract !== null && $contract->status === RentalContract::AWAITING) {
                $contract->delete();
            }
        });
    }

    private function contractOf(Voucher $voucher): ?RentalContract
    {
        if ($voucher->against_type !== RentalContract::drillSourceType()) {
            return null;
        }

        return RentalContract::query()->find($voucher->against_id);
    }

    /** ভাউচারে চুক্তির খাতের অঙ্ক — পরিশোধে ডেবিট (জামানত গেল), রসিদে ক্রেডিট (ফেরত এল)। */
    private function sideOf(Voucher $voucher, int $accountId): string
    {
        $net = '0';

        foreach ($voucher->lines()->where('account_id', $accountId)->get() as $line) {
            $net = bcadd($net, bcsub((string) $line->debit, (string) $line->credit, 4), 4);
        }

        return ltrim($net, '-');
    }

    /**
     * ⛔ এই চুক্তির কোনো টাকা সই-এর অপেক্ষায় থাকলে আরেকটা নয় — অডিট গ১, ৪ অক্টোবর ২০২৬।
     *
     * ⓘ অপেক্ষার ফেরতের পাশে মাস কাটা বা জামানত বাড়ানো বসলে সই পড়ার পর টাকাটা একটা শেষ চুক্তিতে ঢুকত।
     */
    private function assertNothingWaiting(RentalContract $contract): void
    {
        if ($this->isWaiting($contract)) {
            throw ValidationException::withMessages([
                'status' => __('finance::validation.awaits_signature_first'),
            ]);
        }
    }

    /** এই চুক্তির কোনো টাকা সইয়ের অপেক্ষায় কি — পর্দার বার্তার জন্যও ([[RentalContractController]])। */
    public function isWaiting(RentalContract $contract): bool
    {
        return Voucher::query()
            ->where('status', DocumentStatus::DRAFT)
            ->where(fn ($q) => $q
                ->where(fn ($v) => $v->where('against_type', RentalContract::drillSourceType())->where('against_id', $contract->id))
                ->orWhereIn('id', RentalAdjustment::query()->where('rental_contract_id', $contract->id)->whereNotNull('voucher_id')->select('voucher_id')))
            ->exists();
    }

    /**
     * শর্তগুলো নিজেদের সাথে মেলে কি না।
     *
     * ⛔ এই তিনটা না দেখলে একটা চুক্তি বসত যেটা নিজেই নিজেকে ভাঙত, আর
     * ভুলটা ধরা পড়ত দুই বছর পরে — যেদিন ফেরত চাইতে গিয়ে দেখা যেত টাকা
     * নেই।
     */
    private function assertTermsMakeSense(string $deposit, string $rent, string $adjustment, int $term): void
    {
        if ($term < 1) {
            throw ValidationException::withMessages([
                'term_months' => __('finance::validation.rental_term_needed'),
            ]);
        }

        if (bccomp($adjustment, $rent, 4) > 0) {
            throw ValidationException::withMessages([
                'monthly_adjustment' => __('finance::validation.rental_adjustment_over_rent'),
            ]);
        }

        /*
         * ⚠️ পুরো মেয়াদের সমন্বয় জামানতের চেয়ে বেশি হতে পারে না।
         *
         * ⓘ "খ" ধরনে দুইটা **সমান** (৩০,০০০ × ২৪ = ৭,২০,০০০), আর সেটা
         * বৈধ — শেষে কিছুই ফেরত আসে না। বেশি হলেই কেবল ভুল।
         */
        $whole = bcmul($adjustment, (string) $term, 4);

        if (bccomp($whole, $deposit, 4) > 0) {
            throw ValidationException::withMessages([
                'monthly_adjustment' => __('finance::validation.rental_adjustment_exceeds_deposit', [
                    'whole' => $whole,
                    'deposit' => $deposit,
                ]),
            ]);
        }
    }

    /**
     * টাকাটা কোন খাতে বসবে।
     *
     * ⭐ ফেরতযোগ্য হলে জামানত (১১৪০), নাহলে অগ্রিম (১১৩০) — আর দুইটা
     * এক নয়। ⛔ সব এক খাতে ফেললে স্থিতিপত্রে "ফেরতযোগ্য জামানত" এমন
     * টাকা দেখাত যা কোনোদিন ফেরত আসবে না।
     *
     * ⓘ ব্যবহারকারী বাছলে তাঁরটাই — ডিফল্টটা কেবল প্রস্তাব, তালা নয়।
     *
     * @param  array<string, mixed>  $data
     */
    private function depositHead(array $data): Account
    {
        if (filled($data['account_id'] ?? null)) {
            return Account::query()->postable()->findOrFail($data['account_id']);
        }

        $whole = bcmul(
            (string) ($data['monthly_adjustment'] ?? '0'),
            (string) ($data['term_months'] ?? '0'),
            4,
        );

        $refundable = bccomp((string) ($data['deposit_amount'] ?? '0'), $whole, 4) > 0;

        return $this->head($refundable ? StandardChart::SECURITY_DEPOSIT : StandardChart::ADVANCE);
    }

    /** @param  array<string, mixed>  $data */
    private function expenseHead(array $data): Account
    {
        if (filled($data['expense_account_id'] ?? null)) {
            /*
             * ⛔ এই কোম্পানির খরচের খাতই — abos-63-এর তালিকা (abos-bb-র নিরীক্ষার বাকি), ২ অক্টোবর ২০২৬
             * ([[ARentalContractNamedAnAccountThatWasNotThereTest]])। ⓘ আগে যেকোনো পোস্টযোগ্য খাত চলত (নগদ খাতে
             * ভাড়া "খরচ" হত), আর না পেলে ৪০৪-এর ভাঙা পাতা।
             */
            $account = Account::query()->postable()->where('type', Account::EXPENSE)->find($data['expense_account_id']);

            if ($account === null) {
                throw ValidationException::withMessages([
                    'expense_account_id' => __('finance::validation.rental_account_unknown'),
                ]);
            }

            return $account;
        }

        return $this->head(StandardChart::RENT);
    }

    private function head(string $code): Account
    {
        $account = StandardChart::find($code);

        if ($account === null) {
            throw ValidationException::withMessages([
                'account_id' => __('finance::validation.rental_head_missing', ['code' => $code]),
            ]);
        }

        return $account;
    }

    private function assertMonthNotDone(RentalContract $contract, Carbon $month): void
    {
        $already = $contract->adjustments()
            ->where('for_month', $month->toDateString())
            ->exists();

        if ($already) {
            throw ValidationException::withMessages([
                'for_month' => __('finance::validation.rental_month_done_already', [
                    'month' => $month->translatedFormat('F Y'),
                ]),
            ]);
        }
    }

    private function assertDepositCovers(RentalContract $contract, string $fromDeposit): void
    {
        // ⛔ সইয়ের অপেক্ষার মাসের টাকা বাদ দিয়ে — নইলে দুই অপেক্ষার মাস একই জামানত কাটত ([[RentalContract::depositFree()]])
        if (bccomp($fromDeposit, $contract->depositFree(), 4) > 0) {
            throw ValidationException::withMessages([
                'from_deposit' => __('finance::validation.rental_no_deposit_left', [
                    'left' => $contract->depositFree(),
                ]),
            ]);
        }
    }

    /**
     * ⭐ মাসের ভাড়া দেওয়া যায় কি — চালু চুক্তিতে যেকোনো মাস; শেষ হওয়া চুক্তিতে কেবল বসানো অথচ না-দেওয়া মাস।
     *
     * ⛔ পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬: শেষ হওয়া চুক্তিতে বসানো মাস (Dr খরচ / Cr ২১৪১) আর দেওয়াই যেত না — দায়টা
     * স্থিতিপত্রে চিরকাল ঝুলত, অথচ বাড়িওয়ালা টাকাটা পাওনা। ⓘ বন্ধের পরে এমন মাস আসে পুরনো চুক্তিতে, বা ফেরতের সইয়ের
     * অপেক্ষার মাঝে মাসের জমা চললে। নতুন মাস বা আগাম মাস বন্ধ চুক্তিতে নয় — সেটা [[assertActive()]]-এর নিয়মই।
     */
    private function assertCanPay(RentalContract $contract, Carbon $month): void
    {
        if ($contract->status === RentalContract::CLOSED && $this->owedMonths($contract)->contains(
            fn (RentalAccrual $accrual) => $accrual->for_month->isSameMonth($month),
        )) {
            return;
        }

        $this->assertActive($contract);
    }

    /**
     * ⭐ বসানো অথচ না-দেওয়া মাস — ২১৪১-এ বাড়িওয়ালার পাওনা হয়ে আছে (সইয়ের অপেক্ষার বসানোও, সই পড়লেই দায়)।
     *
     * ⓘ আগাম দেওয়া মাসের জমার সারিও ([[RentalAccrualService::release()]]) এই টেবিলে, কিন্তু তার মাসের সারি আছে —
     * তাই বাদ পড়ে।
     *
     * @return Collection<int, RentalAccrual>
     */
    public function owedMonths(RentalContract $contract): Collection
    {
        return RentalAccrual::query()
            ->where('rental_contract_id', $contract->id)
            ->whereNotIn('for_month', $contract->adjustments()->select('for_month'))
            ->orderBy('for_month')
            ->get();
    }

    /**
     * ⛔ বসানো অথচ না-দেওয়া মাস থাকলে চুক্তি শেষ নয় — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬।
     *
     * ⓘ বন্ধ করলে দায়টা চোখের আড়ালে যেত: চুক্তির পাতায় মাসের ফর্ম থাকে না, আর ২১৪১ ঐ টাকা নিয়ে বসে থাকত।
     * বার্তাটা বলে কোন মাস, কত, আর কী করতে হবে — আগে মাসগুলো দিন (নগদে বা জামানত থেকে), তারপর শেষ।
     */
    private function assertNothingOwed(RentalContract $contract): void
    {
        $owed = $this->owedMonths($contract);

        if ($owed->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'closed_on' => __('finance::validation.rental_close_unpaid_months', [
                'months' => $owed->map(fn (RentalAccrual $accrual) => $accrual->for_month->translatedFormat('F Y'))->implode(', '),
                'amount' => Money::format(
                    $owed->reduce(fn (string $sum, RentalAccrual $accrual) => bcadd($sum, (string) $accrual->amount, 4), '0'),
                ),
            ]),
        ]);
    }

    /**
     * ⛔ আগাম দেওয়া মাস (১১৩৭ অগ্রিম ভাড়া) — চুক্তি শেষে চিরকাল সম্পদ হয়ে থাকত (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬)।
     *
     * ⓘ আগাম মাসটা খরচে যায় কেবল মাসের জমায় ([[RentalAccrualService::run()]]), আর সে কেবল চালু চুক্তি দেখে — বন্ধের
     * পরে ঐ টাকা আর কোথাও যেত না। মালিকের নিয়ম: নীরবে টাকা হারানো চলবে না। তাই:
     *   · শেষের মাস বা তার আগের আগাম মাস, যা এখনো খরচে যায়নি → থামা: আগে ঐ মাসের জমা চালান (জায়গাটা ঐ মাসে ব্যবহার হয়েছে)
     *   · শেষের পরের আগাম মাস → বাড়িওয়ালা টাকাটা ফেরত দেন; জামানতের সাথে একই রসিদে ফেরত, তাই টাকার খাত লাগে
     *
     * @param  array<string, mixed>  $data
     * @return string শেষের পরের মাসগুলোর আগাম ভাড়া — রসিদে ১১৩৭-এ ক্রেডিট
     */
    private function prepaidToRefund(RentalContract $contract, Carbon $closeMonth, array $data): string
    {
        $prepaid = RentalAccrualService::prepaid()->id;
        $used = [];
        $ahead = '0';
        $aheadMonths = [];

        $rows = $contract->adjustments()
            ->whereNotIn('for_month', RentalAccrual::query()->where('rental_contract_id', $contract->id)->select('for_month'))
            ->whereHas('voucher', fn ($v) => $v->where('status', DocumentStatus::CONFIRMED))
            ->with('voucher.lines')
            ->orderBy('for_month')
            ->get();

        foreach ($rows as $row) {
            $amount = bcadd((string) $row->voucher->lines->where('account_id', $prepaid)->sum('debit'), '0', 4);

            if (bccomp($amount, '0', 4) <= 0) {
                continue;
            }

            if ($row->for_month->gt($closeMonth)) {
                $ahead = bcadd($ahead, $amount, 4);
                $aheadMonths[] = $row->monthLabel();
            } else {
                $used[] = $row->monthLabel();
            }
        }

        if ($used !== []) {
            throw ValidationException::withMessages([
                'closed_on' => __('finance::validation.rental_close_prepaid_not_expensed', ['months' => implode(', ', $used)]),
            ]);
        }

        if (bccomp($ahead, '0', 4) > 0 && blank($data['money_account_id'] ?? null)) {
            throw ValidationException::withMessages([
                'money_account_id' => __('finance::validation.rental_close_prepaid_needs_refund', [
                    'months' => implode(', ', $aheadMonths),
                    'amount' => Money::format($ahead),
                ]),
            ]);
        }

        return $ahead;
    }

    private function assertActive(RentalContract $contract): void
    {
        if (! $contract->isActive()) {
            throw ValidationException::withMessages([
                'status' => __('finance::validation.rental_closed'),
            ]);
        }
    }

    /**
     * ⛔ টাকার খাত এই কোম্পানির টাকার খাতই — নগদ, ব্যাংক বা MFS ([[Account::scopeMoney()]]), ২ অক্টোবর ২০২৬
     * ([[ARentalContractNamedAnAccountThatWasNotThereTest]])। ⓘ আগে ফর্মের সংখ্যাটা সোজা দাখিলায় বসত — না-থাকা,
     * অন্য কোম্পানির বা টাকার-নয় এমন খাতও; খাতার দরজা কোথাও আটকালে ভাঙা পাতা, না আটকালে ভুল খাতে টাকা।
     *
     * @param  array<string, mixed>  $data
     */
    private function moneyAccountId(array $data): int
    {
        $account = Account::query()->money()->postable()->active()->find($data['money_account_id'] ?? null);

        if ($account === null) {
            throw ValidationException::withMessages([
                'money_account_id' => __('finance::validation.rental_account_unknown'),
            ]);
        }

        return (int) $account->id;
    }
}
