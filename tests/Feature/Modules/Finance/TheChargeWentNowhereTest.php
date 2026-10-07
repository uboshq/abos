<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ব্যাংক আর বিকাশ চার্জ কাটত, আর সেটা কোথাও লেখা হত না।
 *
 * ── মালিকের প্রশ্ন, ১৩ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"ব্যাংক চার্জ আছে, MFS চার্জ আছে — এগুলো কোথায়?"*
 *
 * ⛔ কোথাও না। খাত দুইটা প্রমিত ছকে **আগে থেকেই বসানো** (`5210` ব্যাংক
 * চার্জ, `5211` মোবাইল ব্যাংকিং চার্জ), আর ছকের মন্তব্যে কারণও লেখা:
 * *"বিকাশ ক্যাশ-আউটে চার্জ কাটে, ব্যাংক কাটে না… ওই চার্জটা আলাদা খাতে
 * না গেলে বছরে কত গেল কেউ জানে না।"*
 *
 * ⚠️ খাত বানানো হয়েছিল, কারণ লেখা হয়েছিল, আর **কোনো পর্দা কোনোদিন
 * ওগুলোয় একটা টাকাও বসায়নি।** আজকের চেনা রোগ: নিয়ম লেখা, অথচ
 * অপৌঁছানো।
 *
 * ── ⭐ মালিকের সিদ্ধান্ত: কোনটা মূলধন ─────────────────────────────────
 * যা **পাঠানো হলো**, যা ঢুকল তা নয়। মালিকের অংশ তিনি যতটা দিয়েছেন
 * ততটাই; চার্জটা ব্যবসার খরচ, তাঁর অনুদানের ঘাটতি নয়।
 *
 * ⓘ উল্টোটা করলে অংশীদারি ব্যবসায় কার কত অংশ — সেই সংখ্যাটা **চার্জের
 * হারের সাথে নড়ত**, আর ওই সংখ্যাটা নিয়েই ঝগড়া হয়।
 */
class TheChargeWentNowhereTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);
    }

    private function service(): CapitalService
    {
        return app(CapitalService::class);
    }

    private function moneyAccount(string $motherCode, string $name): Account
    {
        $mother = Account::query()->where('code', $motherCode)->firstOrFail();

        return app(AccountService::class)->create([
            'name_en' => $name,
            'parent_id' => $mother->id,
        ]);
    }

    private function draft(string $amount = '8000.00'): CapitalEntry
    {
        $who = Person::query()->first() ?? Person::query()->create([
            'company_id' => $this->company->id,
            'code' => 'P0001',
            'name_en' => 'Owner One',
            'is_active' => true,
        ]);

        return $this->service()->record([
            'person_id' => $who->id,
            'contributor_type' => CapitalEntry::OWNER,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
        ]);
    }

    /** @return array<string, string> খাতের কোড => নিট ডেবিট */
    private function ledgerOf(CapitalEntry $entry): array
    {
        $rows = [];

        foreach (LedgerEntry::query()->where('source_id', $entry->voucher_id)->get() as $line) {
            $code = Account::query()->whereKey($line->account_id)->value('code');
            $rows[$code] = bcsub((string) $line->debit, (string) $line->credit, 4);
        }

        return $rows;
    }

    /**
     * ⭐ ব্যাংকের চার্জ `5210`-এ যায়, আর মূলধন পুরোটাই থাকে।
     *
     * ⓘ তিনটা সংখ্যাই আলাদা করে দেখা হয়, কারণ **যোগফল মিলে গেলেও
     * ভাগটা ভুল হতে পারত** — ৭,৯৮০ + ২০ = ৮,০০০ মিলবে চার্জটা ভুল
     * খাতে গেলেও।
     */
    public function test_a_bank_charge_lands_in_the_bank_charge_head(): void
    {
        $entry = $this->draft('8000.00');
        $bank = $this->moneyAccount(StandardChart::BANK, 'Islami Bank Current');

        $this->service()->post($entry, $bank, 'TRX-778899', '20.00');

        $ledger = $this->ledgerOf($entry->fresh());

        $this->assertSame('7980.0000', $ledger[$bank->code] ?? null,
            'খাতে যা ঢুকল তা মোট বিয়োগ চার্জ হওয়ার কথা।');
        $this->assertSame('20.0000', $ledger[StandardChart::BANK_CHARGES] ?? null,
            'চার্জটা ব্যাংক চার্জের খাতে যায়নি।');
        $this->assertSame('-8000.0000', $ledger[StandardChart::OWNER_CAPITAL] ?? null,
            'মূলধন পুরো ৮,০০০ থাকার কথা — মালিক ততটাই দিয়েছেন।');
    }

    /**
     * ⛔ বিকাশের চার্জ `5211`-এ, `5210`-এ নয়।
     *
     * ⚠️ এই পার্থক্যটাই ছকে দুইটা আলাদা খাত রাখার একমাত্র কারণ। এক
     * খাতে মিশে গেলে "বিকাশে বছরে কত চার্জ গেল" প্রশ্নের উত্তর আর
     * বের করা যেত না — আর ওটাই প্রশ্নটা, কারণ ব্যাংক চার্জ কাটেই না।
     */
    public function test_a_wallet_charge_lands_in_its_own_head_not_the_bank_one(): void
    {
        $entry = $this->draft('5000.00');
        $bkash = $this->moneyAccount(StandardChart::MOBILE_MONEY, 'bKash Merchant');

        $this->service()->post($entry, $bkash, 'BKH-112233', '92.50');

        $ledger = $this->ledgerOf($entry->fresh());

        $this->assertSame('92.5000', $ledger[StandardChart::MFS_CHARGES] ?? null);
        $this->assertArrayNotHasKey(StandardChart::BANK_CHARGES, $ledger,
            'বিকাশের চার্জ ব্যাংক চার্জের খাতে গেছে — দুইটা আলাদা রাখার মানেই থাকল না।');
        $this->assertSame('4907.5000', $ledger[$bkash->code] ?? null);
    }

    /**
     * চার্জ না থাকলে দুই লাইনই — তৃতীয় একটা শূন্য লাইন নয়।
     *
     * ⓘ শূন্যের লাইন খাতায় বসলে প্রতিটা রিপোর্টে চার্জের খাতটা দেখা
     * যেত, অথচ সেখানে কিছুই নেই। ⭐ আর এটাই দাবিটার অন্য অর্ধেক:
     * উপরের দুইটা মাপে চার্জ **বসেছে** কি না, এটা মাপে চার্জ ছাড়া
     * পথটা এখনো অটুট কি না।
     */
    public function test_no_charge_means_no_third_line(): void
    {
        $entry = $this->draft('3000.00');
        $bank = $this->moneyAccount(StandardChart::BANK, 'City Bank Current');

        $this->service()->post($entry, $bank, 'TRX-000111');

        $ledger = $this->ledgerOf($entry->fresh());

        $this->assertCount(2, $ledger);
        $this->assertSame('3000.0000', $ledger[$bank->code] ?? null);
        $this->assertArrayNotHasKey(StandardChart::BANK_CHARGES, $ledger);
    }

    /**
     * ⛔ চার্জ মোটের সমান বা বেশি হলে থামে।
     *
     * ⓘ সমানও নয়: তাহলে খাতে শূন্য ঢুকত, আর "টাকা এসেছে" বলাটাই মিথ্যা
     * হত। ⚠️ সংখ্যাটা হাতে লেখা, তাই একটা বাড়তি শূন্যই যথেষ্ট — আর
     * তখন ভুলটা নীরব হত: ভাউচার মিলে যেত (ডেবিট = ক্রেডিট), কেবল
     * টাকাটা কোথাও থাকত না।
     */
    public function test_a_charge_cannot_swallow_the_whole_contribution(): void
    {
        $entry = $this->draft('500.00');
        $bank = $this->moneyAccount(StandardChart::BANK, 'Tiny Bank');

        $this->expectException(ValidationException::class);

        $this->service()->post($entry, $bank, 'TRX-999', '500.00');
    }
}
