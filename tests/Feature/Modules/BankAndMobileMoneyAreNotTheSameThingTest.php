<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * ব্যাংক আর মোবাইল ব্যাংকিং এক জিনিস নয়।
 *
 * ── মালিকের প্রশ্ন, ৩০ আগস্ট ২০২৬ ────────────────────────────────────
 * *"Cash in Hand, Bank, MFS — ei tinti mother ac … bank & MFS eksathe
 * keno? hisab milate somossaw hobe karon mfs e charge kate"*
 *
 * ছকে দুইটা এক মাথায় ছিল (১১০২ "ব্যাংক ও মোবাইল ব্যাংকিং"), আর ঘোষণার
 * উপরে লেখা যুক্তি ছিল "MFS হিসাবের দিক থেকে ব্যাংকের মতোই আচরণ করে"।
 * ওটা টেকে না: বিকাশ ক্যাশ-আউটে চার্জ কাটে, মিলকরণের কাগজ আলাদা,
 * সেটেলমেন্টের সময়ও আলাদা। এক মাথায় থাকলে "ব্যাংকে কত আছে" সংখ্যাটাই
 * মিথ্যা বলত।
 *
 * ── এই ফাইলটা আসলে কী পাহারা দেয় ────────────────────────────────────
 * ভাগটা নিজে সহজ। **বিপজ্জনক অংশটা হলো ছাঁকনিগুলো**: নয় জায়গায়
 * `[নগদ, ব্যাংক]` জোড়াটা হাতে লেখা ছিল — আদায়, পরিশোধ, মূলধন, জমা,
 * ঋণ, সরাসরি ক্রয়। তৃতীয় কোডটা একটাতে বসাতে ভুললে ওই পর্দায় বিকাশের
 * হিসাবগুলো **নীরবে হারিয়ে যেত**, আর কেউ টের পেত কেবল টাকা তুলতে গিয়ে।
 *
 * তাই পরীক্ষাটা প্রতিটা পর্দা খুলে দেখে MFS হিসাবটা তালিকায় আছে কি না।
 */
class BankAndMobileMoneyAreNotTheSameThingTest extends TestCase
{
    use RefreshDatabase;

    private Account $mfsAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->mfsAccount = app(AccountService::class)->create([
            'code' => '1105-BK',
            'name_en' => 'bKash Merchant',
            'name_bn' => 'বিকাশ মার্চেন্ট',
            'parent_id' => Account::query()
                ->where('code', StandardChart::MOBILE_MONEY)->value('id'),
            'is_bank' => true,
        ]);
    }

    /**
     * তিনটা মাথা, তিনটাই আলাদা।
     */
    public function test_there_are_three_money_heads_not_two(): void
    {
        foreach ([StandardChart::CASH_IN_HAND, StandardChart::BANK,
            StandardChart::MOBILE_MONEY] as $code) {
            $this->assertNotNull(StandardChart::find($code),
                "টাকার মাথা {$code} ছকে নেই।");
        }

        $bank = StandardChart::find(StandardChart::BANK);

        $this->assertSame('Bank', $bank->name_en,
            '১১০২ এখনো MFS-এর নাম বয়ে বেড়াচ্ছে।');
    }

    /**
     * MFS-এর চার্জের নিজের খাত আছে।
     *
     * মালিকের যুক্তির মূল কথাটাই এই চার্জ। ব্যাংক চার্জের সাথে মিশিয়ে
     * রাখলে "MFS-এ বছরে কত গেল" প্রশ্নের উত্তর থাকত না — অথচ ওই
     * উত্তরটা দেখেই ঠিক হয় কোন পথে টাকা তোলা সস্তা।
     */
    public function test_mfs_charges_have_their_own_head(): void
    {
        $this->assertNotNull(Account::query()->where('code', '5211')->first(),
            'MFS চার্জের কোনো আলাদা খাত নেই।');

        $this->assertNotSame(
            Account::query()->where('code', '5210')->value('id'),
            Account::query()->where('code', '5211')->value('id'),
        );
    }

    /**
     * টাকার প্রতিটা পর্দায় MFS হিসাবটা বাছা যায়।
     *
     * ── কেন প্রতিটা আলাদা করে ───────────────────────────────────────
     * ছাঁকনিটা নয় জায়গায় হাতে লেখা ছিল। একটাতে তৃতীয় কোডটা বসাতে
     * ভুললে ওই পর্দাটা নীরবে বিকাশ ভুলে যেত — কোনো ভুল নয়, কোনো ৫০০
     * নয়, কেবল ড্রপডাউনে একটা নাম কম।
     */
    public function test_every_money_screen_offers_the_mfs_account(): void
    {
        $screens = [
            'আদায়' => route('sales.collection.create'),
            'মূলধন' => route('finance.capital.index'),
            'ব্যাংক আমানত' => route('finance.deposit.index', ['issuer' => 'bank']),
        ];

        $missing = [];

        foreach ($screens as $name => $url) {
            /*
             * পর্দার নিজের তালিকাটা পড়া হয়, HTML নয়।
             *
             * প্রথমে HTML-এ নাম খোঁজা হয়েছিল আর মূলধনের পর্দা লাল হলো —
             * ওখানে খাতের ড্রপডাউনটা কেবল খসড়া সারির পাশে আঁকা হয়, আর
             * খসড়া না থাকলে HTML-এ কিছুই থাকে না। ছাঁকনিটা ঠিকই ছিল।
             *
             * তালিকাটাই আসল প্রশ্ন: পর্দা কী কী বাছতে দেয়। কোন সারি
             * আজ আছে কি নেই, সেটা আলাদা কথা।
             */
            $offered = $this->get($url)->assertOk()->viewData('accounts');

            if (! collect($offered)->contains('id', $this->mfsAccount->id)) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'এই পর্দাগুলোয় MFS হিসাবটা বাছা যায় না: '.implode(' · ', $missing),
            'ছাঁকনিতে StandardChart::MONEY_PARENTS ব্যবহার করুন —',
            'হাতে লেখা জোড়া একদিন তৃতীয় কোডটা ভুলে যায়।',
        ]));
    }

    /**
     * পুরনো কোম্পানির বিকাশ হিসাবগুলো নিজে থেকে সরে যায়।
     *
     * ছকের ঘোষণা বদলালে চলমান কোম্পানির সারি নড়ে না — ডিপ্লয়ের
     * কমান্ডটাই ওদের সরায় ([[SplitBankAndMobileMoney]])।
     */
    public function test_an_old_bkash_account_under_bank_moves_across(): void
    {
        $bank = StandardChart::find(StandardChart::BANK);

        $stray = app(AccountService::class)->create([
            'code' => '1102-OLD-BK',
            'name_en' => 'Nagad Distributor',
            'parent_id' => $bank->id,
            'is_bank' => true,
        ]);

        /* সাধারণ ব্যাংক হিসাব — এটা যেন না নড়ে */
        $keep = app(AccountService::class)->create([
            'code' => '1102-CITY',
            'name_en' => 'City Bank Current Account',
            'parent_id' => $bank->id,
            'is_bank' => true,
        ]);

        Artisan::call('abos:split-bank-and-mfs');

        $this->assertSame(
            StandardChart::find(StandardChart::MOBILE_MONEY)->id,
            $stray->fresh()->parent_id,
            'নগদের হিসাবটা ব্যাংকের নিচেই রয়ে গেছে।',
        );

        $this->assertSame($bank->id, $keep->fresh()->parent_id,
            'সাধারণ ব্যাংক হিসাবটাও সরিয়ে দেওয়া হয়েছে — অচেনা কিছু ছোঁয়ার কথা নয়।');
    }
}
