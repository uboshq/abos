<?php

declare(strict_types=1);

namespace Tests\Feature\Widgets;

use App\Core\Services\DealerScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Core\Widgets\WidgetSummaries;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\DealerBinding;
use App\Modules\Customer\Services\DealerBindingService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesTarget;
use App\Modules\Sales\Services\SalesTargetService;
use App\Modules\Sales\Widgets\TargetSummary;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ "লক্ষ্য বনাম অর্জন" উইজেট ওয়েবের লক্ষ্যের পাতার অঙ্কই বলে (৯ নম্বর; a6, ২০ অক্টোবর ২০২৬; [[TargetSummary]])।
 *
 * দাবি (আসল দরজা দিয়ে — `GET /api/v1/widgets/summary?keys=target`):
 *  - মালিক: লক্ষ্য আর অর্জন লক্ষ্যের পাতার স্কোরবোর্ডে যাঁদের লক্ষ্য বসানো তাঁদের যোগের হুবহু, আর পাতায় সেই সারির অঙ্কই ছাপা;
 *    `own: false`; বাকি ১২ দিন (২০ অক্টোবর, আজসহ)।
 *  - দেয়ালের ভিতরের বিক্রয়কর্মী: `own: true`, কেবল নিজের লক্ষ্য আর নিজের ডিলারের অর্জন — আরেক বিক্রয়কর্মীর নয়।
 *  - মাসে লক্ষ্য বসানো নেই: `target: null`, শূন্য নয়।
 */
final class TheTargetWidgetReadsTheTargetPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $sales;

    private User $other;

    private Carbon $month;

    protected function setUp(): void
    {
        parent::setUp();
        // ⓘ দিন স্থির — বাকি দিন ১২ (২০ থেকে ৩১ অক্টোবর)
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        $this->month = Carbon::today()->startOfMonth();

        $this->other = User::factory()->create(['email' => 'other-sr@abos.test', 'current_company_id' => $this->company->id, 'is_active' => true]);
        $this->other->companies()->attach($this->company->id, ['is_active' => true]);

        app(SettingsService::class)->set(DealerScope::SWITCH, true);
        CompanyContext::forCompany($this->company->id, function () {
            $this->other->assignRole('salesman');
            $role = Role::findByName('salesman', 'web');
            $role->givePermissionTo(Permission::findOrCreate(DealerScope::OWN, 'web'));
            $role->givePermissionTo(Permission::findOrCreate('sales.target.view', 'web'));
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // ⓘ ডেমোর বাঁধন মুছে নিজের ছক: রহিম এই বিক্রয়কর্মীর, করিম অন্যজনের
        DealerBinding::query()->withoutGlobalScopes()->whereIn('user_id', [$this->sales->id, $this->other->id])->delete();
        $rahim = Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail();
        $karim = Customer::acrossDealers()->where('name_en', 'Karim Stores')->firstOrFail();
        DealerBinding::query()->withoutGlobalScopes()->where('customer_id', $karim->id)->delete();
        $bindings = app(DealerBindingService::class);
        $bindings->bind((int) $this->sales->id, [(int) $rahim->id], Carbon::today()->toDateString());
        $bindings->bind((int) $this->other->id, [(int) $karim->id], Carbon::today()->toDateString());
        app(DealerScope::class)->forget();

        $this->bill('WT-MINE', $rahim, '1150', '150');
        $this->bill('WT-THEIRS', $karim, '9000', '0');

        app(SalesTargetService::class)->setForMonth($this->month, [$this->sales->id => '50000', $this->other->id => '90000']);

        // ⓘ নকল তালিকা নয় — দরজা মডিউলের নিজের ঘোষণা (`phone_widgets`) থেকেই পায়
        WidgetSummaries::$testing = [];
    }

    protected function tearDown(): void
    {
        WidgetSummaries::$testing = [];
        parent::tearDown();
    }

    public function test_the_owner_sees_the_same_target_and_achievement_as_the_target_page(): void
    {
        $rows = collect(app(SalesTargetService::class)->scoreboard($this->month))->filter(fn (array $r) => $r['target'] !== null);
        $target = Money::round(Money::sumOf($rows, fn (array $r) => $r['target']));
        $achieved = Money::round(Money::sumOf($rows, fn (array $r) => $r['achieved']));

        $widget = $this->widget($this->owner);

        $this->assertSame([
            'month' => '2026-10',
            'target' => $target,
            'achieved' => $achieved,
            'days_left' => 12,
            'own' => false,
            'link' => '/sales/targets',
        ], $widget, '⛔ উইজেট আর লক্ষ্যের পাতা আলাদা অঙ্ক বলে');
        $this->assertGreaterThanOrEqual(0, bccomp($target, '140000.00', 2), 'প্রস্তুতিটাই ভুল — দুইজনের লক্ষ্য বসেনি');

        // ⭐ পাতাতেও সেই অঙ্ক — বিক্রয়কর্মীর সারি
        $mine = $rows->first(fn (array $r) => (int) $r['user']->id === (int) $this->sales->id);
        $this->get(route('sales.target.index', ['month' => '2026-10']))->assertOk()->assertSee(Money::format($mine['achieved']), false);
    }

    public function test_a_walled_salesman_sees_only_his_own_target_and_sale(): void
    {
        $this->assertTrue(app(DealerScope::class)->walled($this->sales->fresh()), 'দৃশ্যটাই বানানো যায়নি — বিক্রয়কর্মী দেয়ালে নেই');

        $byUser = app(SalesTargetService::class)->achievedByUser($this->month, $this->month->copy()->endOfMonth());
        $own = Money::round((string) ($byUser[$this->sales->id] ?? '0'));
        $their = Money::round((string) ($byUser[$this->other->id] ?? '0'));
        $this->assertGreaterThanOrEqual(0, bccomp($own, '1000', 2), 'প্রস্তুতিটাই ভুল — নিজের ডিলারের বিল (ভ্যাট বাদে ১০০০) গোনা হয়নি');
        $this->assertGreaterThanOrEqual(0, bccomp($their, '9000', 2), 'প্রস্তুতিটাই ভুল — অন্যজনের ডিলারের বিল গোনা হয়নি');

        $mine = $this->widget($this->sales);
        $this->assertTrue($mine['own'], '⛔ বিক্রয়কর্মীর ফোনে কোম্পানির যোগ');
        $this->assertSame('50000.00', $mine['target'], '⛔ নিজের লক্ষ্য নয়');
        $this->assertSame($own, $mine['achieved'], '⛔ নিজের অর্জন নয় — অন্যের ডিলারের বিক্রি ঢুকল বা নিজেরটা বাদ');

        $theirs = $this->widget($this->other);
        $this->assertTrue($theirs['own']);
        $this->assertSame('90000.00', $theirs['target'], '⛔ অন্য বিক্রয়কর্মী নিজের লক্ষ্য পাননি');
        $this->assertSame($their, $theirs['achieved'], '⛔ অন্য বিক্রয়কর্মী নিজের অর্জন পাননি');
    }

    public function test_no_target_set_this_month_is_null_not_zero(): void
    {
        SalesTarget::query()->forMonth($this->month)->delete();

        $this->assertNull($this->widget($this->owner)['target'], '⛔ লক্ষ্য নেই, তবু মালিকের উইজেটে একটা অঙ্ক');
        $this->assertNull($this->widget($this->sales)['target'], '⛔ লক্ষ্য নেই, তবু বিক্রয়কর্মীর উইজেটে একটা অঙ্ক');
        $own = app(SalesTargetService::class)->achievedByUser($this->month, $this->month->copy()->endOfMonth())[$this->sales->id] ?? '0';
        $this->assertSame(Money::round((string) $own), $this->widget($this->sales)['achieved'], 'লক্ষ্য না থাকলেও নিজের বিক্রি দেখায়');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function widget(User $user): array
    {
        $this->app['auth']->forgetGuards();
        app(DealerScope::class)->forget();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        $widgets = $this->getJson('/api/v1/widgets/summary?keys=target')->assertOk()->json('widgets');
        $this->assertArrayHasKey('target', $widgets, "প্রস্তুতিটাই ভুল — {$user->email} লক্ষ্যের উইজেট পাননি");

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner);

        return $widgets['target'];
    }

    private function bill(string $no, Customer $customer, string $amount, string $tax): void
    {
        $invoice = SalesInvoice::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('is_current', true)->firstOrFail()->id,
            'document_no' => $no,
            'customer_id' => $customer->id,
            'trx_date' => Carbon::today()->toDateString(),
            'subtotal' => $amount,
            'tax' => $tax,
            'total' => $amount,
            'status' => DocumentStatus::CONFIRMED,
            'created_by' => $this->owner->id,
        ]);

        (new SalesInvoiceLine)->forceFill([
            'line_no' => 1, 'sales_invoice_id' => $invoice->id, 'product_id' => Product::query()->firstOrFail()->id,
            'qty' => '1', 'rate' => $amount, 'amount' => $amount, 'tax' => $tax,
        ])->save();
    }
}
