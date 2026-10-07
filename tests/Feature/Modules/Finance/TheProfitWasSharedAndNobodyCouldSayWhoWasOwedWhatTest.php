<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\ProfitShare;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\Finance\Services\ProfitDistribution;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * লাভ ভাগ হলো, অথচ কার কত পাওনা কেউ বলতে পারত না।
 *
 * ── ⭐ মালিকের নকশা, ২২ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"টাকাটা তুলে নেবেন"*, আর *"র থাকলে বছর শেষে capital-এ যোগ হবে বা
 * invest-এ"*।
 *
 * ── ⛔ কেন দাখিলাটা দায়ে বসে, মূলধনে নয় ────────────────────────────
 * মূলধনে বসালে পরে টাকা তোলাটা খাতায় **মূলধন প্রত্যাহার** বলে দেখাত —
 * অর্থাৎ খাতা বলত মালিক ব্যবসা থেকে পুঁজি সরাচ্ছেন, অথচ তিনি নিজের
 * লাভ নিচ্ছেন। ⚠️ দুইটা সম্পূর্ণ আলাদা কথা, আর অংশীদারি ব্যবসায় ওই
 * পার্থক্যটাই পরে ঝগড়া হয়।
 *
 * ── ⚠️ কেন সংখ্যা ধরে ধরে মাপা ─────────────────────────────────────
 * "পর্দা খোলে" বা "সারি লেখা হয়" — এটুকু দাবি প্রায় কিছুই বলে না।
 * ⛔ একটা বণ্টন নিখুঁত চলে **ভুল খাতে** টাকা বসাতে পারে, আর কেউ টের
 * পায় না, কারণ রেওয়ামিল তবু মেলে: ডেবিট-ক্রেডিট সমান থাকে, কেবল
 * অর্থটা অন্য।
 */
