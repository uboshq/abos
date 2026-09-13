<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\RentalAdjustment;
use App\Modules\Finance\Models\RentalContract;
use Illuminate\Support\Carbon;
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
    public function __construct(private readonly VoucherService $vouchers) {}

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
                'counterparty' => (string) $data['counterparty'],
                'counterparty_phone' => $data['counterparty_phone'] ?? null,
                'subject' => $data['subject'] ?? null,
                'account_id' => $this->depositHead($data)->id,
                'expense_account_id' => $this->expenseHead($data)->id,
                'deposit_amount' => $deposit,
                'monthly_rent' => $rent,
                'monthly_adjustment' => $adjustment,
                'starts_on' => $starts->toDateString(),
                'term_months' => $term,

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
                'created_by' => auth()->id(),
            ]);

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
                        'trx_date' => $starts->toDateString(),
                        'narration' => __('finance::message.rental_deposit_narration', [
                            'who' => $contract->counterparty,
                        ]),

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
                            'account_id' => (int) $data['money_account_id'],
                            'debit' => '0', 'credit' => $deposit,
                        ],
                    ],
                );

                $this->vouchers->post($voucher);
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
        if (! $contract->isActive()) {
            throw ValidationException::withMessages([
                'status' => __('finance::validation.rental_closed'),
            ]);
        }

        $month = Carbon::parse((string) ($data['for_month'] ?? now()->toDateString()))->startOfMonth();

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
        if (bccomp($fromDeposit, $contract->depositLeft(), 4) > 0) {
            throw ValidationException::withMessages([
                'from_deposit' => __('finance::validation.rental_no_deposit_left', [
                    'left' => $contract->depositLeft(),
                ]),
            ]);
        }

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
        $already = $contract->adjustments()
            ->whereDate('for_month', $month->toDateString())
            ->exists();

        if ($already) {
            throw ValidationException::withMessages([
                'for_month' => __('finance::validation.rental_month_done_already', [
                    'month' => $month->translatedFormat('F Y'),
                ]),
            ]);
        }

        if (bccomp($cash, '0', 4) > 0 && blank($data['money_account_id'] ?? null)) {
            throw ValidationException::withMessages([
                'money_account_id' => __('finance::validation.rental_needs_money_account'),
            ]);
        }

        return DB::transaction(function () use ($contract, $data, $month, $rent, $cash, $fromDeposit) {
            $lines = [[
                'account_id' => $contract->expense_account_id,
                'debit' => $rent, 'credit' => '0',
            ]];

            if (bccomp($cash, '0', 4) > 0) {
                $lines[] = [
                    'account_id' => (int) $data['money_account_id'],
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
                    'trx_date' => (string) ($data['paid_on'] ?? $month->toDateString()),
                    'narration' => __('finance::message.rental_month_narration', [
                        'who' => $contract->counterparty,
                        'month' => $month->translatedFormat('F Y'),
                    ]),
                    'instrument_no' => ($data['instrument_no'] ?? '') ?: null,
                ],
                $lines,
            );

            $this->vouchers->post($voucher);

            return RentalAdjustment::create([
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
        if (! $contract->isActive()) {
            throw ValidationException::withMessages([
                'status' => __('finance::validation.rental_closed'),
            ]);
        }

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

        if (bccomp($needed, $contract->depositLeft(), 4) > 0) {
            throw ValidationException::withMessages([
                'monthly_adjustment' => __('finance::validation.rental_adjustment_exceeds_deposit', [
                    'whole' => $needed,
                    'deposit' => $contract->depositLeft(),
                ]),
            ]);
        }

        $contract->update([
            'monthly_rent' => $rent,
            'monthly_adjustment' => $adjustment,
            'counterparty_phone' => $data['counterparty_phone'] ?? $contract->counterparty_phone,
            'subject' => $data['subject'] ?? $contract->subject,
            'note' => $data['note'] ?? $contract->note,
        ]);

        return $contract->fresh();
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
        if (! $contract->isActive()) {
            throw ValidationException::withMessages([
                'status' => __('finance::validation.rental_closed'),
            ]);
        }

        $amount = (string) ($data['amount'] ?? '0');

        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('finance::validation.rental_amount_positive'),
            ]);
        }

        return DB::transaction(function () use ($contract, $data, $amount) {
            $voucher = $this->vouchers->create(
                [
                    'type' => Voucher::PAYMENT,
                    'trx_date' => (string) ($data['paid_on'] ?? now()->toDateString()),
                    'narration' => __('finance::message.rental_topup_narration', [
                        'who' => $contract->counterparty,
                    ]),
                    'instrument_no' => ($data['instrument_no'] ?? '') ?: null,
                ],
                [
                    [
                        'account_id' => $contract->account_id,
                        'debit' => $amount, 'credit' => '0',
                    ],
                    [
                        'account_id' => (int) $data['money_account_id'],
                        'debit' => '0', 'credit' => $amount,
                    ],
                ],
            );

            $this->vouchers->post($voucher);

            $contract->update([
                'deposit_amount' => bcadd((string) $contract->deposit_amount, $amount, 4),
            ]);

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
        if (! $contract->isActive()) {
            throw ValidationException::withMessages([
                'status' => __('finance::validation.rental_closed'),
            ]);
        }

        $left = $contract->depositLeft();
        $on = (string) ($data['closed_on'] ?? now()->toDateString());

        return DB::transaction(function () use ($contract, $data, $left, $on) {
            if (bccomp($left, '0', 4) > 0 && filled($data['money_account_id'] ?? null)) {
                $voucher = $this->vouchers->create(
                    [
                        'type' => Voucher::RECEIPT,
                        'trx_date' => $on,
                        'narration' => __('finance::message.rental_refund_narration', [
                            'who' => $contract->counterparty,
                        ]),
                        'instrument_no' => ($data['instrument_no'] ?? '') ?: null,
                    ],
                    [
                        [
                            'account_id' => (int) $data['money_account_id'],
                            'debit' => $left, 'credit' => '0',
                        ],
                        [
                            'account_id' => $contract->account_id,
                            'debit' => '0', 'credit' => $left,
                        ],
                    ],
                );

                $this->vouchers->post($voucher);

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
            return Account::query()->postable()->findOrFail($data['expense_account_id']);
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
}
