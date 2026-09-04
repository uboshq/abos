<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ক্রয়ের পর্দায় "বকেয়া" — কোনটা এই বিলের, কোনটা পুরনো খাতার।
 *
 * ── কেন তিনটা সারি, একটা নয় ─────────────────────────────────────────
 * মালিকের ছবিতে মোটের কার্ডে তিনটা আলাদা সারি:
 *
 *     এই বিলে বাকি  +  আগের বকেয়া  =  মোট বকেয়া
 *
 * ⚠️ এক সংখ্যায় মিশিয়ে দিলে *"৳৫০,০০০ বাকি"* পড়ে বোঝার উপায় থাকত না
 * ওটা আজকের কাগজের, নাকি ছয় মাসের জমা দেনা। ⓘ দুইজন মানুষ দুইটা অর্থ
 * করতেন, আর দরাদরির টেবিলে ওই ভুলের দাম টাকা।
 *
 * ── ⛔ আর সবচেয়ে সম্ভাব্য বাগটা এখানেই ──────────────────────────────
 * "আগের বকেয়া" মানে **এই বিলের আগে পর্যন্ত**, আজ পর্যন্ত নয়। সংখ্যাটা
 * এই বিলটাকেও গুনে ফেললে `DUE` দ্বিগুণ দেখাত — আর দেখতে বিশ্বাসযোগ্যই
 * থাকত।
 */
