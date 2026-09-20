<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsurancePolicy;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * প্রতিটা নাম একটা দরজা।
 *
 * ── ⭐ মালিকের নালিশ, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"সব জায়গায় হাইপার লিংক দেওয়ার কথা, but notun kaje kotaw hyperlink
 * dicche na"* — নতুন পর্দাগুলোতে নাম আছে, দরজা নেই।
 *
 * ⓘ নিয়মটা সরল: ঘরে যদি কোনো **নথি, মানুষ, হিসাব বা সংখ্যা** থাকে, ঘরটা
 * সেই জিনিসটা খোলে; সংখ্যা খোলে তার পিছনের সারিগুলো। ⛔ সাদা থাকে কেবল
 * সেটাই, যার পিছনে কিছু নেই।
 *
 * ⚠️ এই ফাইলটা পাহারা, প্রমাণ নয়: চোখে দেখে "লিংক আছে" বলা যায়, কিন্তু
 * ছয় মাস পরে কেউ একটা কলাম নতুন করে লিখলে সেটা আবার সাদা হয়ে যেত, আর
 * কেউ ধরত না।
 */
final class EveryNameIsADoorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
    }

    /**
     * ⭐ বিমার তালিকায় বিমাকারীর নাম তার প্রতিষ্ঠানের পাতায় নামে।
     */
    public function test_the_insurer_opens_the_institution(): void
    {
        $policy = $this->policy();

        $this->get(route('finance.insurance.index'))
            ->assertOk()
            ->assertSee(route('finance.institution.show', $policy->institution_id), escape: false);

        $this->get(route('finance.insurance.show', $policy))
            ->assertOk()
            ->assertSee(route('finance.institution.show', $policy->institution_id), escape: false);
    }

    /**
     * ⭐ ব্যাংক চার্জে "কতবার" সংখ্যাটা ঐ ব্যাংকের কাটাগুলো খোলে।
     *
     * ⓘ পরীক্ষাটা সত্যিকারের একটা কাটা বসিয়ে করা হয় — নাহলে ঘরটাই থাকত না।
     */
    public function test_the_bank_charge_count_filters_to_that_bank(): void
    {
        $bank = $this->chargedBank();

        // ⭐ "১ বার" ঘরটা ঐ ব্যাংকে ছাঁকা তালিকায় নামে
        $list = $this->get(route('finance.bank_charge.index'));

        $list->assertOk();
        $list->assertSee(route('finance.bank_charge.index', [
            'period' => 'this_month', 'bank_id' => $bank->id,
        ]));

        // ⭐ ছাঁকনি চালু থাকলে সেটা লেখা থাকে, আর ফেরার পথও থাকে
        $filtered = $this->get(route('finance.bank_charge.index', ['bank_id' => $bank->id]));

        $filtered->assertOk();
        $filtered->assertSee(__('finance::bank_charge.show_all_banks'));
    }

    /**
     * ⛔ পাতাটা ২০০ দিলেও টেবিলটা কাঁচা ছাপা হতে পারে।
     *
     * ── কেন এই অদ্ভুত পরীক্ষাটা ─────────────────────────────────────
     * ⚠️ `:columns="[ … ]"` ঘরের **ভিতরে** মন্তব্যে একটা ASCII ডবল কোট
     * থাকলে Blade ঐ কোটেই অ্যাট্রিবিউট শেষ ধরে নেয় — তখন `<x-ui.table>`
     * ট্যাগটা কম্পোনেন্ট না হয়ে অক্ষর হয়ে পাতায় বসে। ⓘ সার্ভার ২০০ বলে,
     * `php -l` চুপ, ভিউ কম্পাইলও চুপ — কেবল চোখে দেখলে ধরা পড়ে
     * (abos-f9 ধরেছে, abos-d1 জানিয়েছে, ২০ সেপ্টেম্বর ২০২৬)।
     */
    public function test_the_analysis_table_is_a_table_not_a_line_of_text(): void
    {
        $account = Account::query()->postable()->active()->firstOrFail();

        $page = $this->get(route('finance.account_analysis.index', ['account_id' => $account->id]));

        $page->assertOk();
        $page->assertDontSee('<x-ui.table', escape: false);
    }

    /**
     * ⭐ পরিবহন-মজুরির খতিয়ানে পক্ষের নিজের পাতার ঠিকানাটা সেবা থেকেই আসে।
     *
     * ⚠️ পরীক্ষাটা পর্দার নয়, [[App\Core\Services\PartyRegistry::routesOf]]-এর:
     * ঠিকানাটা যদি মডেলের `drillRoute()` থেকে না আসত, প্রতিটা রিপোর্টে
     * "সরবরাহকারী হলে এই রুট" হাতে লিখতে হত — আর নতুন ধরনের পক্ষ যোগ হলে
     * সেগুলো চুপচাপ সাদা হয়ে যেত।
     */
    public function test_a_party_carries_the_route_to_its_own_page(): void
    {
        $supplier = \App\Modules\Supplier\Models\Supplier::query()->firstOrFail();

        $routes = app(\App\Core\Services\PartyRegistry::class)
            ->routesOf([['supplier', (int) $supplier->id]]);

        $this->assertArrayHasKey('supplier:'.$supplier->id, $routes,
            'পক্ষের নামের সাথে তার পাতার ঠিকানা আসেনি — রিপোর্টে ঘরটা মরা থাকবে।');

        [$name, $params] = $routes['supplier:'.$supplier->id];

        $this->assertSame('supplier.show', $name);
        $this->assertSame((int) $supplier->id, (int) reset($params));
    }

    /**
     * একটা ব্যাংক খাত, যার নামে সত্যিই একটা চার্জ কাটা হয়েছে।
     *
     * ⓘ চার্জ ছাড়া "ব্যাংক ধরে" তালিকাটা খালি থাকে, আর তখন ঘরটাই থাকে না —
     * পরীক্ষাটা তখন কিছুই প্রমাণ করত না।
     */
    private function chargedBank(): Account
    {
        /*
         * ⚠️ ১১০২ একটা মাথা, খাত নয় — প্রতিটা ব্যাংক হিসাব তার নিচে বসে।
         * ⓘ তাই নিজেরই একটা বসানো হয়: ডেমো তথ্যে ব্যাংক হিসাব না-ও থাকতে
         * পারে, আর থাকলেও সেটার উপর ভরসা করলে পরীক্ষাটা অন্যের তথ্যের
         * উপর দাঁড়াত।
         */
        $head = Account::query()->where('code', StandardChart::BANK)->firstOrFail();

        $bank = Account::query()->create([
            'parent_id' => $head->id,
            'code' => '1102-T1',
            'name_en' => 'Test Bank Account',
            'name_bn' => 'পরীক্ষা ব্যাংক হিসাব',
            'type' => $head->type,
            'nature' => $head->nature,
            'is_group' => false,
            'money_kind' => Account::BANK,
            'is_active' => true,
        ]);

        $charge = Account::query()->where('code', StandardChart::BANK_CHARGES)->postable()->firstOrFail();

        $vouchers = app(VoucherService::class);

        /*
         * ⚠️ ব্যাংকের খাতে টাকা নড়লে লেনদেন নম্বরটা বাধ্যতামূলক — ওটা ছাড়া
         * পরে ব্যাংকের কাগজের সাথে মেলানো যায় না, তাই পোস্টই হয় না।
         */
        $vouchers->post($vouchers->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(),
             'narration' => 'চার্জ', 'instrument_no' => 'TRX-1'],
            [
                ['account_id' => $charge->id, 'debit' => '50', 'credit' => '0'],
                ['account_id' => $bank->id, 'debit' => '0', 'credit' => '50'],
            ],
        ));

        return $bank;
    }

    private function policy(): InsurancePolicy
    {
        // ⓘ ডেমো তথ্যে বিমা প্রতিষ্ঠান না-ও থাকতে পারে, তাই নিজেরই একটা
        $institution = Institution::query()->create([
            'kind' => Institution::INSURANCE,
            'name_en' => 'Test Insurance Co',
            'name_bn' => 'পরীক্ষা বিমা কোম্পানি',
            'short_code' => 'TICO',
            'is_active' => true,
        ]);

        return InsurancePolicy::query()->create([
            'institution_id' => $institution->id,
            'policy_no' => 'POL-TEST-1',
            'subject' => 'গুদাম',
            'covers' => InsurancePolicy::COVERS[0],
            'sum_insured' => '100000',
            'premium' => '5000',
            'starts_on' => now()->subMonth()->toDateString(),
            'ends_on' => now()->addMonths(11)->toDateString(),
            'is_active' => true,
        ]);
    }
}
