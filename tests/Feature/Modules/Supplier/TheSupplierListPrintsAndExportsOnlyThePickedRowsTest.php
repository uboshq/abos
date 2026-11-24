<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ সরবরাহকারীর তালিকায় একসাথে কাজের বার, ধাপ ২ — মালিক, ১৯ নভেম্বর ২০২৬ (নমুনা Q2W6mM3S v3; [[BulkRun]], [[x-ui.bulk-form]])।
 *
 * ⓘ সরবরাহকারী মাস্টার, খসড়া নয় — বারে কেবল "ছাপুন · Excel" (রপ্তানির চাবি থাকলে), বাছা সারির, তালিকার নিজের প্রশ্নে।
 * ⛔ দাবিগুলো: তিন ABOS রূপে ঘর আর বার, SAP (`tiles`) আর Dynamic-এ কিছুই নয়; বাছা রপ্তানিতে কেবল বাছা সারি — অন্য শাখা আর
 *   অন্য কোম্পানির সরবরাহকারী হাতে বানানো id দিয়েও নয়; রপ্তানির চাবি ছাড়া বারও নেই, ঘরও নেই।
 */
final class TheSupplierListPrintsAndExportsOnlyThePickedRowsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Branch $mine;

    private Branch $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->mine = $this->company->defaultBranch();
        $this->theirs = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->whereKeyNot($this->mine->id)->firstOrFail();
        CompanyContext::set($this->company->id, $this->mine->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    public function test_the_abos_looks_get_the_pick_column_and_the_bar_and_sap_and_dynamic_do_not(): void
    {
        $this->supplier('Zbk One Mill', $this->mine);

        foreach (['rose', 'navy', 'blue'] as $look) {
            $html = $this->listAs($this->owner, $look);
            $this->assertStringContainsString('form="supplier-bulk"', $html, "⛔ {$look}: সারিতে টিক-বাক্স নেই।");
            $this->assertMatchesRegularExpression('/<form[^>]*id="supplier-bulk"[^>]*data-bulk-form/', $html, "⛔ {$look}: বার নেই।");
            $this->assertStringContainsString('data-bulk-export="print"', $html, "⛔ {$look}: \"ছাপুন\" নেই।");
            $this->assertStringContainsString('data-bulk-export="xlsx"', $html, "⛔ {$look}: \"Excel\" নেই।");
            $this->assertStringNotContainsString('data-bulk-do=', $html, "⛔ {$look}: মাস্টারে কাগজের কাজের বোতাম।");
        }

        foreach (['tiles', 'dynamic'] as $look) {
            $html = $this->listAs($this->owner, $look);
            $this->assertStringNotContainsString('supplier-bulk', $html, "⛔ {$look}: SAP/Dynamic-এ বার বা টিক-বাক্স বসল।");
            $this->assertStringNotContainsString('data-bulk-pick', $html, "⛔ {$look}: SAP/Dynamic-এ টিক-বাক্স বসল।");
        }
    }

    public function test_without_the_export_key_there_is_no_bar_and_no_pick_column(): void
    {
        $this->supplier('Zbk One Mill', $this->mine);
        $clerk = $this->clerk(['supplier.view']);

        $html = $this->listAs($clerk, 'blue');
        $this->assertStringContainsString('Zbk One Mill', $html, 'প্রস্তুতিটাই ভুল — কর্মী তালিকাটা দেখেন না।');
        $this->assertStringNotContainsString('supplier-bulk', $html, '⛔ রপ্তানির চাবি ছাড়া একসাথে কাজের বার বসল।');
        $this->assertStringNotContainsString('data-bulk-export', $html, '⛔ রপ্তানির চাবি ছাড়া "ছাপুন · Excel"।');

        $this->get(route('supplier.index', ['export' => 'csv', 'picked' => 1, 'ids' => [1]]))->assertForbidden();
    }

    public function test_the_picked_export_has_only_the_picked_rows_and_nothing_from_another_branch_or_company(): void
    {
        $a = $this->supplier('Zbk Pick Alpha', $this->mine);
        $b = $this->supplier('Zbk Pick Bravo', $this->mine);
        $this->supplier('Zbk Left Behind', $this->mine);
        $far = $this->supplier('Zbk Far Mill', $this->theirs);
        $other = $this->otherCompanySupplier('Zbk Elsewhere Mill');

        $clerk = $this->clerk(['supplier.view', 'system_admin.list.export']);
        $this->listAs($clerk, 'blue');

        $csv = (string) $this->get(route('supplier.index', ['export' => 'csv', 'picked' => 1,
            'ids' => [$a->id, $b->id, $far->id, $other->id]]))->assertOk()->getContent();
        $this->assertStringContainsString('Zbk Pick Alpha', $csv);
        $this->assertStringContainsString('Zbk Pick Bravo', $csv);
        $this->assertStringNotContainsString('Zbk Left Behind', $csv, '⛔ বাছা হয়নি এমন সারি ফাইলে এল।');
        $this->assertStringNotContainsString('Zbk Far Mill', $csv, '⛔ অন্য শাখার সরবরাহকারী হাতে বানানো id দিয়ে ফাইলে এল।');
        $this->assertStringNotContainsString('Zbk Elsewhere Mill', $csv, '⛔ অন্য কোম্পানির সরবরাহকারী ফাইলে এল।');
        $this->assertStringNotContainsString((string) __('core.bulk.pick'), $csv, '⛔ ফাইলে "বাছুন" কলাম।');

        $all = (string) $this->get(route('supplier.index', ['export' => 'csv']))->assertOk()->getContent();
        $this->assertStringContainsString('Zbk Left Behind', $all, 'বাছাই ছাড়া রপ্তানি গোটা তালিকা দেয়নি।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function listAs(User $user, string $look): string
    {
        $user->forceFill(['ui' => $look])->save();
        $this->app['auth']->forgetGuards();
        $this->forget();
        $this->actingAs($user->fresh());

        return (string) $this->get(route('supplier.index'))->assertOk()->getContent();
    }

    /** @param  list<string>  $keys */
    private function clerk(array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'current_branch_id' => $this->mine->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        UserDataScope::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'user_id' => $user->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->mine->id]);
        CompanyContext::forCompany($this->company->id, function () use ($user, $keys): void {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        $this->forget();

        return $user->fresh();
    }

    private function forget(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();
        CompanyContext::set($this->company->id, $this->mine->id);
    }

    private function supplier(string $name, Branch $branch): Supplier
    {
        return app(SupplierService::class)->create(['name_en' => $name, 'branch_id' => $branch->id, 'credit_limit' => 0, 'credit_days' => 0]);
    }

    private function otherCompanySupplier(string $name): Supplier
    {
        $other = Company::query()->where('code', 'FMART')->firstOrFail();

        return CompanyContext::forCompany($other->id, fn () => Supplier::query()->create(['company_id' => $other->id,
            'code' => 'ZBK-ELSE', 'name_en' => $name, 'branch_id' => $other->defaultBranch()?->id, 'is_active' => true]));
    }
}