final class TheProfitWasSharedAndNobodyCouldSayWhoWasOwedWhatTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->switchCompany($company->id);
        $this->be($this->owner);

        /*
         * ⛔ ডেমোতে একজনও মূলধনদাতা নেই — ২২ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ প্রথম রানে দাবিগুলো লাল হয়েছিল *"একজনও মূলধনদাতা
         * নেই"* বলে — আর সেটাই চাওয়া ছিল। ⚠️ শর্তটা না থাকলে
         * `positions()` খালি তালিকা দিত, ভাগ লেখা হত না, আর
         * পরীক্ষাগুলো **কিছুই না মেপে** সবুজ থাকত।
         *
         * ⭐ তাই অবস্থাটা পরীক্ষাই বানায়। দুইজন, অসম মূলধন —
         * সমান হলে ভাগও সমান হত, আর অনুপাত ঠিক কি না তা
         * বোঝা যেত না।
         */
        $this->contribute('PROF-A', 'Partner A', '600000');
        $this->contribute('PROF-B', 'Partner B', '400000');
    }

    /**
     * একজন মূলধনদাতা, বসানো মূলধনসহ।
     *
     * ⓘ সারিটা সরাসরি লেখা হয়, ভাউচার ছাড়া — [[CapitalService::positions()]]
     * এন্ট্রির টেবিল পড়ে, খতিয়ান নয়। ⚠️ পোস্ট করলে নগদও
     * বাড়ত, আর এই পরীক্ষায় সেটা অপ্রাসঙ্গিক শব্দ।
     */
    private function contribute(string $code, string $name, string $amount): void
    {
        $person = Person::query()->create([
            'code' => $code,
            'name_en' => $name,
            'name_bn' => $name,
            'is_active' => true,
        ]);

        CapitalEntry::query()->create([
            'branch_id' => Branch::query()->firstOrFail()->id,
            'document_no' => 'CAP-'.$code,
            'person_id' => $person->id,
            'contributor_type' => CapitalEntry::CONTRIBUTION,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'in_kind' => CapitalEntry::CASH,
            'trx_date' => now()->subMonth()->toDateString(),
            'amount' => $amount,
            'status' => CapitalEntry::POSTED,
        ]);
    }

    /**
     * ⭐ ভাগের যোগফল বণ্টিত অঙ্কের সমান — এক পয়সাও হারায় না।
     */
    public function test_the_shares_add_up_to_what_was_shared(): void
    {
        $rows = $this->distribute('100000');

        $sum = '0';

        foreach ($rows as $row) {
            $sum = bcadd($sum, (string) $row->amount, 4);
        }

        $this->assertSame(0, bccomp($sum, '100000', 4), implode("\n", [
            'ভাগগুলোর যোগফল বণ্টিত অঙ্কের সমান নয়।',
            '',
            'ⓘ চাওয়া হয়েছিল ১,০০,০০০ · ভাগ হয়েছে '.$sum,
            '',
            '⛔ বড়-অবশিষ্ট নিয়মে ভাগ করলে এক পয়সাও হারানোর কথা নয়।',
        ]));
    }

    /**
     * ⭐ টাকাটা দায়ে বসে, মূলধনে নয় — এই কাজের গোটা কারণ।
     */
    public function test_the_money_lands_in_the_payable_not_in_capital(): void
    {
        $before = $this->balance(StandardChart::OWNER_CAPITAL);

        $this->distribute('50000');

        $payable = $this->movement(StandardChart::PROFIT_PAYABLE);
        $retained = $this->movement(StandardChart::RETAINED_EARNINGS);

        $this->assertSame(0, bccomp($payable['credit'], '50000', 4), implode("\n", [
            'প্রদেয় মুনাফায় ৫০,০০০ ক্রেডিট হয়নি — এসেছে '.$payable['credit'].'।',
            '',
            'ⓘ ঘোষণার দিন অঙ্কটা এখানেই নামার কথা।',
        ]));

        $this->assertSame(0, bccomp($retained['debit'], '50000', 4),
            'সঞ্চিত মুনাফা থেকে ৫০,০০০ ডেবিট হয়নি — হয়েছে '.$retained['debit'].'।');

        $this->assertSame(0, bccomp($this->balance(StandardChart::OWNER_CAPITAL), $before, 4),
            implode("\n", [
                'মূলধনের জের বদলে গেছে।',
                '',
                '⛔ বণ্টন মূলধনে বসলে পরে টাকা তোলাটা "মূলধন প্রত্যাহার" বলে',
                '   দেখাত — খাতা বলত মালিক পুঁজি সরাচ্ছেন, অথচ তিনি নিজের',
                '   লাভ নিচ্ছেন।',
            ]));
    }

    /**
     * ⭐ প্রতিটা ক্রেডিট লাইনে কার নাম, তা লেখা থাকে।
     *
     * ── ⚠️ কেন আলাদা দাবি ───────────────────────────────────────────
     * উপরেরটা কেবল বলে **মোট** ঠিক খাতে গেছে। ⛔ নাম না বসালেও ওটা
     * সবুজ থাকত, আর তখন খতিয়ান বলত "৫০,০০০ দিতে বাকি" — কাকে, তা নয়।
     */
    public function test_every_credit_line_names_the_person(): void
    {
        $rows = $this->distribute('60000');

        $account = Account::query()->where('code', StandardChart::PROFIT_PAYABLE)->firstOrFail();

        $lines = LedgerEntry::query()
            ->where('account_id', $account->id)
            ->where('credit', '>', 0)
            ->get();

        $this->assertCount(count($rows), $lines,
            'ক্রেডিট লাইনের সংখ্যা ভাগের সংখ্যার সমান নয়।');

        foreach ($lines as $line) {
            $this->assertSame('person', $line->party_type, implode("\n", [
                'ক্রেডিট লাইনে পক্ষের ধরন বসেনি।',
                '',
                '⛔ তখন খতিয়ান বলত "কত দিতে বাকি", বলত না "কাকে"।',
            ]));

            $this->assertNotNull($line->party_id, 'ক্রেডিট লাইনে পক্ষের নাম বসেনি।');
        }
    }

    /**
     * ⭐ অংশ ও ভিত্তি সারিতেই লেখা থাকে।
     *
     * ── ⛔ কেন এটা জরুরি ────────────────────────────────────────────
     * পরে অনুপাত বদলালে বা বছরের মুনাফা সংশোধিত হলে পুরনো ঘোষণার ভাগ
     * বদলে যেত। ⚠️ অনুমোদিত কাগজ নিজে থেকে বদলায় না — ছয় মাস পরে
     * অংশীদার *"আমার ভাগ কত ছিল"* জিজ্ঞেস করলে উত্তরটা এক থাকা চাই।
     */
    public function test_the_row_remembers_the_ratio_it_was_worked_out_on(): void
    {
        $rows = $this->distribute('80000');

        foreach ($rows as $row) {
            $this->assertSame(0, bccomp((string) $row->profit_base, '80000', 4),
                'সারিতে কোন মুনাফার উপর ভাগ হয়েছিল তা লেখা নেই।');

            $this->assertNotNull($row->share_percent,
                'সারিতে অংশের শতাংশ লেখা নেই — পরে অনুপাত বদলালে ভাগও বদলে যেত।');

            $this->assertSame(ProfitShare::POSTED, $row->status);
            $this->assertNotNull($row->voucher_id, 'সারিটা কোনো ভাউচারের সাথে বাঁধা নেই।');
        }

        // ⭐ এক ঘোষণা, এক নম্বর — যতজনই ভাগ পান
        $this->assertCount(1, collect($rows)->pluck('document_no')->unique(),
            'এক ঘোষণার সারিগুলোর নম্বর আলাদা।');
    }

    /**
     * ⛔ লোকসান ভাগ করা যায় না।
     */
    public function test_a_loss_cannot_be_shared(): void
    {
        foreach (['0', '-5000'] as $notAProfit) {
            try {
                app(ProfitDistribution::class)->declare([
                    'trx_date' => now()->toDateString(),
                    'profit' => $notAProfit,
                ]);

                $this->fail('"'.$notAProfit.'" ভাগ করা গেল — লোকসান আলাদা সিদ্ধান্ত।');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('profit', $e->errors());

                /*
                 * ⛔ কেবল ঘরের নাম মেলানো যথেষ্ট নয় — ২২ সেপ্টেম্বর ২০২৬।
                 *
                 * ⚠️ প্রথম খসড়ায় এখানে শুধু `assertArrayHasKey('profit')`
                 * ছিল। ⓘ মিউটেশনে ধনাত্মক-মুনাফার শর্তটা তুলে দেওয়ার
                 * পরেও দাবিটা **সবুজ থেকেছে**: লোকসানে কারও ভাগ শূন্য
                 * হয়ে যায়, তালিকা খালি হয়, আর *অন্য* একটা নিয়ম
                 * ("ভাগ বসানোর কেউ নেই") ঠিক **একই ঘরে** ভুল বসায়।
                 *
                 * ⭐ অর্থাৎ দাবিটা পাস করত ভুল কারণে — আর সেটা পাস না
                 * করার চেয়েও খারাপ, কারণ পড়ে মনে হয় শর্তটা কাজ করছে।
                 */
                $this->assertSame(
                    [__('finance::validation.profit_must_be_positive')],
                    $e->errors()['profit'],
                    implode("\n", [
                        'ভুলটা আটকেছে, কিন্তু অন্য নিয়মে।',
                        '',
                        '⛔ "'.$notAProfit.'"-কে ধনাত্মক-মুনাফার শর্তেই আটকানোর কথা।',
                    ]),
                );
            }
        }
    }

    /**
     * ⛔ ঘোষণার একটা দাখিলাও দল-খাতে বসে না।
     *
     * ── ⚠️ যা ভাঙা ছিল, ২২ সেপ্টেম্বর ২০২৬ ──────────────────
     * খাত বেছে নেওয়ার সহায়কটা কেবল কোড মিলাত — `where('code', …)`
     * — আর যা আসত তাই নিত, দল হলেও। এখন `postable()` ছাঁকে।
     *
     * ── ⭐ মিউটেশন যা দেখাল ────────────────────────────
     * চার্টে ২১৯০-কে দল বানিয়ে আর `postable()` সরিয়ে চালালাম।
     * ⓘ দাবিটা লাল হলো, কিন্তু আমার নিজের বাক্যে নয় —
     * খতিয়ান-ইঞ্জিন নিজেই ফিরিয়ে দিল:
     * *"গ্রুপ খাতে সরাসরি লেনদেন বসে না"*।
     *
     * ⚠️ অর্থাৎ ক্ষতিটা **নীরব ভুল জের ছিল না** — ছিল পোস্ট
     * করার মাঝপথে একটা ছুঁড়ে ফেলা। `postable()` বাধাটা আগে
     * নিয়ে আসে, যেখানে বার্তাটা *"চার্টে এই খাতটা নেই"* —
     * যা সত্যিই বোঝা যায়।
     *
     * ⓘ তাই দাবিটা যা সত্যি পাহারা দেয়: চার্ট বা সহায়ক
     * বদলালে ঘোষণাটা আর চুপচাপ বসবে না — হয় সারিগুলো
     * পোস্টযোগ্য খাতে বসবে, নয়তো এই পরীক্ষাটা লাল হবে।
     */
    public function test_no_line_lands_on_a_group_head(): void
    {
        $rows = $this->distribute('100000');

        /*
         * ⓘ গোটা খতিয়ান নয় — কেবল এই ঘোষণার ভাউচারটা। ⚠️ ডেমোর
         * পুরনো সারি গুনলে দাবিটা অন্য কারণে লাল হত, আর তখন
         * এটা আর লাভ-বণ্টনের পাহারা থাকত না।
         */
        $voucherId = (int) $rows[0]->voucher_id;

        $this->assertGreaterThan(0, $voucherId, 'ঘোষণার সারি কোনো ভাউচারের কথা জানে না।');

        /*
         * ⓘ `ledger_entries`-এ `voucher_id` বলে কোনো ঘর নেই — উৎসটা
         * `source_type` + `source_id` দিয়ে লেখা হয়, আর প্রতিটা
         * ভাউচার-ধরনের নিজস্ব নাম আছে ([[Voucher::SOURCE_TYPES]])।
         *
         * ⭐ নামটা নিজে লিখি না — ভাউচারটাকে জিজ্ঞেস করি।
         * ⚠️ হাতে লেখা নাম ভুল হলে সংগ্রহ খালি আসত, আর নিচের
         * দাবিটা মিথ্যা সবুজ হত।
         */
        $voucher = Voucher::query()->findOrFail($voucherId);

        $lines = LedgerEntry::query()
            ->where('source_type', Voucher::SOURCE_TYPES[$voucher->type])
            ->where('source_id', $voucherId)
            ->get();

        $this->assertGreaterThanOrEqual(2, $lines->count(), implode("\n", [
            'ভাউচারে দুইটার কম সারি — ঘোষণাটা কি সত্যিই খাতায় বসল?',
            '',
            'ⓘ খালি সংগ্রহে নিচের দাবিটা সবসময় সবুজ থাকত।',
        ]));

        $onGroups = Account::query()
            ->whereIn('id', $lines->pluck('account_id')->unique()->all())
            ->where('is_group', true)
            ->pluck('code')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([], $onGroups, implode("\n", [
            'এই দল-খাতে টাকা বসেছে: '.implode(', ', $onGroups),
            '',
            '⛔ সারিটা খতিয়ানে দেখা যাবে, অথচ কোনো যোগফলে আসবে না।',
        ]));
    }

    // ── সহায়ক ───────────────────────────────────────────────────────

    /**
     * ⚠️ ভাগ পাওয়ার মতো কেউ না থাকলে নিচের দাবিগুলো কিছুই মাপত না,
     * তাই আগে মূলধন আছে কি না নিশ্চিত করা হয়।
     *
     * @return list<ProfitShare>
     */

    private function distribute(string $profit): array
    {
        $positions = app(CapitalService::class)->positions($profit);

        $this->assertNotSame([], $positions, implode("\n", [
            'ডেমোতে একজনও মূলধনদাতা নেই — এই পরীক্ষাটা তাহলে কিছুই প্রমাণ করে না।',
        ]));

        $rows = app(ProfitDistribution::class)->declare([
            'trx_date' => now()->toDateString(),
            'profit' => $profit,
        ]);

        $this->assertNotSame([], $rows, 'একটাও ভাগ লেখা হয়নি।');

        return $rows;
    }

    /** খাতের মোট ডেবিট ও ক্রেডিট। */
    private function movement(string $code): array
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        return [
            'debit' => (string) (LedgerEntry::query()->where('account_id', $account->id)->sum('debit') ?: '0'),
            'credit' => (string) (LedgerEntry::query()->where('account_id', $account->id)->sum('credit') ?: '0'),
        ];
    }

    private function balance(string $code): string
    {
        $m = $this->movement($code);

        return bcsub($m['credit'], $m['debit'], 4);
    }
}
