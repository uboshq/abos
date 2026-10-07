<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Contracts\ImportNeedsKeys;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\ImportRunner;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ ইমপোর্ট দিয়ে চাবি ছাড়া খাতায় লেখা যেত — পুরো ERP অডিট, ৬ অক্টোবর ২০২৬ (SystemAdmin ⛔৫)।
 *
 * দরজায় কেবল `system_admin.import.manage` ("সেটিং রক্ষক"-এর ছকে); খোলা জের ইমপোর্ট যেকোনো পোস্টযোগ্য খাতে খাতার খোলা
 * সারি বসাত — হিসাবের কোনো চাবি ছাড়া।
 *
 * দাবি:
 *   · প্রতিটা ঘোষিত ইমপোর্টার নিজের চাবি বলে, আর প্রতিটা চাবি কোনো মডিউলে সত্যিই আছে
 *   · একই মানুষ — কেবল import.manage → খোলা জের তালিকায় নেই, চালালে ফেরত; হিসাবের চাবি পেলে → আছে
 *   · মডিউল বন্ধ থাকলে তার ইমপোর্ট মালিকের তালিকাতেও নয়
 */
final class AnImportOpenedTheBooksWithoutTheirKeysTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    public function test_every_importer_names_its_own_real_keys(): void
    {
        $known = collect(app(ModuleRegistry::class)->all())->flatMap(fn ($m) => $m->permissions)->all();

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->imports as $key => $class) {
                $this->assertTrue(is_subclass_of($class, ImportNeedsKeys::class), "⛔ ইমপোর্ট {$key} নিজের চাবি বলে না।");
                $this->assertNotSame([], $class::requiredPermissions(), "⛔ ইমপোর্ট {$key}-এর চাবি খালি।");
                foreach ($class::requiredPermissions() as $permission) {
                    $this->assertContains($permission, $known, "⛔ ইমপোর্ট {$key}-এর চাবি {$permission} কোনো মডিউলে নেই।");
                }
            }
        }
    }

    public function test_the_import_key_alone_does_not_open_the_books(): void
    {
        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->give($clerk, ['system_admin.import.manage']);

        $this->actingAs($clerk->fresh());
        $this->assertArrayNotHasKey('opening_balance', app(ImportRunner::class)->available(), '⛔ কেবল ইমপোর্টের চাবিতে খোলা জের ইমপোর্ট খোলা।');
        $this->post(route('system_admin.import.store'), ['kind' => 'opening_balance'])->assertSessionHasErrors('kind');
        $this->get(route('system_admin.import.template', 'opening_balance'))->assertNotFound();

        // ⭐ একই মানুষ, এবার হিসাবের চাবিও
        $this->give($clerk, ['accounts.coa.manage', 'accounts.voucher.create']);
        $this->actingAs($clerk->fresh());
        $this->assertArrayHasKey('opening_balance', app(ImportRunner::class)->available());
        $this->get(route('system_admin.import.template', 'opening_balance'))->assertOk();
    }

    public function test_a_switched_off_module_offers_no_import_even_to_the_owner(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->assertArrayHasKey('customer', app(ImportRunner::class)->available());

        app(SettingsService::class)->set('customer.enabled', false);
        app()->forgetInstance(SettingsService::class);

        $this->assertArrayNotHasKey('customer', app(ImportRunner::class)->available(), '⛔ বন্ধ মডিউলের ইমপোর্ট তালিকায়।');
    }

    /** @param  list<string>  $keys */
    private function give(User $user, array $keys): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
