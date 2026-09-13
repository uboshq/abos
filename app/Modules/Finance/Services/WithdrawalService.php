<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Models\WithdrawalLimit;
use App\Modules\MasterData\Models\Person;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * উত্তোলন — মালিকের টাকা মালিকের কাছে, কিন্তু লেখা থাকে।
 *
 * ── কেন এই পর্দাটা লাগল, ৩০ আগস্ট ২০২৬ ───────────────────────────────
 * টাকাটা তোলা যেত আগেও — একটা পরিশোধ ভাউচার, ডেবিট ৩২০০। কিন্তু
 * ভাউচারে "কে তুলল" বলার কোনো ঘর নেই, তাই নামটা থাকত কেবল বিবরণে, আর
 * [[CapitalService::withdrawnBy()]] ওই লেখা মিলিয়ে খুঁজত।
 *
 * বানান একটু আলাদা হলেই টাকাটা কারও নামে বসত না — আর অংশীদারি ব্যবসায়
 * ওই সংখ্যাটা নিয়েই ঝগড়া হয়।
 *
 * ── অনুরোধ, তারপর খাতা — দুইটা ধাপ ───────────────────────────────────
 * মালিকের পরিকল্পনার ভাষায়: **অনুরোধ → অনুমোদন → ভাউচার → খতিয়ান**।
 * লেখা আর খাতায় বসা আলাদা রাখা হয়েছে মূলধনের পর্দার মতোই, একই কারণে:
 * কথা হয় একদিন, টাকা যায় আরেকদিন।
 *
 * অনুমোদনের প্রবাহ বসানো না থাকলে ইঞ্জিন `null` ফেরায়, আর তখন সারিটা
 * সরাসরি বসানোর জন্য প্রস্তুত — অনুমোদন **বাধ্যতামূলক নয়, ঐচ্ছিক
 * নিয়ন্ত্রণ**। বাধ্যতামূলক করলে যে ডিপো কোনো প্রবাহ বসায়নি তার
 * প্রতিটা উত্তোলন চিরকাল ঝুলে থাকত।
 */
