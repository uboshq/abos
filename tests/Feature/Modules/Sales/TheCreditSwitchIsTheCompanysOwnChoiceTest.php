<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\PermissionSyncer;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Services\CreditExposure;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ বাকির সীমার সুইচ — প্রতিষ্ঠানের নিজের বাছাই (মালিকের বিক্রয়-পরিকল্পনা, সংস্করণ ২, ৪ অক্টোবর ২০২৬):
 * *"বাকির সীমা পরম … কোনো প্রতিষ্ঠান সীমার বাইরে বাকি দিতে চাইলে নিজের সেটিংস থেকে 'বাকির সীমা' সুইচ বন্ধ রাখবে"*।
 *
 * দাবি:
 *   চালু (ডিফল্ট) — সীমা পেরোনো কাউন্টারের বিক্রি ফেরে, দেয়াল ছুড়ে দেয়, DO-র হিসাবও "আঁটে না" বলে;
 *   বন্ধ — একই বিক্রি যায়, দেয়াল চুপ, DO-র হিসাব "আঁটে" — সব পথ একই প্রশ্ন করে ([[CreditExposure::isOn()]]);
 *   সুইচ বদলাতে পারেন কেবল সুপার অ্যাডমিন — সেটিংসের চাবি থাকা ব্যবস্থাপক পাঠালেও বদলায় না; সুপার অ্যাডমিনের বদল অডিটে ওঠে।
 */
final class TheCreditSwitchIsTheCompanysOwnChoiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '100'])->save();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->orderBy('id')->firstOrFail();
        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse, sourceType: 'opening', sourceId: $this->product->id,
            floor: '100', date: now()->toDateString(), documentNo: 'TEST-CREDIT-SWITCH',
        );
    }

    public function test_switched_on_the_limit_holds_on_every_path_and_switched_off_it_holds_on_none(): void
    {
        $credit = app(CreditExposure::class);
        $this->assertTrue(app(SettingsService::class)->enabled('customer.credit_limit_enabled'), 'ডিফল্ট চালু নয়।');

        // ── চালু: পরম ─────────────────────────────────────────────────
        $this->assertFalse($credit->check($this->customer, '1000')['fits'], '⛔ সীমা ১০০, বিল ১০০০ — DO-র হিসাব "আঁটে" বলল।');
        try {
            $credit->assertRoom($this->customer, '1000');
            $this->fail('⛔ সুইচ চালু, তবু দেয়াল ছাড়ল।');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->sell()->assertSessionHasErrors();

        // ── বন্ধ: কোনো পথে যাচাই নয় ──────────────────────────────────
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);
        app()->forgetInstance(CreditExposure::class);
        $credit = app(CreditExposure::class);

        $this->assertTrue($credit->check($this->customer, '1000')['fits'], '⛔ সুইচ বন্ধ, তবু DO "টাকার জন্য আটকে" যেত।');
        $credit->assertRoom($this->customer, '1000'); // ⓘ ছুড়ে না দিলেই দাবি পূর্ণ
        $this->sell()->assertSessionHasNoErrors();
    }

    public function test_only_the_super_admin_may_turn_the_switch_and_the_change_is_audited(): void
    {
        // ⓘ সেটিংসের চাবি আছে, সুপার অ্যাডমিন নন — পাঠালেও সুইচ বদলায় না
        $manager = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $manager->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id,
            fn () => $manager->givePermissionTo(Permission::findOrCreate('system_admin.settings.manage', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($manager->fresh()->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE));

        $this->actingAs($manager->fresh())
            ->put(route('system_admin.settings.update'), ['settings' => ['customer.credit_limit_enabled' => '0']]);
        $this->assertTrue($this->fresh()->enabled('customer.credit_limit_enabled'),
            '⛔ সুপার অ্যাডমিন নন, অথচ বাকির সীমা বন্ধ করে দিলেন।');

        // ⓘ সুপার অ্যাডমিন — বদলায়, আর খাতায় ওঠে
        $this->assertTrue($this->owner->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE));
        $this->actingAs($this->owner)
            ->put(route('system_admin.settings.update'), ['settings' => ['customer.credit_limit_enabled' => '0']]);
        $this->assertFalse($this->fresh()->enabled('customer.credit_limit_enabled'),
            '⛔ সুপার অ্যাডমিন সুইচ বন্ধ করতে পারলেন না।');

        $row = Setting::query()->where('company_id', $this->company->id)->where('key', 'customer.credit_limit_enabled')->firstOrFail();
        $this->assertTrue(AuditTrail::query()->where('auditable_type', $row::class)->where('auditable_id', $row->id)
            ->where('user_id', $this->owner->id)->exists(), '⛔ সুইচ বদলের অডিট নেই।');
    }

    /** ⓘ মান নতুন করে পড়া — সেবা এক অনুরোধ থেকে আরেকটায় ক্যাশ রাখে */
    private function fresh(): SettingsService
    {
        app()->forgetInstance(SettingsService::class);

        return app(SettingsService::class);
    }

    private function sell(): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('sales.direct.store'), [
            'own_transport' => '1',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100']],
        ]);
    }
}
