<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ পণ্যের তালিকায় একসাথে কাজের বার, ধাপ ২ — মালিক, ১৯ নভেম্বর ২০২৬ (নমুনা Q2W6mM3S v3; [[BulkRun]], [[x-ui.bulk-form]])।
 *
 * ⓘ পণ্য মাস্টার, খসড়া নয় — বারে কেবল "ছাপুন · Excel" (রপ্তানির চাবি থাকলে), বাছা সারির, তালিকার নিজের প্রশ্নে।
 * ⛔ দাবিগুলো: তিন ABOS রূপে ঘর আর বার, SAP (`tiles`) আর Dynamic-এ কিছুই নয়; বাছা রপ্তানিতে কেবল বাছা সারি — হেডারে বাছা
 *   শাখায় বিক্রি না হওয়া পণ্য ([[Product::scopeSoldInViewedBranch()]]) আর অন্য কোম্পানির পণ্য হাতে বানানো id দিয়েও নয়;
 *   রপ্তানির চাবি ছাড়া বারও নেই, ঘরও নেই।
 */
final class TheProductListPrintsAndExportsOnlyThePickedRowsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Branch $mymensingh;

    private Branch $netrakona;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->mymensingh = $this->branch('MMS');
        $this->netrakona = $this->branch('NTK');
        CompanyContext::set($this->company->id, $this->mymensingh->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    public function test_the_abos_looks_get_the_pick_column_and_the_bar_and_sap_and_dynamic_do_not(): void
    {
        $this->product('ZBK-P-ONE');

        foreach (['rose', 'navy', 'blue'] as $look) {
            $html = $this->listAs($this->owner, $look);
            $this->assertStringContainsString('form="product-bulk"', $html, "⛔ {$look}: সারিতে টিক-বাক্স নেই।");
            $this->assertMatchesRegularExpression('/<form[^>]*id="product-bulk"[^>]*data-bulk-form/', $html, "⛔ {$look}: বার নেই।");
            $this->assertStringContainsString('data-bulk-export="print"', $html, "⛔ {$look}: \"ছাপুন\" নেই।");
            $this->assertStringContainsString('data-bulk-export="xlsx"', $html, "⛔ {$look}: \"Excel\" নেই।");
            $this->assertStringNotContainsString('data-bulk-do=', $html, "⛔ {$look}: মাস্টারে কাগজের কাজের বোতাম।");
        }

        foreach (['tiles', 'dynamic'] as $look) {
            $html = $this->listAs($this->owner, $look);
            $this->assertStringNotContainsString('product-bulk', $html, "⛔ {$look}: SAP/Dynamic-এ বার বা টিক-বাক্স বসল।");
            $this->assertStringNotContainsString('data-bulk-pick', $html, "⛔ {$look}: SAP/Dynamic-এ টিক-বাক্স বসল।");
        }
    }

    public function test_without_the_export_key_there_is_no_bar_and_no_pick_column(): void
    {
        $this->product('ZBK-P-ONE');

        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'current_branch_id' => $this->mymensingh->id, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        UserDataScope::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->mymensingh->id]);
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('inventory.product.view', 'web')));

        $html = $this->listAs($clerk, 'blue');
        $this->assertStringContainsString('ZBK-P-ONE', $html, 'প্রস্তুতিটাই ভুল — কর্মী তালিকাটা দেখেন না।');
        $this->assertStringNotContainsString('product-bulk', $html, '⛔ রপ্তানির চাবি ছাড়া একসাথে কাজের বার বসল।');
        $this->assertStringNotContainsString('data-bulk-export', $html, '⛔ রপ্তানির চাবি ছাড়া "ছাপুন · Excel"।');

        $this->get(route('inventory.product.index', ['export' => 'csv', 'picked' => 1, 'ids' => [1]]))->assertForbidden();
    }

    public function test_the_picked_export_has_only_the_picked_rows_and_nothing_from_another_branch_or_company(): void
    {
        $a = $this->product('ZBK-P-ALPHA');
        $b = $this->product('ZBK-P-BRAVO');
        $this->product('ZBK-P-LEFT');
        $far = $this->product('ZBK-P-FAR');
        $far->branches()->sync([$this->netrakona->id]);
        $other = $this->otherCompanyProduct('ZBK-P-ELSE');

        // ⓘ হেডারে ময়মনসিংহ — নেত্রকোনায় বাঁধা পণ্য তালিকায় নেই
        $this->listAs($this->owner, 'blue');
        $this->choose($this->mymensingh->id);
        $html = (string) $this->get(route('inventory.product.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('ZBK-P-FAR', $html, 'প্রস্তুতিটাই ভুল — অন্য শাখার পণ্য তালিকায়।');

        $csv = (string) $this->get(route('inventory.product.index', ['export' => 'csv', 'picked' => 1,
            'ids' => [$a->id, $b->id, $far->id, $other->id]]))->assertOk()->getContent();
        $this->assertStringContainsString('ZBK-P-ALPHA', $csv);
        $this->assertStringContainsString('ZBK-P-BRAVO', $csv);
        $this->assertStringNotContainsString('ZBK-P-LEFT', $csv, '⛔ বাছা হয়নি এমন সারি ফাইলে এল।');
        $this->assertStringNotContainsString('ZBK-P-FAR', $csv, '⛔ অন্য শাখার পণ্য হাতে বানানো id দিয়ে ফাইলে এল।');
        $this->assertStringNotContainsString('ZBK-P-ELSE', $csv, '⛔ অন্য কোম্পানির পণ্য ফাইলে এল।');
        $this->assertStringNotContainsString((string) __('core.bulk.pick'), $csv, '⛔ ফাইলে "বাছুন" কলাম।');

        $all = (string) $this->get(route('inventory.product.index', ['export' => 'csv']))->assertOk()->getContent();
        $this->assertStringContainsString('ZBK-P-LEFT', $all, 'বাছাই ছাড়া রপ্তানি গোটা তালিকা দেয়নি।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function listAs(User $user, string $look): string
    {
        $user->forceFill(['ui' => $look])->save();
        $this->app['auth']->forgetGuards();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();
        CompanyContext::set($this->company->id, $this->mymensingh->id);
        $this->actingAs($user->fresh());

        return (string) $this->get(route('inventory.product.index'))->assertOk()->getContent();
    }

    /** ⓘ হেডারের শাখা বাছা — আসল দরজা দিয়ে ([[EachBranchSellsItsOwnGoodsTest]]-এর একই পথ) */
    private function choose(int $branch): void
    {
        $this->actingAs($this->owner->fresh())
            ->post(route('branch.switch'), ['branch_id' => (string) $branch])
            ->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner);
    }

    private function product(string $code): Product
    {
        return Product::query()->create(['code' => $code, 'name_en' => 'Item '.$code, 'name_bn' => 'Item '.$code,
            'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true]);
    }

    private function otherCompanyProduct(string $code): Product
    {
        $other = Company::query()->where('code', 'FMART')->firstOrFail();

        return CompanyContext::forCompany($other->id, fn () => Product::query()->create(['company_id' => $other->id, 'code' => $code,
            'name_en' => 'Item '.$code, 'name_bn' => 'Item '.$code,
            // ⓘ একক যেকোনো — অন্য কোম্পানির নিজের এককের সারি না থাকতেও পারে
            'unit_id' => Unit::query()->withoutGlobalScopes()->where('code', 'PCS')->value('id'), 'is_active' => true]));
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
