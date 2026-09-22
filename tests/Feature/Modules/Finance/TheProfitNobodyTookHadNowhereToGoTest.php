<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\Finance\Services\ProfitDistribution;
use App\Modules\Finance\Services\WithdrawalService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * যে লাভ কেউ তুলল না, তার যাওয়ার জায়গা ছিল না।
 *
 * ── ⭐ মালিকের তৃতীয় ধাপ, ২২ সেপ্টেম্বর ২০২৬ ────────────────────────
 * *"র থাকলে বছর শেষে capital-এ যোগ হবে বা invest-এ"*।
 *
 * ── ⛔ ধাপটা না থাকলে যা হত ─────────────────────────────────────────
 * ঘোষিত লাভ প্রদেয় মুনাফায় (২১৯০) বসে থাকত **চিরকাল**। ⓘ খাতা বলত
 * ব্যবসা অংশীদারকে টাকা দিতে বাকি, অথচ তিনি ওটা ব্যবসাতেই রেখে
 * দিয়েছেন — অর্থাৎ ওটা এখন তাঁর **মূলধন**, দেনা নয়।
 *
 * ⚠️ আর দুইটা সংখ্যাই ভুল হত: দায় বেশি দেখাত, মালিকানা কম।
 *
 * ── ⭐ কেন `CapitalEntry` সারিও লেখা হয় ─────────────────────────────
 * [[CapitalService::positions()]] অংশ গোনে সারি ধরে, খতিয়ান ধরে নয়।
 * ⛔ কেবল দাখিলা বসালে মূলধনে যোগ হওয়া লাভ কারও **অংশ বাড়াত না**,
 * আর পরের বছরের ভাগ ভুল হত — টাকাটা ঢুকেছে, ওজনটা ঢোকেনি।
 */
