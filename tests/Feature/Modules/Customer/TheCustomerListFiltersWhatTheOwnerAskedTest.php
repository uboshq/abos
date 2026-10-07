<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\MasterData\Models\Location;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * গ্রাহকের তালিকার ছাঁকনি — মালিক, ১ অক্টোবর ২০২৬ (Customers, "+ ছাঁকনি"):
 * *"date range, due range, advance range, area wise, point wise, active/inactive — eigulo but ache ki kucui nai"*।
 *
 *   তারিখ   — গ্রাহক খোলার দিন, থেকে–পর্যন্ত
 *   বকেয়া  — সারিতে দেখানো বকেয়া (ধনাত্মক), সর্বনিম্ন–সর্বোচ্চ
 *   অগ্রিম  — ঋণাত্মক বকেয়া, ধনাত্মক অঙ্কে লেখা সর্বনিম্ন–সর্বোচ্চ
 *   এরিয়া   — এরিয়ার নিচের সব পয়েন্ট ও রুটের গ্রাহক (পুরো ডাল)
 *   পয়েন্ট  — ঐ পয়েন্ট ও তার রুটের গ্রাহক
 *   অবস্থা  — সক্রিয় (ডিফল্ট) / নিষ্ক্রিয় / সব; পুরনো `?inactive=1` = সব
 *
 * ⓘ প্রতিটা ছাঁকনিতে একজন ঢোকে আর একজন বাদ পড়ে, একই মালিক; শেষে কয়েকটা একসাথে। চিপ ওঠে আর সরানো যায়,
 * রপ্তানি ছাঁকনি মানে।
 */
