<?php

declare(strict_types=1);

namespace Tests\Feature\Widgets;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\DealerScope;
use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\DealerBinding;
use App\Modules\Customer\Services\DealerBindingService;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনের "বড় বকেয়া" উইজেট — `big_dues` (উইজেটের চুক্তি §৪; মালিকের আদেশ, ২০ অক্টোবর ২০২৬; [[BigDuesSummary]])।
 *
 * দাবি, আসল দরজা দিয়ে (`GET /api/v1/widgets/summary`):
 *  - প্রতিটা সারির জের = ওয়েবে সেই গ্রাহকের পাতার খাতার জের (/customers/{id}, `outstanding`);
 *  - অগ্রিম ঋণাত্মক; `total` = নাগালের কেবল ধনাত্মক জেরের যোগ (অগ্রিম কাটে না);
 *  - ক্রম ওয়েবের বকেয়ার তালিকার — চিহ্নসহ জের, বড়টা আগে — আর সর্বোচ্চ ৮টা সারি;
 *  - মেয়াদ পেরোনো বিল থাকলে `overdue_days` তার দিন, না থাকলে null;
 *  - ⛔ দেয়ালের বিক্রয়কর্মী (সুইচ চালু, নিজের চাবি, সবার চাবি নেই, এক ডিলারে বাঁধা) কেবল সেই ডিলার — একই মানুষ দেয়াল ছাড়া
 *    অন্যজনকেও দেখেন ([[a-door-claim-needs-one-actor-twice]]);
 *  - হেডারে এক শাখা বাছলে অন্য শাখার জের সারিতেও নেই, মোটেও নেই;
 *  - চাবি (`customer.report`) ছাড়া, বা ফোনে গ্রাহক মডিউল বন্ধ থাকলে, উইজেটটাই উত্তরে নেই।
 */
final class TheBigDuesWidgetIsTheLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private int $here;

    private int $serial = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->here = (int) $this->company->defaultBranch()?->id;
        CompanyContext::set($this->company->id, $this->here);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['locale' => 'en', 'current_company_id' => $this->company->id,
            'current_branch_id' => $this->here, 'view_all_branches' => true])->save();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        app(SettingsService::class)->set(PhoneModules::key('customer'), true);

        // ⓘ নিজের ছক — ডেমোর গ্রাহক-খাতা মোছা, যাতে প্রতিটা অঙ্ক এই দাবির নিজের পোস্টিং থেকে
        DB::table('ledger_entries')->where('company_id', $this->company->id)
            ->where('party_type', Customer::drillSourceType())->delete();
    }

    public function test_each_row_is_the_customers_web_ledger_balance_in_the_web_lists_order_capped_at_eight(): void
    {
        $owing = [];
        foreach ([300, 1000, 700, 100, 900, 500, 200, 800, 400, 600] as $amount) {
            $owing[] = $this->owes($this->customer(), $amount.'.0000');
        }
        $ahead = $this->customer();
        $this->advance($ahead, '68.4300');

        $widget = $this->widget($this->owner);
        $this->assertIsArray($widget, '⛔ মালিকের উত্তরে বড় বকেয়ার উইজেটই নেই।');
        $this->assertCount(8, $widget['rows'], '⛔ সারি ৮-এর বেশি বা কম।');

        $balances = array_column($widget['rows'], 'balance');
        $this->assertSame(['1000.0000', '900.0000', '800.0000', '700.0000', '600.0000', '500.0000', '400.0000', '300.0000'], $balances,
            '⛔ ক্রম ওয়েবের বকেয়ার তালিকার নয় (চিহ্নসহ জের, বড়টা আগে)।');

        // ⭐ total = সব ধনাত্মক জের (১০ জন, ৫,৫০০), কেবল দেখানো ৮টা নয়, আর অগ্রিম কাটে না
        $this->assertSame('5500.0000', $widget['total'], '⛔ মোট ধনাত্মক জেরের যোগ নয়।');
        $this->assertSame('/customers/reports/due-list', $widget['link']);
        $this->assertArrayNotHasKey('as_of', $widget, '⛔ উত্তরের ভেতরে সময় — দরজার etag কখনো মিলবে না।');

        foreach ($widget['rows'] as $row) {
            $id = (int) substr($row['link'], strlen('/customers/'));
            $this->assertSame('/customers/'.$id, $row['link']);
            $this->assertSame($row['balance'], $this->webBalance($id), '⛔ সারির জের ওয়েবের খাতার জের নয়: গ্রাহক '.$id);

            $customer = Customer::query()->with(Customer::PLACE)->findOrFail($id);
            $this->assertSame($customer->name_en, $row['customer']['name']);
            $this->assertSame(implode(' · ', array_filter([$row['customer']['name'], $row['customer']['point'], $row['customer']['branch']])),
                $row['customer']['label'], '⛔ নাম · পয়েন্ট · শাখা নয়।');
            $this->assertNotNull($row['customer']['branch'], '⛔ গ্রাহকের শাখা নেই।');
        }
    }

    public function test_an_advance_is_negative_and_the_total_leaves_it_out_and_overdue_days_come_from_the_open_bills(): void
    {
        $late = $this->owes($this->customer(), '1200.0000');
        $fresh = $this->owes($this->customer(), '400.0000');
        $ahead = $this->customer();
        $this->advance($ahead, '68.4300');
        $this->bill($late, '500.0000', now()->subDays(10)->toDateString());

        $widget = $this->widget($this->owner);
        $rows = collect($widget['rows'])->keyBy('link');

        $this->assertSame('-68.4300', $rows['/customers/'.$ahead->id]['balance'] ?? null, '⛔ অগ্রিম ঋণাত্মক নয়, বা সারিতে নেই।');
        $this->assertSame($this->webBalance((int) $ahead->id), '-68.4300', 'প্রস্তুতিটাই ভুল — ওয়েবের খাতায় অগ্রিম নেই।');
        $this->assertSame('/customers/'.$ahead->id, $widget['rows'][2]['link'], '⛔ অগ্রিম তালিকার শেষে নয় (চিহ্নসহ ক্রম)।');
        $this->assertSame('1600.0000', $widget['total'], '⛔ মোটে অগ্রিম কাটা গেল বা যোগ হলো।');

        $this->assertSame(10, $rows['/customers/'.$late->id]['overdue_days'], '⛔ মেয়াদ পেরোনো বিলের দিন নেই।');
        $this->assertNull($rows['/customers/'.$fresh->id]['overdue_days'], '⛔ মেয়াদ পেরোনো কিছু নেই, তবু দিন।');
    }

    public function test_a_walled_salesman_sees_only_his_own_dealer(): void
    {
        $mine = $this->owes($this->customer('BD Own Dealer'), '500.0000');
        $theirs = $this->owes($this->customer('BD Someone Elses Dealer'), '9000.0000');

        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $sales->forceFill(['locale' => 'en', 'current_company_id' => $this->company->id, 'current_branch_id' => $this->here, 'view_all_branches' => true])->save();
        DealerBinding::query()->withoutGlobalScopes()->where('user_id', $sales->id)->delete();
        app(DealerBindingService::class)->bind((int) $sales->id, [(int) $mine->id], now()->toDateString());

        app(SettingsService::class)->set(DealerScope::SWITCH, true);
        CompanyContext::forCompany($this->company->id, function () use ($sales) {
            Role::findByName('salesman', 'web')->revokePermissionTo(Permission::findOrCreate(DealerScope::ALL, 'web'));
            $sales->revokePermissionTo(Permission::findOrCreate(DealerScope::ALL, 'web'));
            Role::findByName('salesman', 'web')->givePermissionTo(Permission::findOrCreate(DealerScope::OWN, 'web'));
        });
        $this->forget();

        $json = $this->widgetJson($sales);
        $this->assertTrue(app(DealerScope::class)->walled($sales->fresh()), 'প্রস্তুতিটাই ভুল — বিক্রয়কর্মী দেয়ালে নেই।');
        $this->assertStringContainsString('BD Own Dealer', $json, '⛔ নিজের ডিলার উইজেটে নেই।');
        $this->assertStringNotContainsString('BD Someone Elses Dealer', $json, '⛔ দেয়ালের বিক্রয়কর্মী অন্যের ডিলার দেখলেন।');
        $this->assertSame('500.0000', json_decode($json, true)['widgets']['big_dues']['total'], '⛔ মোটে অন্যের ডিলারের বকেয়া।');

        // ⓘ একই মানুষ, দেয়াল ছাড়া — অন্যজন তখন দেখা যায়, তাই উপরের অনুপস্থিতি দেয়ালেরই
        CompanyContext::forCompany($this->company->id, fn () => Role::findByName('salesman', 'web')->revokePermissionTo(DealerScope::OWN));
        $this->forget();
        $this->assertStringContainsString('BD Someone Elses Dealer', $this->widgetJson($sales), 'প্রস্তুতিটাই ভুল — দেয়াল ছাড়াও অন্যজন নেই।');
    }

    public function test_the_header_branch_narrows_the_rows_and_the_total(): void
    {
        $there = (int) Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('id', '<>', $this->here)->orderBy('id')->firstOrFail()->id;
        $near = $this->owes($this->customer(), '300.0000');
        $far = $this->owes($this->customer('BD Far Branch Shop'), '800.0000', $there);

        $all = $this->widget($this->owner);
        $this->assertSame('1100.0000', $all['total'], 'প্রস্তুতিটাই ভুল — "সব শাখা"-তে দুজনই নেই।');

        $this->owner->forceFill(['current_branch_id' => $this->here, 'view_all_branches' => false])->save();
        $one = $this->widget($this->owner);
        $links = array_column($one['rows'], 'link');
        $this->assertContains('/customers/'.$near->id, $links);
        $this->assertNotContains('/customers/'.$far->id, $links, '⛔ হেডারে এক শাখা, তবু অন্য শাখার বকেয়া।');
        $this->assertSame('300.0000', $one['total'], '⛔ মোটে অন্য শাখার বকেয়া।');
    }

    public function test_the_widget_is_absent_without_the_key_or_with_the_module_off(): void
    {
        $this->owes($this->customer(), '300.0000');
        $this->assertIsArray($this->widget($this->owner), 'প্রস্তুতিটাই ভুল — মালিকের উত্তরে উইজেট নেই।');

        $nobody = User::factory()->create(['email' => 'bd-nobody@abos.test', 'current_company_id' => $this->company->id, 'is_active' => true, 'locale' => 'en']);
        $nobody->companies()->attach($this->company->id, ['is_active' => true]);
        $this->forget();
        $this->assertFalse($nobody->can('customer.report'), 'প্রস্তুতিটাই ভুল — চাবিহীন মানুষের চাবি আছে।');
        $this->assertNull($this->widget($nobody), '⛔ চাবি ছাড়াই বকেয়ার উইজেট গেল।');

        app(SettingsService::class)->set(PhoneModules::key('customer'), false);
        app()->forgetInstance(SettingsService::class);
        app()->forgetInstance(PhoneModules::class);
        $this->assertNull($this->widget($this->owner), '⛔ ফোনে গ্রাহক মডিউল বন্ধ, তবু উইজেট।');
    }

    // ── সাহায্য ─────────────────────────────────────────────────────────────

    private function widget(User $user): ?array
    {
        return json_decode($this->widgetJson($user), true)['widgets']['big_dues'] ?? null;
    }

    private function widgetJson(User $user): string
    {
        $this->app['auth']->forgetGuards();
        $this->forget();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return (string) $this->getJson('/api/v1/widgets/summary?keys=big_dues')->assertOk()->getContent();
    }

    /** ⭐ ওয়েবের পথ — গ্রাহকের পাতা, মালিক হয়ে, যে জের পাতার মাথায় বসে ([[CustomerController::dueInView()]]) */
    private function webBalance(int $id): string
    {
        $this->app['auth']->forgetGuards();
        $this->forget();

        return (string) $this->actingAs($this->owner->fresh())->get('/customers/'.$id)->assertOk()->viewData('outstanding');
    }

    private function customer(?string $name = null): Customer
    {
        $this->serial++;

        return Customer::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->here,
            'code' => 'BD-'.$this->serial,
            'name_en' => $name ?? 'BD Shop '.$this->serial,
            'is_active' => true,
        ]);
    }

    private function owes(Customer $customer, string $amount, ?int $branch = null): Customer
    {
        app(OpeningBalanceService::class)->forReceivable(Customer::drillSourceType(), (int) $customer->id,
            'OB-BD-'.$customer->id, $amount, now()->toDateString(), $branch ?? $this->here);

        return $customer;
    }

    /** আগাম জমা — প্রাপ্যে ক্রেডিট, গ্রাহকের নামে ([[TheDueListNettedTheAdvancesTest]]-এর একই পোস্টিং) */
    private function advance(Customer $customer, string $amount): void
    {
        $receivable = (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
        $income = (int) Account::query()->postable()->active()->where('type', Account::INCOME)->value('id');

        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: 98000 + (int) $customer->id,
            trxDate: now()->toDateString(), lines: [
                ['account_id' => $income, 'debit' => $amount],
                ['account_id' => $receivable, 'credit' => $amount, 'party_type' => Customer::drillSourceType(), 'party_id' => $customer->id],
            ], branchId: $this->here);
    }

    private function bill(Customer $customer, string $total, string $dueOn): SalesInvoice
    {
        return SalesInvoice::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->here,
            'financial_year_id' => FinancialYear::query()->where('is_current', true)->firstOrFail()->id,
            'document_no' => 'BD-INV-'.$customer->id,
            'customer_id' => $customer->id,
            'trx_date' => $dueOn,
            'due_on' => $dueOn,
            'subtotal' => $total,
            'total' => $total,
            'status' => 'confirmed',
        ]);
    }

    private function forget(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DealerScope::class)->forget();
        app(\App\Core\Services\PermissionOverrides::class)->forget();
        app(\App\Core\Services\DataScope::class)->forget();
        CompanyContext::set($this->company->id, $this->here);
    }
}
