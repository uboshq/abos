<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * অর্থের পর্দা এমন একটা শব্দ খুঁজত যা কেউ লেখে না।
 *
 * ── ⛔ লাইভে যা দেখা গেছে, ২৬ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * TCL-এ মালিক ৪,৯৯৯ টাকা মূলধন দিয়েছেন। কাগজটা ঠিক আছে (CAP-0001),
 * খতিয়ানেও বসেছে (RCV-0003, খাত ৩১০০ ক্রেডিট)। ⚠️ অথচ অর্থের
 * ড্যাশবোর্ডে *"মূলধন এসেছে"* দেখায় **০.০০**।
 *
 * ── ⓘ কারণটা এক লাইনের, আর সেটাই একে এত নীরব করেছে ───────────────────
 * [[FinanceDashboard]] গোনে `where('entry_type', 'in')`। ⛔ কিন্তু
 * `entry_type` ঘরে কখনো `'in'` বসে না — বসে `contribution` বা
 * `investment` ([[CapitalEntry::KINDS]])। ⓘ যাচাইকরণ সেটাই বাধ্য করে,
 * শ্রোতা সেটাই লেখে, ফর্মের ডিফল্টও সেটাই।
 *
 * ⚠️ তাই ছাঁকনিটা **সবসময় শূন্য সারি** পায়, আর `sum()` ০.০০ দেয়।
 * ⛔ কোনো ত্রুটি নয়, কোনো ৫০০ নয় — সংখ্যাটা শুধু চিরকাল শূন্য।
 *
 * ⭐ আর লক্ষণটার সবচেয়ে বিভ্রান্তিকর দিকটা এখানেই: পাশের টাইলে
 * **দাতার সংখ্যা** ঠিক দেখায়, কারণ ওখানে কোনো ছাঁকনি নেই। ⓘ ফলে পর্দা
 * বলে *"একজন দাতা, মোট ০.০০"* — আর সেটা পড়তে ডেটা হারানোর মতো লাগে,
 * অথচ ডেটা অক্ষত।
 *
 * ── ⓘ দাবিটা পর্দার দিক থেকে, কোয়েরির দিক থেকে নয় ───────────────────
 * ⚠️ কোয়েরি ধরে দাবি লিখলে সেটা আমার নিজের বোঝাটাই যাচাই করত।
 * ⭐ এখানে যা মাপা হয়, ব্যবহারকারী ঠিক তাই দেখেন: পাতাটা খুলে সংখ্যাটা
 * পড়া।
 */