final class TheCustomerListFiltersWhatTheOwnerAskedTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Customer> */
    private array $c = [];

    private Location $area1;

    private Location $point1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->area1 = $this->place('ZQA1', Location::TERRITORY, null);
        $this->point1 = $this->place('ZQP1', Location::POINT, $this->area1);
        $route1 = $this->place('ZQR1', Location::ROUTE, $this->point1);
        $area2 = $this->place('ZQA2', Location::TERRITORY, null);
        $point2 = $this->place('ZQP2', Location::POINT, $area2);

        // ⓘ নামগুলো অদ্ভুত — পাতার আর কোথাও মিলে যাবে না
        $this->c['old_due'] = $this->customer('Zqc Old Due', $this->point1, '2026-01-10', due: '5000');
        $this->c['route_due'] = $this->customer('Zqc Route Due', $route1, '2026-06-15', due: '800');
        $this->c['advance'] = $this->customer('Zqc Advance', $point2, '2026-06-20', due: '-3000');
        $this->c['clean'] = $this->customer('Zqc Clean', $point2, '2026-09-01');
        $this->c['sleeping'] = $this->customer('Zqc Sleeping', $this->point1, '2026-06-01');
        $this->c['sleeping']->forceFill(['is_active' => false])->saveQuietly();
    }

    public function test_each_filter_lets_one_in_and_keeps_one_out(): void
    {
        $this->sees(['created_from' => '2026-06-01', 'created_to' => '2026-06-30'],
            ['route_due', 'advance'], ['old_due', 'clean']);

        $this->sees(['due_min' => '1000'], ['old_due'], ['route_due', 'advance', 'clean']);
        $this->sees(['due_min' => '500', 'due_max' => '1000'], ['route_due'], ['old_due', 'advance']);

        $this->sees(['advance_min' => '2000', 'advance_max' => '4000'], ['advance'], ['old_due', 'clean', 'route_due']);
        $this->sees(['advance_min' => '5000'], [], ['advance']);

        $this->sees(['area' => $this->area1->id], ['old_due', 'route_due'], ['advance', 'clean']);
        $this->sees(['point' => $this->point1->id], ['old_due', 'route_due'], ['advance', 'clean']);

        $this->sees([], ['old_due', 'clean'], ['sleeping']);
        $this->sees(['status' => 'inactive'], ['sleeping'], ['old_due', 'clean']);
        $this->sees(['status' => 'all'], ['sleeping', 'old_due'], []);
        $this->sees(['inactive' => '1'], ['sleeping', 'old_due'], [], 'পুরনো ?inactive=1 লিংক');
    }

    public function test_filters_combine_and_the_export_obeys_them(): void
    {
        $this->sees(['area' => $this->area1->id, 'due_min' => '1000', 'status' => 'all'], ['old_due'], ['route_due', 'sleeping']);
        $this->sees(['area' => $this->area1->id, 'status' => 'inactive'], ['sleeping'], ['old_due']);

        $csv = (string) $this->get(route('customer.index', ['due_min' => '1000', 'export' => 'csv']))->assertOk()->getContent();
        $this->assertStringContainsString('Zqc Old Due', $csv);
        $this->assertStringNotContainsString('Zqc Route Due', $csv, '⛔ রপ্তানি ছাঁকনি মানেনি।');
    }

    public function test_each_filter_shows_a_chip_that_removes_it_and_the_panel_carries_every_control(): void
    {
        $html = (string) $this->get(route('customer.index', ['area' => $this->area1->id, 'due_min' => '1000']))
            ->assertOk()->getContent();

        // ⓘ চিপে এরিয়ার নাম, আইডি নয়; আর চিপের ঠিকানায় বাকি ছাঁকনিটা থাকে
        // ⓘ নাম পাতার ভাষায় — বাংলা পাতায় `name_bn` ("ZQA1 জায়গা")
        $this->assertMatchesRegularExpression('/data-facet[^>]*href="[^"]*due_min=1000[^"]*"[^>]*>\s*<span[^>]*>[^<]*ZQA1 /u', $html,
            'এরিয়ার চিপে জায়গার নাম নেই, বা চিপ সরালে বকেয়ার ছাঁকনিও চলে যায়।');
        $this->assertDoesNotMatchRegularExpression('/data-facet[^>]*>\s*<span[^>]*>[^<]*: '.$this->area1->id.'\s*</u', $html, 'চিপে এরিয়ার আইডি।');

        foreach (['created_from', 'created_to', 'due_min', 'due_max', 'advance_min', 'advance_max', 'area', 'point', 'status'] as $name) {
            $this->assertMatchesRegularExpression('/name="'.$name.'"/', $html, "ছাঁকনির প্যানেলে \"{$name}\" ঘর নেই।");
        }
    }

    /** ⭐ টপ/বটম N — বিক্রি গত ৯০ দিনে (যাঁরা কিনেছেন তাঁদের মধ্যে), আর বকেয়া */
    public function test_top_and_bottom_n_rank_by_sales_and_by_due(): void
    {
        $this->bill($this->c['old_due'], '500', 5);
        $this->bill($this->c['route_due'], '300', 10);
        $this->bill($this->c['clean'], '100', 20);
        $this->bill($this->c['advance'], '900', 200); // ⓘ ৯০ দিনের বাইরে — গোনায় আসে না

        $this->sees(['rank' => 'top', 'rank_n' => '2'], ['old_due', 'route_due'], ['clean', 'advance']);
        $this->sees(['rank' => 'bottom', 'rank_n' => '2'], ['clean', 'route_due'], ['old_due', 'advance']);
        $this->sees(['rank' => 'top', 'rank_n' => '1', 'rank_by' => 'due'], ['old_due'], ['route_due', 'clean']);
        $this->sees(['rank' => 'bottom', 'rank_n' => '1', 'rank_by' => 'due'], ['route_due'], ['old_due', 'clean', 'advance']);

        // ⓘ শিরোনাম বলে কী দেখছেন; আর এরিয়ার সাথে মেলে
        $html = (string) $this->get(route('customer.index', ['rank' => 'top', 'rank_n' => '2']))->getContent();
        $this->assertStringContainsString(e(__('customer::filter.rank_title', [
            'rank' => __('customer::filter.top'), 'n' => 2, 'by' => __('customer::filter.by_sales'),
            'period' => __('customer::filter.last_days', ['days' => 90]),
        ])), $html);
        $this->sees(['rank' => 'top', 'rank_n' => '5', 'area' => $this->area1->id], ['old_due', 'route_due'], ['clean']);
    }

    /** ⭐ ভালো কাস্টমার — গত ৯০ দিনে কিনেছেন, সীমার ভেতরে, কোনো বিল নিজের বাকির দিন পেরিয়ে অপরিশোধিত নয় */
    public function test_a_good_customer_bought_lately_and_left_no_old_bill_unpaid(): void
    {
        $this->bill($this->c['clean'], '100', 10);            // ভালো: নতুন বিল, ৩০ দিনের ভেতরে
        $this->bill($this->c['route_due'], '100', 10);
        $this->bill($this->c['route_due'], '100', 45);        // ⛔ ৪৫ দিনের অপরিশোধিত বিল, বাকির দিন খালি = ৩০
        $this->bill($this->c['advance'], '100', 120);         // ⛔ ৯০ দিনে কেনেনি

        // ⓘ সত্যিকারের সীমা, যাতে ৫,০০০ বকেয়া সীমার ভেতরে থাকে — ১ অক্টোবর ২০২৬ থেকে শূন্য সীমা মানে বাকি নেই
        //   (মালিকের চূড়ান্ত কথা), কোনো সুইচে নয়; আগে এখানে "শূন্য = সীমাহীন" ধরা হত
        $this->c['old_due']->forceFill(['credit_days' => 60, 'credit_limit' => '100000'])->saveQuietly();
        $this->bill($this->c['old_due'], '100', 45);          // ভালো: ৪৫ দিন, কিন্তু তাঁর বাকির দিন ৬০

        $this->sees(['quick' => 'good'], ['clean', 'old_due'], ['route_due', 'advance']);
    }

    private function bill(Customer $customer, string $total, int $daysAgo): void
    {
        DB::table('sal_invoices')->insert([
            'company_id' => CompanyContext::id(),
            'branch_id' => $customer->branch_id,
            'document_no' => 'ZQ-'.$customer->id.'-'.$daysAgo.'-'.random_int(1, 99999),
            'customer_id' => $customer->id,
            'trx_date' => now()->subDays($daysAgo)->toDateString(),
            'total' => $total,
            'status' => 'confirmed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $in
     * @param  list<string>  $out
     */
    private function sees(array $filters, array $in, array $out, string $why = ''): void
    {
        $html = (string) $this->get(route('customer.index', $filters))->assertOk()->getContent();
        $label = ($why !== '' ? $why.' — ' : '').json_encode($filters);

        foreach ($in as $key) {
            $this->assertStringContainsString($this->c[$key]->name_en, $html, "⛔ {$label}: {$key} থাকার কথা, নেই।");
        }

        foreach ($out as $key) {
            $this->assertStringNotContainsString($this->c[$key]->name_en, $html, "⛔ {$label}: {$key} বাদ পড়ার কথা, আছে।");
        }
    }

    private function place(string $code, string $level, ?Location $parent): Location
    {
        return Location::query()->create([
            'company_id' => CompanyContext::id(),
            'parent_id' => $parent?->id,
            'code' => $code,
            'name_en' => $code.' Place',
            'name_bn' => $code.' জায়গা',
            'level' => $level,
            'is_active' => true,
        ]);
    }

    private function customer(string $name, Location $where, string $opened, ?string $due = null): Customer
    {
        $customer = app(CustomerService::class)->create(['name_en' => $name, 'name_bn' => $name, 'credit_limit' => '0']);
        $customer->forceFill(['location_id' => $where->id, 'created_at' => $opened.' 10:00:00'])->saveQuietly();

        if ($due !== null) {
            $receivable = Account::query()->where('code', StandardChart::RECEIVABLE)->firstOrFail();
            $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();
            $amount = ltrim($due, '-');
            $owes = ! str_starts_with($due, '-');

            app(PostingEngine::class)->post(
                sourceType: 'test:customer-balance',
                sourceId: (int) $customer->id,
                trxDate: now()->toDateString(),
                lines: [
                    ['account_id' => $receivable->id, $owes ? 'debit' : 'credit' => $amount,
                        'party_type' => Customer::drillSourceType(), 'party_id' => (int) $customer->id],
                    ['account_id' => $capital->id, $owes ? 'credit' : 'debit' => $amount],
                ],
            );
        }

        return $customer->fresh();
    }
}