final class TheProfitNobodyTookHadNowhereToGoTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Person $big;

    private Person $small;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->switchCompany((int) $company->id);
        $this->be($this->owner);

        // ⚠️ দুইজন, অসম অংশে — নাহলে "কার কত" প্রশ্নটাই ওঠে না
        $this->big = $this->contribute('YE-BIG', '700000');
        $this->small = $this->contribute('YE-SML', '300000');

        app(ProfitDistribution::class)->declare([
            'trx_date' => now()->subDays(3)->toDateString(),
            'profit' => '100000',
        ]);
    }

    /**
     * ⭐ যা তোলা হয়নি, তা মূলধনে যায় — দায় থেকে মালিকানায়।
     */
    public function test_what_was_never_taken_becomes_capital(): void
    {
        $payableBefore = $this->balance(StandardChart::PROFIT_PAYABLE);
        $capitalBefore = $this->balance(StandardChart::OWNER_CAPITAL);

        $this->assertSame(0, bccomp($payableBefore, '100000', 4),
            'ঘোষণার পরে প্রদেয় মুনাফার জের ১,০০,০০০ নয় — এসেছে '.$payableBefore.'।');

        $entries = app(ProfitDistribution::class)->capitalise([
            'trx_date' => now()->toDateString(),
            'entry_type' => CapitalEntry::CONTRIBUTION,
        ]);

        $this->assertCount(2, $entries, 'দুইজনেরই সারি বসার কথা।');

        $this->assertSame(0, bccomp($this->balance(StandardChart::PROFIT_PAYABLE), '0', 4), implode("\n", [
            'মূলধনে নেওয়ার পরেও প্রদেয় মুনাফার জের শূন্য হয়নি — আছে '
                .$this->balance(StandardChart::PROFIT_PAYABLE).'।',
            '',
            '⛔ খাতা তখনো বলবে ব্যবসা টাকাটা দিতে বাকি।',
        ]));

        $this->assertSame(0, bccomp($this->balance(StandardChart::OWNER_CAPITAL),
            bcadd($capitalBefore, '100000', 4), 4), implode("\n", [
                'মূলধন ১,০০,০০০ বাড়েনি।',
                '',
                '⛔ দায়টা কমল অথচ মালিকানা বাড়ল না — টাকাটা উবে গেল।',
            ]));
    }

    /**
     * ⭐ ভাগটা যাঁর যতটুকু বাকি, তাঁর ততটুকুই।
     *
     * ── ⚠️ কেন এই দাবিটা আলাদা ──────────────────────────────────────
     * উপরেরটা কেবল **মোট** মাপে। ⛔ পুরো ১,০০,০০০ একজনের নামে বসিয়ে
     * দিলেও ওটা সবুজ থাকত, আর তখন অংশীদারি ব্যবসায় সবচেয়ে বড়
     * ঝগড়াটাই শুরু হত।
     */
    public function test_each_person_gets_exactly_what_was_left_to_them(): void
    {
        // ছোট অংশীদার তাঁর পুরোটাই তুলে নিলেন
        $left = app(ProfitDistribution::class)->outstandingFor((int) $this->small->id);
        $this->take($this->small, $left);

        $rows = app(ProfitDistribution::class)->outstanding();

        $this->assertCount(1, $rows, implode("\n", [
            'যিনি সব তুলে নিয়েছেন তিনিও তালিকায় আছেন।',
            '',
            '⛔ তাহলে তাঁর নামে দ্বিতীয়বার মূলধন বসত।',
        ]));

        $this->assertSame((int) $this->big->id, $rows[0]['person_id'], 'ভুল মানুষ তালিকায়।');

        $entries = app(ProfitDistribution::class)->capitalise([
            'trx_date' => now()->toDateString(),
            'entry_type' => CapitalEntry::CONTRIBUTION,
        ]);

        $this->assertCount(1, $entries, 'একজনেরই সারি বসার কথা।');

        $this->assertSame(0, bccomp((string) $entries[0]->amount, '70000', 4),
            'বড় অংশীদারের ৭০,০০০ বসেনি — বসেছে '.$entries[0]->amount.'।');

        /*
         * ⭐ খতিয়ানের লাইনেও পক্ষটা বসেছে তো? ⓘ না বসলে "কার মূলধন
         * বাড়ল" প্রশ্নের উত্তর কেবল সারিতে থাকত, খাতায় নয় — আর
         * দুইটা মিলিয়ে দেখার উপায় থাকত না।
         */
        $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();

        $named = LedgerEntry::query()
            ->where('account_id', $capital->id)
            ->where('party_type', 'person')
            ->where('party_id', $this->big->id)
            ->sum('credit');

        $this->assertSame(0, bccomp((string) $named, '70000', 4),
            'খতিয়ানের ক্রেডিট লাইনে বড় অংশীদারের নাম নেই।');
    }

    /**
     * ⛔ একই টাকা দুইবার নেওয়া যায় না।
     *
     * ── ⚠️ এটাই এই ধাপের সবচেয়ে বিপজ্জনক ফাঁক ───────────────────────
     * মূলধনে নেওয়া অঙ্কটা ঘোষণাও নয়, তোলাও নয়। ⓘ `outstandingFor()`
     * ওটা বাদ না দিলে বোতামটা দ্বিতীয়বার চাপলেই আবার পুরোটা বসত —
     * মালিকানা দ্বিগুণ, আর ২১৯০-এর জের ঋণাত্মক।
     */
    public function test_the_same_money_cannot_be_capitalised_twice(): void
    {
        app(ProfitDistribution::class)->capitalise([
            'trx_date' => now()->toDateString(),
            'entry_type' => CapitalEntry::CONTRIBUTION,
        ]);

        $this->assertSame([], app(ProfitDistribution::class)->outstanding(),
            'মূলধনে নেওয়ার পরেও কারও নামে পাওনা দেখাচ্ছে।');

        try {
            app(ProfitDistribution::class)->capitalise([
                'trx_date' => now()->toDateString(),
                'entry_type' => CapitalEntry::CONTRIBUTION,
            ]);

            $this->fail('দ্বিতীয়বারও মূলধনে নেওয়া গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('trx_date', $e->errors());
        }

        $this->assertSame(0, bccomp($this->balance(StandardChart::PROFIT_PAYABLE), '0', 4),
            'দ্বিতীয় চেষ্টার পরে প্রদেয় মুনাফার জের ঋণাত্মক।');
    }

    /**
     * ⭐ মূলধনে যাওয়া লাভ পরের বছরের অংশও বাড়ায়।
     *
     * ── ⚠️ কেন এটা না মাপলে ধাপটা অর্ধেক ────────────────────────────
     * ⓘ [[CapitalService::positions()]] অংশ গোনে [[CapitalEntry]] সারি
     * ধরে। ⛔ কেবল দাখিলা বসালে টাকাটা মূলধন খাতে যেত, অথচ **কারও
     * অংশ বাড়ত না** — আর পরের বছরের ভাগ পুরনো অনুপাতেই হত।
     */
    public function test_capitalised_profit_counts_towards_the_share(): void
    {
        $before = $this->contributed((int) $this->small->id);

        app(ProfitDistribution::class)->capitalise([
            'trx_date' => now()->toDateString(),
            'entry_type' => CapitalEntry::CONTRIBUTION,
        ]);

        $after = $this->contributed((int) $this->small->id);

        // ছোট অংশীদারের ভাগ ছিল ৩০% × ১,০০,০০০ = ৩০,০০০
        $this->assertSame(0, bccomp(bcsub($after, $before, 4), '30000', 4), implode("\n", [
            'ছোট অংশীদারের মূলধন ৩০,০০০ বাড়েনি — বেড়েছে '.bcsub($after, $before, 4).'।',
            '',
            '⛔ টাকাটা খাতায় ঢুকল, ওজনটা ঢুকল না — পরের বছরের ভাগ ভুল হবে।',
        ]));
    }

    // ── সহায়ক ───────────────────────────────────────────────────────

    private function contributed(int $personId): string
    {
        foreach (app(CapitalService::class)->positions(null) as $position) {
            if ($position['person_id'] === $personId) {
                return (string) $position['contributed'];
            }
        }

        return '0';
    }

    private function take(Person $person, string $amount): void
    {
        $withdrawal = app(WithdrawalService::class)->request([
            'person_id' => $person->id,
            'kind' => Withdrawal::PROFIT_SHARE,
            'in_kind' => 'cash',
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
            'reason' => 'test',
        ]);

        app(WithdrawalService::class)->post(
            $withdrawal->fresh(),
            // ⛔ `1101` একটা মাথা, পোস্টযোগ্য খাত নয়
            Account::query()->money()->where('is_group', false)->firstOrFail(),
        );
    }

    private function contribute(string $code, string $amount): Person
    {
        $person = Person::query()->create([
            'code' => $code,
            'name_en' => $code,
            'name_bn' => $code,
            'is_active' => true,
        ]);

        CapitalEntry::query()->create([
            'branch_id' => Branch::query()->firstOrFail()->id,
            'document_no' => 'CAP-'.$code,
            'person_id' => $person->id,
            'contributor_type' => CapitalEntry::PARTNER,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'in_kind' => CapitalEntry::CASH,
            'trx_date' => now()->subMonth()->toDateString(),
            'amount' => $amount,
            'status' => CapitalEntry::POSTED,
        ]);

        return $person;
    }

    private function balance(string $code): string
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        $debit = (string) (LedgerEntry::query()->where('account_id', $account->id)->sum('debit') ?: '0');
        $credit = (string) (LedgerEntry::query()->where('account_id', $account->id)->sum('credit') ?: '0');

        return bcsub($credit, $debit, 4);
    }
}