final class WithdrawalService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly VoucherService $vouchers,
        private readonly ApprovalEngine $approvals,
    ) {}

    /**
     * কেউ তুলতে চাইছেন — লিখে রাখা, আর দরকার হলে অনুমোদনে পাঠানো।
     *
     * @param  array<string, mixed>  $data
     */
    public function request(array $data): Withdrawal
    {
        $personId = (int) ($data['person_id'] ?? 0);
        $amount = (string) ($data['amount'] ?? '0');
        $on = (string) ($data['trx_date'] ?? now()->toDateString());

        if ($personId <= 0) {
            throw ValidationException::withMessages([
                'person_id' => __('finance::validation.withdrawal_needs_a_name'),
            ]);
        }

        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('finance::validation.withdrawal_must_be_positive'),
            ]);
        }

        $this->assertWithinCap($personId, $amount, $on);

        return DB::transaction(function () use ($data, $personId, $amount, $on) {
            $withdrawal = Withdrawal::query()->create([
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'document_no' => $this->numbers->next('WDR'),
                'person_id' => $personId,
                'amount' => $amount,
                'trx_date' => $on,
                'money_account_id' => $data['money_account_id'] ?? null,
                'reason' => ($data['reason'] ?? '') ?: null,
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            /*
             * প্রবাহ বসানো থাকলে অনুমোদনে যায়; না থাকলে `null` ফেরে
             * আর সারিটা খসড়া হিসেবেই বসার অপেক্ষায় থাকে।
             */
            $this->approvals->request(
                document: $withdrawal,
                module: 'finance',
                action: 'withdrawal',
                amount: $amount,
                reason: $withdrawal->reason,
            );

            return $withdrawal;
        });
    }

    /**
     * টাকা গেল — খাতায় বসানো।
     *
     * ── অঙ্কটা ──────────────────────────────────────────────────────
     * Dr ৩২০০ উত্তোলন · Cr নগদ/ব্যাংক।
     *
     * উত্তোলন মূলধনের ঘরে বসা একটা **ডেবিট প্রকৃতির** খাত — ওটা মূলধন
     * কমায়। খরচ (৫০০০) নয়, কারণ খরচ ব্যবসা চালাতে লাগে; এটা মালিকের
     * নিজের টাকা নিয়ে যাওয়া। খরচে ফেললে ব্যবসার মুনাফা কম দেখাত।
     *
     * ── `$reference` — ব্যাংক বা MFS হলে যে নম্বরটা লাগে ──────────────
     * চেক নম্বর, ট্রানজেকশন আইডি, বিকাশের TrxID। ⛔ ঐচ্ছিক, কারণ নগদে
     * নম্বর হয় না, আর কখন লাগবে সেটা এক জায়গায় জানা —
     * [[App\Modules\Accounts\Services\VoucherService::assertBankReferenceIsFree]]।
     * দুই জায়গায় নিয়ম রাখলে একদিন দুইটা আলাদা কথা বলত।
     */
    public function post(Withdrawal $withdrawal, Account $from, ?string $reference = null): Withdrawal
    {
        if ($withdrawal->isPosted()) {
            throw ValidationException::withMessages([
                'status' => __('finance::validation.withdrawal_already_posted', [
                    'no' => $withdrawal->document_no,
                ]),
            ]);
        }

        if ($from->is_group) {
            throw ValidationException::withMessages([
                'money_account_id' => __('finance::validation.not_a_postable_account'),
            ]);
        }

        $pending = $this->approvals->latestFor($withdrawal, 'withdrawal');

        /*
         * অনুমোদন ঝুলে থাকলে টাকা যায় না।
         *
         * ── কেন সেবাই আটকায়, পর্দা নয় ───────────────────────────────
         * বোতামটা লুকিয়ে রাখলে ঠিকানা টাইপ করেই পোস্ট করা যেত, আর
         * অনুমোদনের গোটা ব্যবস্থাটা সাজসজ্জা হয়ে যেত।
         */
        if ($pending !== null && $pending->isPending()) {
            throw ValidationException::withMessages([
                'status' => __('finance::validation.withdrawal_awaits_approval', [
                    'no' => $withdrawal->document_no,
                ]),
            ]);
        }

        return DB::transaction(function () use ($withdrawal, $from, $reference) {
            $drawings = StandardChart::find(StandardChart::DRAWINGS);

            if ($drawings === null) {
                throw ValidationException::withMessages([
                    'account_id' => __('finance::validation.chart_head_missing', [
                        'code' => StandardChart::DRAWINGS,
                    ]),
                ]);
            }

            $voucher = $this->vouchers->create(
                [
                    'type' => Voucher::PAYMENT,
                    'trx_date' => $withdrawal->trx_date->toDateString(),
                    'narration' => __('finance::message.withdrawal_narration', [
                        /*
                         * নামটা এখন ব্যক্তির সারি থেকে।
                         *
                         * ⓘ বিবরণে নামটা থাকে **পড়ার জন্য** — কেউ খতিয়ান
                         * খুলে যেন বুঝতে পারেন টাকাটা কার। ⛔ কিন্তু হিসাব
                         * আর কখনো এই লেখাটা ধরে হয় না: আগে
                         * [[App\Modules\Finance\Services\CapitalService::withdrawnBy]]
                         * ঠিক এই বিবরণেই `LIKE '%নাম%'` করত, আর তাতে
                         * "রহিম"-এর হিসাবে "আব্দুর রহিম"-এর টাকা বসত।
                         */
                        'who' => $withdrawal->person?->name() ?? '',
                        'no' => $withdrawal->document_no,
                    ]),
                    'instrument_no' => $reference,
                ],
                [
                    ['account_id' => $drawings->id, 'debit' => $withdrawal->amount, 'credit' => '0'],
                    ['account_id' => $from->id, 'debit' => '0', 'credit' => $withdrawal->amount],
                ],
            );

            $this->vouchers->post($voucher);

            $withdrawal->forceFill([
                'status' => DocumentStatus::CONFIRMED,
                'voucher_id' => $voucher->id,
                'money_account_id' => $from->id,
                'posted_at' => now(),
            ])->save();

            return $withdrawal->fresh();
        });
    }

    /**
     * এই মানুষটির মাসিক সীমা বসানো বা বদলানো।
     *
     * শূন্য বা খালি দিলে সীমাটা তুলে নেওয়া হয় — সারি না থাকা মানে
     * সীমা নেই, শূন্য নয়।
     */
    public function setCap(int $personId, ?string $cap): ?WithdrawalLimit
    {
        if ($personId <= 0) {
            throw ValidationException::withMessages([
                'person_id' => __('finance::validation.withdrawal_needs_a_name'),
            ]);
        }

        if ($cap === null || $cap === '' || bccomp($cap, '0', 4) <= 0) {
            WithdrawalLimit::query()->where('person_id', $personId)->delete();

            return null;
        }

        /*
         * ⓘ একজনের একটাই সীমা, আর সেটা এখন ডাটাবেজেও বাঁধা
         * (`unique(company_id, person_id)`)। আগে অনন্যতাটা কেবল এই
         * `updateOrCreate`-এর ভরসায় ছিল, আর দুই বানান মানে দুইটা সারি —
         * দুইটা সীমা, আর কোনটা খাটবে তা নির্ভর করত উত্তোলনে কোন বানান
         * লেখা হয়েছে তার উপর।
         */
        return WithdrawalLimit::query()->updateOrCreate(
            ['company_id' => CompanyContext::id(), 'person_id' => $personId],
            ['monthly_cap' => $cap],
        );
    }

    /**
     * কে এই মাসে কত তুলেছেন, আর সীমার কতটা বাকি।
     *
     * ── কেন মূলধনের সারি থেকেও মানুষগুলো ─────────────────────────────
     * যিনি টাকা দেননি তিনি তোলার প্রশ্নই ওঠে না, কিন্তু যাঁর সীমা বসানো
     * আছে অথচ এখনো কিছু তোলেননি তাঁকেও দেখাতে হয় — নাহলে পর্দাটা বলত
     * সীমাটা নেই।
     *
     * ── ⛔ আগে এখানে যা লেখা ছিল, আর কেন সেটা অর্ধসত্য ছিল ───────────
     * মন্তব্যে লেখা ছিল: *"নামের তালিকা আলাদা করে রাখলে দুইটা তালিকা
     * একদিন আলাদা হয়ে যেত, আর «করিম» আর «মোঃ করিম» দুইজন হয়ে বসতেন।"*
     *
     * ⚠️ **রোগটা ঠিক ধরা হয়েছিল, ওষুধটা নয়।** তিনটা উৎস জুড়ে দিলে
     * তালিকা এক হয়, কিন্তু "করিম" আর "মোঃ করিম" **তবু দুইজনই থাকতেন** —
     * কারণ জোড়াটা হত নাম ধরে, আর নামই ছিল পরিচয়। দুইটা সারি, দুইটা
     * সীমা, আর কোনটা খাটবে তা নির্ভর করত উত্তোলনে কোন বানান লেখা হয়েছে
     * তার উপর।
     *
     * এখন জোড়াটা `person_id` ধরে, তাই সমস্যাটার অস্তিত্বই নেই।
     *
     * @return list<array<string, mixed>>
     */
    public function standing(?string $month = null): array
    {
        $month = $month !== null ? Carbon::parse($month) : now();
        $from = $month->copy()->startOfMonth()->toDateString();
        $to = $month->copy()->endOfMonth()->toDateString();

        $caps = WithdrawalLimit::query()->pluck('monthly_cap', 'person_id');

        $personIds = Withdrawal::query()->distinct()->pluck('person_id')
            ->merge($caps->keys())
            ->merge(DB::table('acc_capital_entries')
                ->where('company_id', CompanyContext::id())
                ->distinct()->pluck('person_id'))
            ->filter()->unique()->values();

        /*
         * নামগুলো একবারেই — প্রতি সারিতে একটা করে কোয়েরি নয়।
         *
         * ⓘ সাজানোটাও নামে, আইডিতে নয়: পর্দায় মানুষ নাম দেখেন, আর
         * আইডির ক্রম তাঁদের কাছে এলোমেলো।
         */
        $people = Person::query()->whereKey($personIds)->get()->keyBy('id');

        $out = [];

        foreach ($personIds as $personId) {
            $person = $people->get((int) $personId);

            if ($person === null) {
                continue;
            }

            $thisMonth = Withdrawal::query()
                ->where('person_id', $personId)
                ->where('status', '!=', DocumentStatus::CANCELLED)
                ->whereBetween('trx_date', [$from, $to])
                ->sum('amount');

            $everything = Withdrawal::query()
                ->where('person_id', $personId)
                ->posted()
                ->sum('amount');

            $cap = $caps[$personId] ?? null;

            $out[] = [
                'person_id' => (int) $personId,
                'name' => $person->name(),
                'cap' => $cap !== null ? (string) $cap : null,
                'this_month' => (string) $thisMonth,
                'left' => $cap !== null
                    ? bcsub((string) $cap, (string) $thisMonth, 4)
                    : null,
                'taken_all' => (string) $everything,
            ];
        }

        // নাম ধরে সাজানো — পর্দায় ওটাই মানুষ পড়েন
        usort($out, fn (array $a, array $b) => strcmp((string) $a['name'], (string) $b['name']));

        return $out;
    }

    /**
     * সীমা পেরোলে আটকানো — আর কতটা বাকি সেটা বলা।
     *
     * ── কেন আটকানো, কেবল সতর্ক করা নয় ──────────────────────────────
     * সতর্কবার্তা তৃতীয়বার থেকে কেউ পড়ে না, আর তখন সীমাটা একটা
     * সাজানো সংখ্যা হয়ে যায়। আটকালে সীমাটার একটা মানে থাকে।
     *
     * ── আর কেন এটা অচলাবস্থা নয় ────────────────────────────────────
     * সীমাটা একই পর্দা থেকে বদলানো যায়। অর্থাৎ বেশি তুলতে হলে সেটা
     * একটা **দৃশ্যমান সিদ্ধান্ত** — কেউ একটা সংখ্যা বদলাল, আর সেটা
     * অডিটে থাকে — নিঃশব্দে সীমা পেরোনো নয়।
     */
    private function assertWithinCap(int $personId, string $amount, string $on): void
    {
        /*
         * ⛔ মিলটা `person_id` ধরে, নাম ধরে নয় — আর এটাই এই ফাইলের
         * সবচেয়ে জরুরি লাইন (১৩ সেপ্টেম্বর ২০২৬)।
         *
         * ── আগে দুইভাবে টাকা বেরিয়ে যেত, আর দুইটাই নিঃশব্দে ─────────
         * ১। `where('contributor_name', $name)` — সীমা বসেছিল "Al Amin"
         *    নামে, উত্তোলন লেখা হলো "Al-Amin"। `$cap` হত `null`, আর নিচের
         *    `return`-টা চুপচাপ বেরিয়ে যেত — **সীমা একেবারেই বসত না**,
         *    কোনো বার্তা ছাড়া।
         * ২। নিচের `$already`-ও নামে মেলানো হত, তাই আগের ভিন্ন-বানানের
         *    উত্তোলনগুলো যোগ হত না — সীমা বসত ভুল (কম) মোটের উপর, আর
         *    কেউ সীমার দ্বিগুণ তুলে ফেলতে পারতেন।
         *
         * ⚠️ দ্বিতীয়টা প্রথমটার চেয়ে খারাপ: প্রথমটায় সীমা কাজ করে না, যা
         * অন্তত ধারাবাহিক। দ্বিতীয়টায় সীমা কাজ করছে বলে **মনে হয়**, আর
         * "বাকি আছে এতটা" সংখ্যাটা মিথ্যা।
         *
         * দুইটাই বাঁধা আছে আলাদা দাবিতে —
         * [[Tests\Feature\Modules\Finance\TheCapWasSetOnANameAndTheNameChangedTest]]।
         */
        $cap = WithdrawalLimit::query()->where('person_id', $personId)->value('monthly_cap');

        if ($cap === null) {
            return;
        }

        $month = Carbon::parse($on);

        $already = Withdrawal::query()
            ->where('person_id', $personId)
            ->where('status', '!=', DocumentStatus::CANCELLED)
            ->whereBetween('trx_date', [
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            ])
            ->sum('amount');

        $after = bcadd((string) $already, $amount, 4);

        if (bccomp($after, (string) $cap, 4) > 0) {
            /*
             * অঙ্ক দুইটা সাজিয়ে — কাঁচা `10000.0000` নয়।
             *
             * এটা একটা প্রত্যাখ্যানের বার্তা, আর প্রত্যাখ্যান পড়েই
             * মানুষ ঠিক করেন পরে কী করবেন। চারটা দশমিক আর কমা ছাড়া
             * সংখ্যা পড়তে গিয়ে থামতে হলে বার্তাটা তার কাজ করে না।
             */
            throw ValidationException::withMessages([
                'amount' => __('finance::validation.withdrawal_over_cap', [
                    'cap' => Money::format((string) $cap),
                    'left' => Money::format(bcsub((string) $cap, (string) $already, 4)),
                ]),
            ]);
        }
    }
}