final class TheDashboardLookedForAWordNobodyWritesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        /*
         * ⓘ ঘরের নিয়ম — বসানো কোম্পানি আর বসানো ব্যবহারকারী।
         *
         * ⛔ প্রথমে হাতে কোম্পানি বানিয়ে নিজে চাবি দিয়েছিলাম, আর
         * পাতাটা **৬০৩** দিয়েছিল — অনুমতি এখানে কোম্পানি-ভিত্তিক
         * (`team_foreign_key => company_id`), তাই নতুন কোম্পানিতে কোনো
         * অনুমতি অস্তিত্বেই থাকে না।
         *
         * ⚠️ আর সেই ৬০৩-কে আমি একবার **ফাঁকের প্রমাণ বলে ধরে
         * নিয়েছিলাম** — লাল দেখে, কারণ না পড়ে। পাতাটা খোলেইনি,
         * সংখ্যায় পৌঁছানোই যায়নি।
         */
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        /*
         * ⓘ `DemoSeeder` কোনো ব্যক্তির সারি বসায় না, তাই নিজেই বসানো।
         * ⚠️ ঘরগুলো `SHOW COLUMNS` দিয়ে **মেপে** নেওয়া — আজ আমি
         * তিনবার আন্দাজে ঘর বাদ দিয়ে তিনটা রান নষ্ট করেছি।
         */
        $this->person = Person::create([
            'company_id' => CompanyContext::id(),
            'code' => 'PSN-CAP-1',
            'name_en' => 'Karim Mia',
        ]);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ⭐ আসল দাবিটা ─────────────────────────────────────────────────

    public function test_the_money_that_came_in_is_shown_not_zero(): void
    {
        $this->aContributionOf('4999');

        $page = $this->actingAs($this->owner)->get('/dashboard/finance');

        $page->assertOk();

        $this->assertSame('4,999.00', $this->capitalInTile($page));
    }

    public function test_an_investment_counts_too(): void
    {
        /*
         * ⓘ `KINDS` দুইটা — দান আর বিনিয়োগ। ⚠️ কেবল একটা গুনলে টাইলটা
         * *"মূলধন এসেছে"* বলে অর্ধেক সত্যি বলত, আর সেই ভুলটা ধরা আরও
         * কঠিন হত: সংখ্যাটা শূন্য নয়, কেবল কম।
         */
        $this->aContributionOf('1000', CapitalEntry::INVESTMENT);

        $page = $this->actingAs($this->owner)->get('/dashboard/finance');

        $page->assertOk();
        /*
         * ⛔ আগে কেবল `assertSee('1,000.00')` লেখা ছিল, আর সেটা
         * **অন্ধ প্রমাণিত হয়েছে** — ২৭ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ মিউটেন্ট বসানো হয়েছিল যাতে টাইলটা কেবল **দান** গোনে,
         * অর্থাত্ বিনিয়োগের টাকা বাদ পড়ত। ⚠️ তবু এই দাবি **সবুজ
         * থেকেছে** — কারণ `1,000.00` হরফগুলো পাতার **অন্য কোনো
         * ঘরে** ও পাওয়া যায়। ⓘ অর্থের ড্যাশবোর্ড টাকার সংখ্যায় ভরা।
         *
         * ⭐ তাই এখন লেবেল আর মান **ক্রম মিলিয়ে** দেখা হয় — সংখ্যাটা
         * নিজের লেবেলের **পরে** আসতে হবে, পাতার যেখানে সেখানে নয়।
         */
        $this->assertSame('1,000.00', $this->capitalInTile($page));
    }

    public function test_the_two_kinds_are_added_together(): void
    {
        $this->aContributionOf('4999');
        $this->aContributionOf('1000', CapitalEntry::INVESTMENT);

        $page = $this->actingAs($this->owner)->get('/dashboard/finance');

        $page->assertOk();

        $this->assertSame('5,999.00', $this->capitalInTile($page));
    }

    // ── ⓘ যা গোনা **উচিত নয়** ────────────────────────────────────────

    public function test_profit_kept_in_the_business_is_not_money_that_came_in(): void
    {
        /*
         * ⛔ `PROFIT` মূলধনের সারিতে বসে, কিন্তু সেটা **বাইরে থেকে আসা
         * টাকা নয়** — ব্যবসার নিজের লাভ যা তুলে নেওয়া হয়নি
         * ([[ProfitDistribution]])।
         *
         * ⚠️ এই দাবিটা ছাড়া সারাইটা "সব সারি যোগ করো" হয়ে যেত, আর তখন
         * টাইলটা আবার ভুল বলত — এবার উল্টো দিকে, আর সেটা ধরা আরও কঠিন,
         * কারণ সংখ্যাটা তখন **বড়** দেখাত, শূন্য নয়।
         */
        $this->aContributionOf('4999');
        $this->aContributionOf('7777', CapitalEntry::PROFIT);

        $page = $this->actingAs($this->owner)->get('/dashboard/finance');

        $page->assertOk();

        $this->assertSame('4,999.00', $this->capitalInTile($page));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * ⓘ ঘরগুলো টেবিল থেকে মেপে নেওয়া (`SHOW COLUMNS`), আন্দাজে নয় —
     * `company_id`, `document_no`, `contributor_type`, `entry_type`,
     * `trx_date`, `amount` ছয়টার কোনোটার ডিফল্ট নেই।
     *
     * ⚠️ এক এক করে ভুল ধরতে গিয়ে দুইটা রান নষ্ট হয়েছিল, তাই একবারেই
     * পুরো তালিকাটা মেপে নেওয়া হলো।
     */
    /**
     * ⭐ টাইলটার **নিজের** মান, পাতার হরফ খুঁজে নয়।
     *
     * ── ⛔ কেন দুইবার বদলাতে হলো ───────────────────────
     * ⓘ প্রথমে লেখা ছিল `assertSee('1,000.00')`। ⚠️ মিউটেন্ট
     * প্রমাণ করল সেটা **অন্ধ** — অর্থের পাতা টাকার সংখ্যায় ভরা,
     * তাই ওই হরফগুলো অন্য কোনো ঘরেও মেলে।
     *
     * ⓘ তারপর `assertSeeInOrder([লেবেল, মান])` বসানো হলো। ⛔ সেটাও
     * মিউটেন্টে বেঁচে গেল, আর কারণটা শেখার মতো: `assertSeeInOrder`
     * কেবল বলে *"লেবেল আগে, সংখ্যা পরে কোথাও"* — **একই ঘরে কি
     * না সেটা বলে না**। ⓘ ওই টাইলের পরে আরও অনেক টাইল আছে।
     *
     * ⭐ তাই এখন হরফ খোঁজা হয় না — ভিউ-ডেটা থেকে টাইলটা নাম ধরে
     * খুঁজে তার `value` **হুবহু** মিলানো হয়। ⓘ এখানে পাশের কোনো
     * ঘর সাহায্য করতে পারে না।
     */
    private function capitalInTile(\Illuminate\Testing\TestResponse $page): string
    {
        $dashboard = $page->viewData('dashboard');

        foreach ($dashboard->stats as $stat) {
            if ($stat->label === __('finance::dashboard.capital_in')) {
                return (string) $stat->value;
            }
        }

        $this->fail('অর্থের পাতায় "মূলধন এসেছে" টাইলটাই নেই — দাবিটা কিছুই মাপত না।');
    }

    private function aContributionOf(string $amount, string $kind = CapitalEntry::CONTRIBUTION): CapitalEntry
    {
        static $serial = 0;

        return CapitalEntry::create([
            'company_id' => CompanyContext::id(),
            'branch_id' => CompanyContext::branchId(),
            'document_no' => 'CAP-'.str_pad((string) ++$serial, 4, '0', STR_PAD_LEFT),
            'person_id' => $this->person->id,
            'contributor_type' => CapitalEntry::OWNER,
            'entry_type' => $kind,
            'in_kind' => CapitalEntry::CASH,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
            'status' => CapitalEntry::POSTED,
        ]);
    }
}