class TheDueOnTheBuyingScreenSaysWhoseDebtItIsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $buyer;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'DP', 'name_en' => 'Direct Purchase Co']);
        CompanyContext::set($this->company->id);
        app(StandardChart::class)->install();

        FinancialYear::create([
            'name' => '2026-2027',
            'starts_on' => now()->startOfYear()->toDateString(),
            'ends_on' => now()->endOfYear()->toDateString(),
            'is_current' => true,
        ]);

        // লাইভে `PermissionSyncer` বসায়; ফেলনা ডাটাবেসে সে চলে না
        Permission::findOrCreate('purchase.bill.create', 'web');

        $this->buyer = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->buyer->companies()->attach($this->company->id, ['is_active' => true]);
        $this->buyer->givePermissionTo('purchase.bill.create');

        $this->supplier = Supplier::create([
            'code' => 'SUP-0001',
            'name_en' => 'Karim Traders',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /**
     * সরবরাহকারীর খাতায় একটা পুরনো দেনা বসানো।
     *
     * ⓘ সরাসরি খতিয়ানে, কারণ পরীক্ষার বিষয় **পর্দা কী পড়ে**, বিল কীভাবে
     * তৈরি হয় তা নয়। ⚠️ পক্ষ ধরে বসানো হয়, খাত ধরে নয় — `payable()`
     * ওভাবেই গোনে।
     */
    private function owe(string $amount, bool $weOwe = true): void
    {
        DB::table('ledger_entries')->insert([
            'company_id' => $this->company->id,
            'financial_year_id' => DB::table('financial_years')
                ->where('company_id', $this->company->id)->value('id'),
            'account_id' => StandardChart::find(StandardChart::PAYABLE)->id,
            'trx_date' => now()->subMonth()->toDateString(),
            'debit' => $weOwe ? '0' : $amount,
            'credit' => $weOwe ? $amount : '0',
            'party_type' => Supplier::drillSourceType(),
            'party_id' => $this->supplier->id,
            'source_type' => 'purchase_bill',
            'source_id' => 1,
            'narration' => 'পুরনো দেনা',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function askTheScreen(): array
    {
        return $this->actingAs($this->buyer)
            ->getJson(route('purchase.direct.last_rates', ['supplier' => $this->supplier->id]))
            ->assertOk()
            ->json();
    }

    /** ⭐ পর্দা যে সংখ্যাটা পড়ে, সেটা সরবরাহকারীর আসল বকেয়া। */
    public function test_the_screen_is_told_what_we_already_owe(): void
    {
        $this->owe('12500.0000');

        $payload = $this->askTheScreen();

        /* ⓘ `assertSame` নয়: JSON-এ ১২৫০০.০ পূর্ণসংখ্যা হয়ে ফেরে, আর
           ধরনটা এখানে প্রশ্ন নয় — অঙ্কটা প্রশ্ন। */
        $this->assertEqualsWithDelta(12500, $payload['due'], 0.0001, 'পর্দা ভুল বকেয়া পাচ্ছে');
    }

    /**
     * ⛔ অগ্রিম — সংখ্যাটা ঋণাত্মক, আর পর্দা লেবেলই বদলে দেয়।
     *
     * ⚠️ শূন্যে আটকে দিলে (`max(0, …)`) অগ্রিমটা উধাও হত, আর মোট বকেয়া
     * বেশি দেখাত — অর্থাৎ যে টাকা আমরা আগেই দিয়ে রেখেছি সেটা আবার
     * দেওয়ার কথা বলত।
     */
    public function test_an_advance_comes_back_negative_not_zero(): void
    {
        $this->owe('4000.0000', weOwe: false);

        $this->assertEqualsWithDelta(-4000, $this->askTheScreen()['due'], 0.0001);
    }

    /** কিছু না থাকলে শূন্য — আর সেটাই "কোনো পুরনো হিসাব নেই"। */
    public function test_a_new_supplier_owes_nothing(): void
    {
        $this->assertEqualsWithDelta(0, $this->askTheScreen()['due'], 0.0001);
    }

    /**
     * ⛔ অন্য কোম্পানির সরবরাহকারীর বকেয়া এই কোম্পানি দেখে না।
     *
     * ⓘ বহু-টেন্যান্টে এটা সুবিধা নয়, শর্ত — আর দরজাটা একটা `abort_if`,
     * তাই ভেঙে গেলে নীরবে ভাঙত।
     */
    public function test_another_companys_supplier_is_a_closed_door(): void
    {
        $theirs = Company::create(['code' => 'OTH', 'name_en' => 'Another Co']);

        CompanyContext::set($theirs->id);
        $theirSupplier = Supplier::create(['code' => 'SUP-0001', 'name_en' => 'Theirs', 'is_active' => true]);
        CompanyContext::set($this->company->id);

        $this->actingAs($this->buyer)
            ->getJson(route('purchase.direct.last_rates', ['supplier' => $theirSupplier->id]))
            ->assertNotFound();
    }

    /**
     * ⭐ দরগুলো একই উত্তরে আসে — নতুন দরজা বানানো হয়নি।
     *
     * ⚠️ কেন সেটা জরুরি: পর্দাটা এই একটাই দরজা **দুই জায়গা থেকে** ডাকে —
     * সরবরাহকারী বাছার সময়, আর যাচাই ব্যর্থ হয়ে পাতা ফিরে এলে। ⓘ বকেয়ার
     * জন্য দ্বিতীয় একটা দরজা বানালে দ্বিতীয় প্রবেশপথটা ঢাকতে ভুলে
     * যাওয়ার সম্ভাবনা ছিল, আর তখন ভুল শুধরে ফেরা মানুষটা **পুরনো
     * সরবরাহকারীর বকেয়া** দেখতেন।
     */
    public function test_the_rates_and_the_due_travel_together(): void
    {
        $payload = $this->askTheScreen();

        $this->assertArrayHasKey('rates', $payload);
        $this->assertArrayHasKey('due', $payload);
    }

    /**
     * ⛔ আর পর্দায় তিনটা সারিই আছে, একটা নয়।
     *
     * ⓘ সংখ্যাগুলো Alpine-এ গোনা হয়, তাই এখানে **লেবেলগুলোর উপস্থিতি**
     * পরীক্ষা করা হয় — ওটাই বলে কার্ডটা ছবির আকারে আছে কি না।
     */
    public function test_the_totals_card_keeps_the_three_debts_apart(): void
    {
        $html = $this->actingAs($this->buyer)
            ->get(route('purchase.direct.create'))
            ->assertOk()
            ->getContent();

        /*
         * ⛔ লেখাটা পাতায় *কোথাও* আছে — এটুকু দেখা যথেষ্ট নয়। এই লাইনটা
         * রক্তে লেখা।
         *
         * প্রথম যেবার এই পরীক্ষাটা লেখা হয়, লেবেলদুটো ইচ্ছে করে সরিয়েও
         * এটা **সবুজই ছিল**। কারণ Alpine-এর স্ক্রিপ্টে আমার নিজের একটা
         * মন্তব্য ছিল — `DUE = এই বিলে বাকি + আগের বকেয়া`। ⓘ পরীক্ষাটা
         * পর্দার লেবেল নয়, **নিজের ব্যাখ্যার লেখাটা** মিলিয়ে পাস করছিল।
         *
         * ⚠️ তাই এখন ঘরটাসহ খোঁজা হয় — `>লেখা</span>`। মন্তব্যের ভেতরের
         * লেখা কোনোদিন একটা বন্ধ ট্যাগ নিয়ে আসে না।
         */
        foreach (['invoice_due', 'total_due'] as $row) {
            $label = (string) __("purchase::field.{$row}");

            $this->assertStringContainsString('>'.$label.'</span>', $html,
                "মোটের কার্ডে '{$label}' সারিটা নেই — তিনটা ঋণ আবার এক হয়ে গেছে");
        }

        /*
         * ⚠️ আগের বকেয়ার সারিটা আলাদা করে দেখা হয়, আর কারণটা সূক্ষ্ম:
         * ওর লেবেল `x-text`-এ বসে, কারণ অগ্রিম হলে নামটাই বদলায়। ⓘ আর
         * `@js()` বাংলা লেখাকে `\u09…` করে পাঠায় — অর্থাৎ কাঁচা বাংলা
         * খুঁজলে ওটা **কখনোই** পাওয়া যেত না, আর পরীক্ষাটা চিরকাল
         * মন্তব্যের উপর দাঁড়িয়ে থাকত।
         */
        $this->assertStringContainsString(
            Js::from(__('purchase::field.previous_due'))->toHtml(), $html,
            'আগের বকেয়ার সারিটা কার্ডে নেই');

        $this->assertStringContainsString('previousDue < 0', $html,
            'অগ্রিম হলে লেবেল বদলানোর নিয়মটা পর্দা থেকে চলে গেছে');

        /* ⓘ আর প্রতিটা সারির নিজের অঙ্ক — নইলে তিনটা নাম একটাই সংখ্যা
           দেখাত, আর ভুলটা পড়ে ধরা যেত না। */
        foreach (['money(invoiceDue)', 'money(totalDue)'] as $sum) {
            $this->assertStringContainsString($sum, $html, "সারিটার নিজের অঙ্ক নেই: {$sum}");
        }
    }

    /**
     * ⏳ আর যা এখনো নেই, সেটাও পাহারায় — যাতে অর্ধেক অবস্থায় ফিরে না আসে।
     *
     * ⛔ খরচ ও রাউন্ডিংয়ের ঘর ইচ্ছে করে সরানো: ওগুলো পর্দায় যোগ হত কিন্তু
     * খতিয়ানে পৌঁছাত না, আর তখন পাতা বলত "মোট দেয় ৳১,০০০" অথচ খাতায়
     * বসত ৳৯৮০।
     *
     * ⚠️ **এই পরীক্ষাটা লাল হবে যেদিন কেউ ঘর দুইটা ফিরিয়ে আনবেন** — আর
     * তখন প্রশ্নটা উঠবে: সেবা ও ডাটাবেসের ঘরও কি সাথে এসেছে? ⓘ এসে
     * থাকলে এই পরীক্ষাটা মুছে ফেলাই ঠিক কাজ।
     */
    public function test_expense_and_rounding_are_not_half_built(): void
    {
        $screen = (string) file_get_contents(
            app_path('Modules/Purchase/Resources/views/direct/index.blade.php')
        );

        $carriesThem = DB::getSchemaBuilder()->hasColumn('pur_bills', 'expense');

        if ($carriesThem) {
            $this->markTestSkipped('ঘর দুইটা এখন ডাটাবেসেও আছে — পাহারাটার আর দরকার নেই।');
        }

        $this->assertStringNotContainsString('x-model="expense"', $screen,
            'খরচের ঘর পর্দায় ফিরেছে, কিন্তু বিলে ঘরটা নেই — সংখ্যাটা খতিয়ানে পৌঁছাবে না');

        $this->assertStringNotContainsString('x-model="rounding"', $screen,
            'রাউন্ডিংয়ের ঘর পর্দায় ফিরেছে, কিন্তু বিলে ঘরটা নেই');
    }
}
