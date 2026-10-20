<?php

declare(strict_types=1);

namespace Tests\Feature\Widgets;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Widgets\SalesTodaySummary;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ "আজকের বিক্রি" উইজেট ওয়েব যা বলে তাই বলে ([[SalesTodaySummary]]; মালিকের আদেশ, ২০ অক্টোবর ২০২৬)।
 *
 * দাবি: সত্যিকারের বিক্রির (আজ আর গতকাল) পরে মোট, আদায় আর গতকাল ওয়েবের একই উৎসের ([[SalesMetrics]]) হুবহু, বিলের সংখ্যা
 * খাতায় বসা বিলের গোনা; পরিবর্তনের শতাংশ ঠিক, চিহ্নসহ, আর গতকাল শূন্য হলে null; হেডারের শাখা সংখ্যা ছাঁকে; দরজায় চাবি
 * থাকলে উইজেট আছে, না থাকলে নেই।
 */
final class TheSalesTodayWidgetSaysWhatTheWebSaysTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_after_real_sales_today_and_yesterday_the_figures_are_the_webs_own(): void
    {
        $before = $this->widget();

        Carbon::setTestNow(Carbon::yesterday()->setTime(12, 0));
        $this->sell('50', []);
        Carbon::setTestNow();
        $this->sell('125', ['deposit' => '1000']);

        $today = Carbon::today()->toDateString();
        $yesterday = Carbon::yesterday()->toDateString();
        $after = $this->widget();

        $this->assertSame(0, bccomp($after['total'], SalesMetrics::invoiceTotal($today, $today), 4), '⛔ আজকের মোট ওয়েবের "আজকের বিক্রয়" নয়।');
        $this->assertSame(0, bccomp($after['yesterday'], SalesMetrics::invoiceTotal($yesterday, $yesterday), 4), '⛔ গতকাল ওয়েবের সংজ্ঞায় নয়।');
        $this->assertSame(0, bccomp($after['collections'], SalesMetrics::collectionTotal($today, $today), 4), '⛔ আদায় ওয়েবের "আজ আদায়" নয়।');
        $this->assertSame(SalesInvoice::query()->posted()->whereBetween('trx_date', [$today, $today])->count(), $after['invoices'],
            '⛔ বিলের সংখ্যা খাতায় বসা বিলের গোনা নয়।');

        // ⓘ উৎস দুজনেই ভুল হলে উপরেরটা অন্ধ — তাই সত্যিকারের বদলটাও
        $this->assertSame(0, bccomp(bcsub($after['total'], $before['total'], 4), '1250', 4), '⛔ আজকের ১,২৫০ টাকার বিল মোটে এল না।');
        $this->assertSame(0, bccomp(bcsub($after['yesterday'], $before['yesterday'], 4), '500', 4), '⛔ গতকালের ৫০০ টাকার বিল গতকালে এল না।');
        $this->assertSame($before['invoices'] + 1, $after['invoices'], '⛔ আজ একটা বিল — সংখ্যা এক বাড়েনি।');
        $this->assertSame(1, bccomp(bcsub($after['collections'], $before['collections'], 4), '0', 4), '⛔ ১,০০০ টাকা জমা আদায়ে এল না।');

        foreach (['total', 'collections', 'yesterday'] as $money) {
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{4}$/', $after[$money], "⛔ {$money}: টাকা bcmath-এর চার দশমিকের স্ট্রিং নয়।");
        }
        $this->assertSame('/sales/invoices?view=today', $after['link']);
        // ⛔ সময়ের ছাপ ভিতরে নয় — দরজা উত্তরের হ্যাশে ETag বানায়, ছাপ থাকলে কখনো 304 হত না (দরজার নিজের asOf আছে)
        $this->assertArrayNotHasKey('as_of', $after, '⛔ উইজেটের ভিতরে সময়ের ছাপ — দরজার ETag কখনো মিলবে না।');

        // ⓘ শতাংশ — আলাদা পথে গোনা (ভাসমান সংখ্যা), bcmath-এর সূত্র নয়
        $expected = number_format(((float) $after['total'] - (float) $after['yesterday']) / (float) $after['yesterday'] * 100, 1, '.', '');
        $this->assertSame($expected, $after['change_percent'], '⛔ গতকালের তুলনার শতাংশ ভুল।');
    }

    public function test_the_change_carries_its_sign_and_is_null_when_yesterday_sold_nothing(): void
    {
        $this->assertSame('12.2', SalesTodaySummary::changePercent('112.2000', '100.0000'), '⛔ বাড়া শতাংশ ভুল।');
        $this->assertSame('-10.0', SalesTodaySummary::changePercent('90.0000', '100.0000'), '⛔ কমাটা বাড়া দেখাল — চিহ্ন উল্টো।');
        $this->assertSame('33.3', SalesTodaySummary::changePercent('400.0000', '300.0000'));
        $this->assertSame('-66.7', SalesTodaySummary::changePercent('100.0000', '300.0000'), '⛔ অর্ধেক শূন্য থেকে দূরে গোল হয়নি।');
        $this->assertSame('0.0', SalesTodaySummary::changePercent('100.0000', '100.0000'));
        $this->assertNull(SalesTodaySummary::changePercent('1250.0000', '0.0000'), '⛔ গতকাল শূন্য — শতাংশ null হওয়ার কথা।');
        $this->assertNull(SalesTodaySummary::changePercent('0.0000', '0'), '⛔ দুই দিনই শূন্য — শতাংশ null হওয়ার কথা।');

        // ⓘ আসল উইজেটেও: গতকাল যা আছে তাই, আর শূন্য হলে null
        $data = $this->widget();
        if (bccomp($data['yesterday'], '0', 4) === 0) {
            $this->assertNull($data['change_percent'], '⛔ গতকাল শূন্য, তবু উইজেট শতাংশ বলল।');
        } else {
            $this->assertNotNull($data['change_percent']);
        }
    }

    public function test_the_header_branch_narrows_the_figures(): void
    {
        $branches = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->pluck('id')->all();
        $this->assertGreaterThanOrEqual(2, count($branches), 'প্রস্তুতিটাই ভুল — দুই শাখা নেই।');
        $this->owner->forceFill(['view_all_branches' => false])->save();

        $before = [];
        foreach ($branches as $id) {
            $before[$id] = $this->widget($id);
        }

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $sale = $this->sell('125', []);
        $sold = (int) $sale['invoice']->fresh()->branch_id;
        $this->assertContains($sold, $branches, 'প্রস্তুতিটাই ভুল — বিলটা কোম্পানির কোনো শাখায় নেই।');
        $other = collect($branches)->first(fn (int $id) => $id !== $sold);

        $here = $this->widget($sold);
        $this->assertSame(0, bccomp(bcsub($here['total'], $before[$sold]['total'], 4), '1250', 4), '⛔ নিজের শাখায় বিলটা এল না — দাবি অন্ধ।');
        $this->assertSame($before[$sold]['invoices'] + 1, $here['invoices']);

        $there = $this->widget($other);
        $today = Carbon::today()->toDateString();
        $this->assertSame(0, bccomp($there['total'], $before[$other]['total'], 4), '⛔ অন্য শাখা হেডারে, তবু এই শাখার বিল মোটে এল।');
        $this->assertSame($before[$other]['invoices'], $there['invoices'], '⛔ অন্য শাখা হেডারে, তবু এই শাখার বিল গোনা হলো।');
        $this->assertSame(0, bccomp($there['total'], SalesMetrics::invoiceTotal($today, $today), 4), '⛔ শাখা-ছাঁকা মোট ওয়েবের নয়।');
    }

    public function test_through_the_door_the_widget_is_there_with_the_key_and_gone_without_it(): void
    {
        $door = $this->phone($this->owner)->getJson('/api/v1/widgets/summary')->assertOk()->json('widgets.sales_today');
        $this->assertNotNull($door, '⛔ চাবি থাকতেও দরজায় "আজকের বিক্রি" নেই।');
        $this->assertSame($this->widget(), $door, '⛔ দরজা আর উইজেট আলাদা কথা বলে।');

        $someone = User::query()->whereKeyNot($this->owner->id)->get()->first(fn (User $u) => ! $u->can('sales.invoice.view'));
        $this->assertNotNull($someone, 'প্রস্তুতিটাই ভুল — চাবি-ছাড়া কেউ নেই।');
        $widgets = $this->phone($someone)->getJson('/api/v1/widgets/summary')->assertOk()->json('widgets');
        $this->assertArrayNotHasKey('sales_today', $widgets, '⛔ বিক্রি দেখার চাবি ছাড়াই আজকের বিক্রি গেল।');
    }

    // ── যন্ত্রপাতি ──

    /** উইজেট, হেডারে একটা শাখা বেছে (null = যেমন আছে) */
    private function widget(?int $branchId = null): array
    {
        if ($branchId !== null) {
            CompanyContext::set($this->company->id, $branchId);
            $this->owner->forceFill(['current_branch_id' => $branchId])->save();
        }
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner->fresh());
        app(DataScope::class)->forget();

        return app(SalesTodaySummary::class)->data($this->owner->fresh());
    }

    /** ১০ টাকা দরে বিস্কুট — `qty` × ১০ টাকার খাতায় বসা বিল */
    private function sell(string $qty, array $extra): array
    {
        $this->actingAs($this->owner->fresh());
        $shop = Customer::query()->create(['company_id' => $this->company->id, 'branch_id' => CompanyContext::branchId(),
            'code' => 'ST-'.uniqid(), 'name_en' => 'Today shop '.uniqid(), 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
            'credit_limit' => '1000000']);

        return app(DirectSaleService::class)->complete(
            ['customer_id' => $shop->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1']
                + ($extra ?: ['payment_term' => 'credit', 'credit_period_days' => 15]),
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => $qty, 'rate' => '10', 'free_qty' => '0']],
        );
    }

    private function phone(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }
}
