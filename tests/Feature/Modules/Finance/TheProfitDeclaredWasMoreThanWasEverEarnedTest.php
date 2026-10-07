<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Posting\PostingEngine;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Services\ProfitDistribution;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ChecksTheFiveMatches;
use Tests\TestCase;

/**
 * ঘোষিত লাভ খাতায় যা আছে তার চেয়ে বেশি হতে পারত।
 *
 * ── ⛔ কী ঘটত, নিরীক্ষা §২ ────────────────────────────────────────────
 * [[ProfitDistribution::declare()]] কেবল দেখত অঙ্কটা **ধনাত্মক** কি না।
 * ⚠️ তাই এক টাকাও লাভ না করে দশ লাখ ঘোষণা করা যেত।
 *
 * ⓘ আর দাখিলাটা নিজে মিলত: সঞ্চিত মুনাফা ডেবিট, অংশীদারের দেনা
 * ক্রেডিট। ⛔ খাতা ভারসাম্যে, প্রতিটা পরীক্ষা সবুজ — কেবল সঞ্চিত
 * মুনাফার খাতটা **ডেবিট ব্যালান্সে** নেমে যেত, যার মানে *"যা অর্জিত
 * হয়নি তা ভাগ করা হয়েছে"*।
 *
 * ⚠️ পরিণামটা কাগজের নয়, টাকার: ঘোষণা মানে অংশীদারের নামে **দেনা**।
 * ⛔ তিনি তুলতে এলে টাকাটা থাকে না, আর ততদিনে খাতায় লেখা হয়ে গেছে যে
 * তিনি পাওনাদার।
 *
 * ── ⭐ *"দুইবার নয়"* আলাদা কোনো নিয়ম নয় ─────────────────────────────
 * ⓘ ঘোষণার পর সঞ্চিত মুনাফা কমে যায়। ⭐ তাই একই অঙ্ক দ্বিতীয়বার
 * ঘোষণা করতে গেলে **এই সিলিংটাই** থামায় — নিচের দাবিটা ঠিক সেটাই মাপে।
 *
 * ⚠️ আর এটা *"বছরে একটাই ঘোষণা"* বলে **না**, বলা উচিতও নয় — বছরে
 * দুইবার লাভ বাঁটা ব্যবসায় স্বাভাবিক। ⓘ পাহারাটা টাকার সীমা ধরে,
 * বচনের সংখ্যা ধরে নয়।
 */
final class TheProfitDeclaredWasMoreThanWasEverEarnedTest extends TestCase
{
    use ChecksTheFiveMatches;
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
         * ⓘ ডেমোতে একজনও মূলধনদাতা নেই, তাই দুইজন বসানো হয় —
         * নাহলে `preview()` খালি তালিকা দিত আর ঘোষণা অন্য কারণে
         * থামত, এই পাহারাটার কারণে নয়।
         */
        $this->contribute('CEIL-A', 'Partner A', '600000');
        $this->contribute('CEIL-B', 'Partner B', '400000');

