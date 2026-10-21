<?php

declare(strict_types=1);

namespace Tests\Feature\Widgets;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Widgets\CollectionsTodaySummary;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ আজকের আদায়ের উইজেট — টাকা কোথায় ঢুকল সেই ধরে ভাগ ([[CollectionsTodaySummary]]; মালিকের আদেশ, ২০ অক্টোবর ২০২৬)।
 *
 * দাবি: নগদ খাতে ঢোকা আদায় নগদে, ব্যাংকে ঢোকা ব্যাংকে, বিকাশে ঢোকা MFS-এ; ভাগগুলোর যোগ হুবহু মোট; মোট ওয়েবের একই সংখ্যা
 * ([[SalesMetrics::collectionTotal()]], ফোনের আজকের পাতা); হেডারের শাখা ভাগ আর মোট দুটোই ছাঁকে; দরজায় চাবি থাকলে উইজেট আছে,
 * না থাকলে নেই। আদায়গুলো আসল সেবায় বসানো আর নিশ্চিত ([[CollectionService]])।
 */
final class TheCollectionsTodayWidgetSplitsByWhereTheMoneyLandedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $home;

    private User $owner;

    private Customer $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->home = $this->company->defaultBranch();
        CompanyContext::set($this->company->id, $this->home->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $dealer = app(CustomerService::class)->create([
            'name_en' => 'Widget Traders', 'name_bn' => 'উইজেট ট্রেডার্স', 'credit_limit' => '0', 'credit_days' => 30,
        ]);
        $this->dealer = $dealer->fresh();
    }

    public function test_cash_bank_and_mfs_each_land_in_their_own_part_and_the_parts_make_the_web_total(): void
    {
        $bank = app(AccountService::class)->create(['name_en' => 'Widget Bank Current', 'parent_id' => $this->mother(StandardChart::BANK)->id]);
        $bkash = app(AccountService::class)->create(['name_en' => 'Widget bKash Merchant', 'parent_id' => $this->mother(StandardChart::MOBILE_MONEY)->id]);
        $this->assertTrue($bank->fresh()->isBank() && $bkash->fresh()->isMfs(), 'প্রস্তুতিটাই ভুল — ব্যাংক আর MFS খাত নিজের ধরন পায়নি।');

        $before = $this->widget();

        $this->collect('100', null, 'cash', null);
        $this->collect('250', $bank->id, 'bank_transfer', 'WGT-BANK-0001');
        $this->collect('40', $bkash->id, 'bkash', 'WGT-MFS-0001');

        $after = $this->widget();

        $this->assertMoney('100', bcsub($after['cash'], $before['cash'], 4), '⛔ নগদ খাতে ঢোকা আদায় নগদের ভাগে নেই।');
        $this->assertMoney('250', bcsub($after['bank'], $before['bank'], 4), '⛔ ব্যাংকে ঢোকা আদায় ব্যাংকের ভাগে নেই।');
        $this->assertMoney('40', bcsub($after['mfs'], $before['mfs'], 4), '⛔ বিকাশে ঢোকা আদায় MFS-এর ভাগে নেই।');
        $this->assertMoney('390', bcsub($after['total'], $before['total'], 4), '⛔ তিন আদায়ের পরে মোট ৩৯০ বাড়েনি।');

        $this->assertPartsMakeTheTotal($after);

        $today = now()->toDateString();
        $this->assertMoney(SalesMetrics::collectionTotal($today, $today), $after['total'], '⛔ উইজেটের মোট ওয়েবের আদায়ের সংখ্যা নয়।');

        $door = $this->as($this->owner)->getJson('/api/v1/dashboard/today')->assertOk()->json('collections.amount');
        $this->assertNotNull($door, 'প্রস্তুতিটাই ভুল — ফোনের আজকের পাতায় আদায় নেই।');
        $this->assertMoney((string) $door, $after['total'], '⛔ উইজেট আর ফোনের আজকের পাতা আলাদা আদায় বলে।');

        $this->assertArrayNotHasKey('as_of', $after, '⛔ উত্তরে ঘড়ি — দরজার ETag কখনো মিলবে না।');
        $this->assertStringStartsWith('/', $after['link']);
        $this->assertStringContainsString('from='.$today, $after['link']);
    }

    public function test_the_header_branch_narrows_the_parts_and_the_total(): void
    {
        $other = Branch::acrossAllCompanies()->where('company_id', $this->company->id)->whereKeyNot($this->home->id)->firstOrFail();

        // ⓘ মালিক হেডারে একটা শাখা বেছেছেন — "সব শাখা" থাকলে দেয়ালের দাবিটাই ফাঁকা
        $this->owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $this->home->id])->save();
        $this->owner = $this->owner->fresh();
        $this->actingAs($this->owner);
        CompanyContext::set($this->company->id, $this->home->id);

        $before = $this->widget();

        $here = $this->collect('70', null, 'cash', null);
        $there = $this->collect('30', null, 'cash', null);
        DB::table('sal_collections')->where('id', $there->id)->update(['branch_id' => $other->id]);
        $this->assertSame($this->home->id, (int) DB::table('sal_collections')->where('id', $here->id)->value('branch_id'),
            'প্রস্তুতিটাই ভুল — প্রথম আদায় হেডারের শাখার নয়।');

        $after = $this->widget();

        $this->assertMoney('70', bcsub($after['cash'], $before['cash'], 4), '⛔ অন্য শাখার আদায় হেডারের শাখার নগদে ঢুকেছে।');
        $this->assertMoney('70', bcsub($after['total'], $before['total'], 4), '⛔ অন্য শাখার আদায় হেডারের শাখার মোটে ঢুকেছে।');
        $this->assertPartsMakeTheTotal($after);
    }

    public function test_the_door_carries_the_widget_only_for_the_collections_key(): void
    {
        $this->collect('100', null, 'cash', null);

        $widget = $this->as($this->owner)->getJson('/api/v1/widgets/summary?keys=collections_today')->assertOk()->json('widgets.collections_today');
        $this->assertNotNull($widget, '⛔ আদায়ের চাবি থাকা মালিকের উত্তরে আজকের আদায় নেই।');
        foreach (['total', 'cash', 'bank', 'mfs', 'link'] as $field) {
            $this->assertArrayHasKey($field, $widget, "⛔ দরজার উত্তরে {$field} নেই।");
        }
        $today = now()->toDateString();
        $this->assertMoney(SalesMetrics::collectionTotal($today, $today), $widget['total'], '⛔ দরজার মোট ওয়েবের আদায় নয়।');
        $this->assertPartsMakeTheTotal($widget);

        $someone = User::query()->whereKeyNot($this->owner->id)->get()->first(fn (User $u) => ! $u->can('sales.collection.view'));
        $this->assertNotNull($someone, 'প্রস্তুতিটাই ভুল — আদায়ের চাবি-ছাড়া কেউ নেই।');

        $widgets = $this->as($someone)->getJson('/api/v1/widgets/summary')->assertOk()->json('widgets');
        $this->assertArrayNotHasKey('collections_today', $widgets, '⛔ আদায়ের চাবি ছাড়াই আজকের আদায় গেল।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function widget(): array
    {
        return app(CollectionsTodaySummary::class)->data($this->owner);
    }

    private function collect(string $amount, ?int $accountId, string $instrument, ?string $slip): Collection
    {
        $service = app(CollectionService::class);
        $draft = $service->create(array_filter([
            'customer_id' => $this->dealer->id, 'trx_date' => now()->toDateString(), 'amount' => $amount,
            'account_id' => $accountId, 'instrument' => $instrument, 'instrument_no' => $slip,
        ], fn ($v) => $v !== null), []);

        return $service->confirm($draft->fresh());
    }

    /** @param  array<string, mixed>  $w */
    private function assertPartsMakeTheTotal(array $w): void
    {
        $sum = bcadd(bcadd(bcadd($w['cash'], $w['bank'], 4), $w['mfs'], 4), (string) ($w['other'] ?? '0'), 4);
        $this->assertMoney($w['total'], $sum, '⛔ নগদ + ব্যাংক + MFS (+ অন্য) মোটের সমান নয়।');
        if (array_key_exists('other', $w)) {
            $this->assertNotSame(0, bccomp((string) $w['other'], '0', 4), '⛔ "অন্য" শূন্য অথচ উত্তরে এসেছে।');
        }
    }

    private function assertMoney(string $expected, string $actual, string $message): void
    {
        $this->assertSame(0, bccomp($expected, $actual, 4), "{$message} (চাওয়া {$expected}, পাওয়া {$actual})");
    }

    private function mother(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    private function as(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }
}
