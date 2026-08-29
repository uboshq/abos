<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * খোলার জের — এক সংখ্যা, এক উৎস।
 *
 * ── কী ভাঙা ছিল, ২৯ আগস্ট ২০২৬ ───────────────────────────────────────
 * HP-র রিপোর্ট, হুবহু: হিসাবের ছকে নতুন ব্যাংক খাত খুলে জের ৫০,০০০
 * দিলে খাতের পাতা ৮০,০০০ দেখাত, আর ট্রায়াল ব্যালেন্স ও স্থিতিপত্র
 * ৩০,০০০। দুইটা পর্দা, দুইটা উত্তর, একই খাত।
 *
 * কারণ: জেরটা `accounts.opening_balance` কলামে বসত আর
 * `Account::balanceOn()` সেটা **কোডে** যোগ করত; রিপোর্ট সরাসরি
 * `ledger_entries` পড়ে, তাই তারা কিছুই জানত না।
 *
 * ── কেন কোনো পরীক্ষা এটা ধরেনি ───────────────────────────────────────
 * প্রতিটা পর্দার নিজের পরীক্ষা ছিল, আর প্রতিটাই **নিজের হিসাবের সাথে
 * নিজেকে** মেলাত। দুইটা ভুল একসাথে সত্যি হতে পারে যতক্ষণ না কেউ
 * দুইটাকে পাশাপাশি বসায়।
 *
 * এমনকি আটটা integrity check-ও চুপ ছিল, কারণ ওগুলো খতিয়ানের ভেতরের
 * ডেবিট=ক্রেডিট মিল দেখে — আর যে টাকাটা খতিয়ানে ঢোকেইনি সেটা কোনো
 * অমিল তৈরি করে না। **সবুজ পরীক্ষা যা কিছুই দেখে না।**
 *
 * তাই এই ফাইলটার প্রতিটা পরীক্ষা **দুইটা আলাদা পথের উত্তর মেলায়**,
 * কোনো একটার ভেতরের সঙ্গতি নয়।
 */
class OneNumberOneSourceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
    }

    private function openAnAccount(string $opening): Account
    {
        $parent = Account::query()->where('code', StandardChart::BANK_AND_MFS)->firstOrFail();

        return app(AccountService::class)->create([
            'code' => '1102-TEST',
            'name_en' => 'Test Bank Account',
            'name_bn' => 'পরীক্ষার ব্যাংক হিসাব',
            'parent_id' => $parent->id,
            'is_bank' => true,
            'opening_balance' => $opening,
            'opening_date' => now()->toDateString(),
        ]);
    }

    /** খতিয়ান যা বলে — রিপোর্টগুলো ঠিক এই পথেই যায়। */
    private function fromTheLedger(Account $account): string
    {
        $row = LedgerEntry::query()
            ->where('company_id', $this->company->id)
            ->where('account_id', $account->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        return bcsub((string) $row->d, (string) $row->c, 4);
    }

    /**
     * খাতের পাতা যা বলে আর খতিয়ান যা বলে — এক।
     *
     * এটাই HP-র অভিযোগটার সরাসরি উত্তর।
     */
    public function test_the_account_page_and_the_ledger_say_the_same_thing(): void
    {
        $account = $this->openAnAccount('50000');

        $this->assertSame(0, bccomp($account->balanceOn(), '50000', 4),
            'খাতের পাতা জেরটা দেখাচ্ছে না।');

        $this->assertSame(0, bccomp($this->fromTheLedger($account), '50000', 4),
            'জেরটা খতিয়ানে বসেনি — ট্রায়াল ব্যালেন্স আর স্থিতিপত্র এটা দেখতেই পাবে না।');

        $this->assertSame(0, bccomp($account->balanceOn(), $this->fromTheLedger($account), 4),
            'দুইটা পথ দুইটা উত্তর দিচ্ছে — ঠিক সেই বাগটাই ফিরে এসেছে।');
    }

    /**
     * জেরটা লেনদেনের তালিকায় একটা সারি হিসেবে দেখা যায়।
     *
     * HP-র কথা: "Transactions তালিকায় Opening balance-এর কোনো লাইন নেই,
     * আর running balance সরাসরি লাফ দেয়"। একটা সংখ্যা যেখান থেকে এল
     * সেটা দেখা না গেলে সেটা যাচাই করার উপায় থাকে না (নিয়ম ১)।
     */
    public function test_the_opening_shows_as_a_line_you_can_open(): void
    {
        $account = $this->openAnAccount('50000');

        $entry = LedgerEntry::query()
            ->where('account_id', $account->id)
            ->where('source_type', OpeningBalanceService::ACCOUNT_SOURCE
                .OpeningBalanceService::SOURCE_SUFFIX)
            ->first();

        $this->assertNotNull($entry, 'জেরের কোনো সারি খতিয়ানে নেই।');
        $this->assertSame(0, bccomp((string) $entry->debit, '50000', 4));
    }

    /**
     * বিপরীত দিকটা খোলার জের খাতে যায় — আর খাতা মেলে।
     *
     * একটা খাতের জের একা বসতে পারে না। বসলে ডেবিট আর ক্রেডিটের যোগফল
     * আলাদা হয়ে যেত, আর সেটাই একমাত্র ভুল যা পুরো ব্যবস্থাকে অর্থহীন
     * করে দেয়।
     */
    public function test_the_books_still_balance(): void
    {
        /*
         * আগে-পরে মেপে দেখা, মোট নয়।
         *
         * সঞ্চিত মুনাফায় ইতিমধ্যেই সারি আছে — সিডারের গ্রাহক,
         * সরবরাহকারী ও খোলা মজুদের জেরগুলো একই খাতে যায়। মোট ধরে
         * তুলনা করলে পরীক্ষাটা সিডারের ডেটার সাথে বাঁধা পড়ত, আর
         * সিডার বদলালেই লাল হত — অথচ কোডে কিছুই ভাঙেনি।
         */
        $equity = Account::query()
            ->where('code', StandardChart::RETAINED_EARNINGS)->firstOrFail();

        $before = $this->fromTheLedger($equity);

        $this->openAnAccount('50000');

        $row = LedgerEntry::query()
            ->where('company_id', $this->company->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        $this->assertSame(0, bccomp((string) $row->d, (string) $row->c, 4),
            'জের বসানোর পর ডেবিট আর ক্রেডিট আর সমান নেই।');

        /*
         * বিপরীত দিকটা সঞ্চিত মুনাফায় — গ্রাহক, সরবরাহকারী ও খোলা
         * মজুদ যেখানে যায়, ঠিক সেখানেই।
         *
         * প্রথমে একটা নতুন "খোলার জের" খাত বানাতে গিয়েছিলাম। ওটা ভুল
         * হত: একই ঘটনা দুই ইকুইটি খাতে ভাগ হয়ে যেত, আর "পুরনো খাতা
         * থেকে মোট কত এল" প্রশ্নের উত্তর দিতে দুইটা যোগ করতে হত।
         */
        $this->assertSame(0, bccomp(
            bcsub($this->fromTheLedger($equity), $before, 4), '-50000', 4),
            'বিপরীত দিকটা সঞ্চিত মুনাফায় বসেনি।');
    }

    /**
     * দুইবার চালালে টাকাটা দুইবার বসে না।
     *
     * পুরনো সারি সারানোর কমান্ডটা ডিপ্লয়ে প্রতিবার চলে
     * ([[PostMissingOpenings]])। পাহারা না থাকলে প্রতিটা ডিপ্লয়ে
     * জেরটা আবার বসত — আর ভুলটা ধরা কঠিন: খাতা মেলে, কেবল সংখ্যা বাড়ে।
     */
    public function test_running_it_again_adds_nothing(): void
    {
        $account = $this->openAnAccount('50000');

        app(OpeningBalanceService::class)->forAccount($account);
        app(OpeningBalanceService::class)->forAccount($account->fresh());

        $this->assertSame(0, bccomp($this->fromTheLedger($account), '50000', 4),
            'জেরটা একাধিকবার বসেছে।');
    }

    /**
     * শূন্য জেরে কোনো দাখিলা হয় না।
     *
     * বেশিরভাগ খাতেই জের থাকে না, আর প্রতিটার জন্য শূন্যের একটা
     * ভাউচার বসালে খতিয়ানে শত শত অর্থহীন সারি জমত।
     */
    public function test_no_opening_no_entry(): void
    {
        $parent = Account::query()->where('code', StandardChart::BANK_AND_MFS)->firstOrFail();

        $account = app(AccountService::class)->create([
            'code' => '1102-ZERO',
            'name_en' => 'No Opening Bank',
            'parent_id' => $parent->id,
            'is_bank' => true,
            'opening_balance' => 0,
        ]);

        $this->assertSame(0, LedgerEntry::query()->where('account_id', $account->id)->count(),
            'জের শূন্য, তবু দাখিলা বসেছে।');
    }

    /**
     * দায়ের খাতে জেরটা ক্রেডিট দিকে বসে।
     *
     * কলামে সংখ্যাটা সবসময় ধনাত্মক, স্বাভাবিক দিকে। চিহ্নটা ভুল হলে
     * একটা দায় সম্পদ হয়ে দেখাত, আর স্থিতিপত্র ঠিক দ্বিগুণ পরিমাণে
     * ভুল হত।
     */
    public function test_a_liability_opens_on_the_credit_side(): void
    {
        $parent = Account::query()->where('code', '2100')->first()
            ?? Account::query()->where('type', Account::LIABILITY)->where('is_group', true)->firstOrFail();

        $account = app(AccountService::class)->create([
            'code' => '2100-TEST',
            'name_en' => 'Test Payable',
            'parent_id' => $parent->id,
            'opening_balance' => '20000',
            'opening_date' => now()->toDateString(),
        ]);

        $this->assertSame(0, bccomp($this->fromTheLedger($account), '-20000', 4),
            'দায়ের জেরটা ক্রেডিট দিকে বসেনি।');

        $this->assertSame(0, bccomp($account->balanceOn(), '20000', 4),
            'দায়ের খাতে ব্যালেন্স স্বাভাবিক দিকে ধনাত্মক আসেনি।');
    }
}