        /* ⭐ আর খাতায় সত্যিকারের লাভ — সিলিংটার কিছু ধরার থাকতে হবে */
        $this->earn('100000.0000');
    }

    // ── ⭐ আসল দাবি: বিপজ্জনক অঙ্কটা খাওয়ানো হয়, আর সে নেয় না ────────

    public function test_profit_that_was_never_earned_cannot_be_declared(): void
    {
        $available = $this->retained();

        /*
         * ⚠️ পাহারাটাকে **ঠিক সেই ইনপুটটাই** খাওয়ানো হয় যেটা সে ঠেকাতে
         * বসানো — যা আছে তার চেয়ে এক টাকা বেশি। ⓘ কম দিয়ে পরীক্ষা
         * করলে দাবিটা কখনো পাহারাটা ছুঁতই না।
         */
        $tooMuch = bcadd($available, '1.0000', 4);

        try {
            app(ProfitDistribution::class)->declare([
                'profit' => $tooMuch,
                'trx_date' => now()->toDateString(),
            ]);

            $this->fail('খাতায় যা নেই তাও ঘোষণা হয়ে গেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('profit', $e->errors());
        }

        /* ⭐ আর থামার মানে **কিছুই লেখা হয়নি** — অর্ধেক কাজ নয় */
        $this->assertSame($available, $this->retained(),
            'ঘোষণা থেমেছে, তবু সঞ্চিত মুনাফার খাত নড়েছে।');
    }

    public function test_declaring_exactly_what_is_there_is_allowed(): void
    {
        /*
         * ⛔ পাল্টা-দাবি, আর এটা ছাড়া সারাইটা "সবকিছু আটকাও" হতে পারত।
         * ⓘ সীমানার ঠিক উপরের অঙ্কটাই সবচেয়ে সহজে ভুল হয় — `>` না `>=`।
         */
        $available = $this->retained();

        $this->assertSame(1, bccomp($available, '0', 4),
            'খাতায় সঞ্চিত মুনাফাই নেই — তাহলে এই দাবিটা কিছুই মাপছে না।');

        app(ProfitDistribution::class)->declare([
            'profit' => $available,
            'trx_date' => now()->toDateString(),
        ]);

        $this->assertSame(0, bccomp($this->retained(), '0', 4),
            'পুরোটা ঘোষণার পরেও সঞ্চিত মুনাফা শূন্যে নামেনি।');
    }

    public function test_the_same_profit_cannot_be_declared_a_second_time(): void
    {
        /*
         * ⭐ *"দুইবার নয়"* — আর কোনো নতুন নিয়ম ছাড়াই। ⓘ প্রথম ঘোষণায়
         * সঞ্চিত মুনাফা শূন্যে নামে, তাই দ্বিতীয়বার একই অঙ্ক সিলিং
         * পেরিয়ে যায়।
         */
        $available = $this->retained();

        app(ProfitDistribution::class)->declare([
            'profit' => $available,
            'trx_date' => now()->toDateString(),
        ]);

        $this->expectException(ValidationException::class);

        app(ProfitDistribution::class)->declare([
            'profit' => $available,
            'trx_date' => now()->toDateString(),
        ]);
    }

    public function test_the_books_still_balance_after_a_lawful_declaration(): void
    {
        /* ⭐ পাঁচ মিলের প্রথমটা — ডেবিট = ক্রেডিট */
        app(ProfitDistribution::class)->declare([
            'profit' => $this->retained(),
            'trx_date' => now()->toDateString(),
        ]);

        $this->assertTheFiveMatches([]);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * সঞ্চিত মুনাফা — খাতের **স্বাভাবিক দিকে**, অর্থাৎ থাকলে ধনাত্মক।
     *
     * ⓘ [[Account::balanceOn()]] ক্রেডিট প্রকৃতির খাতে `credit − debit`
     * ফেরায়। ⚠️ চিহ্নটা উল্টো ধরলে এই ফাইলের প্রতিটা দাবি ভুল দিকে
     * মাপত, আর তিনটাই সবুজ থাকত।
     */
    private function retained(): string
    {
        return StandardChart::find(StandardChart::RETAINED_EARNINGS)?->balanceOn() ?? '0';
    }

    /**
     * মূলধনদাতা বসানো — [[TheProfitWasSharedAndNobodyCouldSayWhoWasOwedWhatTest]]-এর একই উপায়।
     *
     * ⓘ সারিটা সরাসরি বসানো হয়, কারণ এখানে মাপার বিষয় মূলধন নয়,
     * লাভের সিলিং — মূলধনটা কেবল অনুপাতটা তৈরি করে।
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
     * ⭐ খাতায় সত্যিকারের সঞ্চিত মুনাফা বসানো — পোস্টিং ইঞ্জিন দিয়েই।
     *
     * ⛔ হাতে সারি বসানো হয় না: তাতে খাতা ভারসাম্য হারাত আর পাঁচ
     * মিলের দাবিটা এই ফাইলের নিজের ফিকশ্চারেই লাল হত।
     *
     * ⓘ আয়ের খাত ক্রেডিট, নগদ ডেবিট — তারপর আয় সঞ্চিত মুনাফায়
     * নিয়ে যাওয়ার বদলে সরাসরি সঞ্চিত মুনাফাকেই ক্রেডিট করা হয়:
     * বছর বন্ধের পরে খাতাটা ঠিক এই অবস্থায় থাকে।
     */
    private function earn(string $amount): void
    {
        $retained = StandardChart::find(StandardChart::RETAINED_EARNINGS);
        $cash = Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();

        $this->assertNotNull($retained, 'চার্টে সঞ্চিত মুনাফার খাতটাই নেই।');

        app(PostingEngine::class)->post(
            sourceType: 'test:earned',
            sourceId: 1,
            trxDate: now()->subDays(2)->toDateString(),
            lines: [
                ['account_id' => $cash->id, 'debit' => $amount, 'credit' => '0'],
                ['account_id' => $retained->id, 'debit' => '0', 'credit' => $amount],
            ],
        );
    }
}
