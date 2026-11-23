<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Services\DataScope;
use App\Core\Services\DealerScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\DealerBindingService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ গ্রাহকের তালিকায় একসাথে কাজের বার, ধাপ ২ — মালিক, ১৯ নভেম্বর ২০২৬ (নমুনা Q2W6mM3S v3; [[BulkRun]], [[x-ui.bulk-form]])।
 *
 * ⓘ গ্রাহক মাস্টার, খসড়া নয় — তাই বারে কেবল "ছাপুন · Excel" (রপ্তানির চাবি থাকলে), বাছা সারির, তালিকার নিজের প্রশ্নে।
 * ⛔ দাবিগুলো: তিন ABOS রূপে ঘর আর বার, SAP (`tiles`) আর Dynamic-এ কিছুই নয়; বাছা রপ্তানিতে কেবল বাছা সারি — অন্য শাখা,
 *   অন্য কোম্পানি আর বিক্রয়কর্মীর দেয়ালের বাইরের ডিলার হাতে বানানো id দিয়েও নয়; রপ্তানির চাবি ছাড়া বারও নেই, ঘরও নেই।
 */
final class TheCustomerListPrintsAndExportsOnlyThePickedRowsTest extends TestCase
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
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->mine = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->theirs = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    public function test_the_abos_looks_get_the_pick_column_and_the_bar_and_sap_and_dynamic_do_not(): void
    {
        $this->customer('ZBK-ONE', $this->mine);

        foreach (['rose', 'navy', 'blue'] as $look) {
            $html = $this->listAs($this->owner, $look);
            $this->assertStringContainsString('form="customer-bulk"', $html, "⛔ {$look}: সারিতে টিক-বাক্স নেই।");
            $this->assertMatchesRegularExpression('/<form[^>]*id="customer-bulk"[^>]*data-bulk-form/', $html, "⛔ {$look}: বার নেই।");
            $this->assertStringContainsString('data-bulk-export="print"', $html, "⛔ {$look}: \"ছাপুন\" নেই।");
            $this->assertStringContainsString('data-bulk-export="xlsx"', $html, "⛔ {$look}: \"Excel\" নেই।");
            // ⓘ মাস্টার — পাঠানো/বাতিল/অনুমোদনের বোতাম নয়
            $this->assertStringNotContainsString('data-bulk-do=', $html, "⛔ {$look}: মাস্টারে কাগজের কাজের বোতাম।");
        }

        foreach (['tiles', 'dynamic'] as $look) {
            $html = $this->listAs($this->owner, $look);
            $this->assertStringNotContainsString('customer-bulk', $html, "⛔ {$look}: SAP/Dynamic-এ বার বা টিক-বাক্স বসল।");
            $this->assertStringNotContainsString('data-bulk-pick', $html, "⛔ {$look}: SAP/Dynamic-এ টিক-বাক্স বসল।");
        }
    }

    public function test_without_the_export_key_there_is_no_bar_and_no_pick_column(): void
    {
        $this->customer('ZBK-ONE', $this->mine);
        $clerk = $this->clerk(['customer.view']);

        $html = $this->listAs($clerk, 'blue');
        $this->assertStringContainsString('ZBK-ONE', $html, 'প্রস্তুতিটাই ভুল — কর্মী তালিকাটা দেখেন না।');
        $this->assertStringNotContainsString('customer-bulk', $html, '⛔ রপ্তানির চাবি ছাড়া একসাথে কাজের বার বসল।');
        $this->assertStringNotContainsString('data-bulk-export', $html, '⛔ রপ্তানির চাবি ছাড়া "ছাপুন · Excel"।');

        // ⓘ ঠিকানায় লিখেও নয় — রপ্তানির দরজা নিজেই চাবি দেখে ([[ExportListing]])
        $this->get(route('customer.index', ['export' => 'csv', 'picked' => 1, 'ids' => [1]]))->assertForbidden();
    }

    public function test_the_picked_export_has_only_the_picked_rows_and_nothing_from_another_branch_or_company(): void
    {
        $a = $this->customer('ZBK-PICK-A', $this->mine);
        $b = $this->customer('ZBK-PICK-B', $this->mine);
        $this->customer('ZBK-LEFT', $this->mine);
        $far = $this->customer('ZBK-FAR', $this->theirs);
        $other = $this->otherCompanyCustomer('ZBK-ELSEWHERE');

        $clerk = $this->clerk(['customer.view', 'system_admin.list.export']);
        $this->listAs($clerk, 'blue');

        $csv = $this->csv(['ids' => [$a->id, $b->id, $far->id, $other->id]]);
        $this->assertStringContainsString('ZBK-PICK-A', $csv);
        $this->assertStringContainsString('ZBK-PICK-B', $csv);
        $this->assertStringNotContainsString('ZBK-LEFT', $csv, '⛔ বাছা হয়নি এমন সারি ফাইলে এল।');
        $this->assertStringNotContainsString('ZBK-FAR', $csv, '⛔ অন্য শাখার গ্রাহক হাতে বানানো id দিয়ে ফাইলে এল।');
        $this->assertStringNotContainsString('ZBK-ELSEWHERE', $csv, '⛔ অন্য কোম্পানির গ্রাহক ফাইলে এল।');

        // ⓘ টিক-বাক্সের কলাম ফাইলে নয়
        $this->assertStringNotContainsString((string) __('core.bulk.pick'), $csv, '⛔ ফাইলে "বাছুন" কলাম।');

        // ⓘ বাছাই ছাড়া রপ্তানি আগের মতোই গোটা তালিকা
        $all = (string) $this->get(route('customer.index', ['export' => 'csv']))->assertOk()->getContent();
        $this->assertStringContainsString('ZBK-LEFT', $all, 'বাছাই ছাড়া রপ্তানি গোটা তালিকা দেয়নি।');
    }

    public function test_the_picked_export_keeps_the_salesmans_dealer_wall(): void
    {
        $ownDealer = $this->customer('ZBK-OWN', $this->mine);
        $notMine = $this->customer('ZBK-NOT-MINE', $this->mine);

        app(SettingsService::class)->set(DealerScope::SWITCH, true);
        $salesman = $this->clerk(['customer.view', 'system_admin.list.export', DealerScope::OWN]);
        app(DealerBindingService::class)->bind((int) $salesman->id, [(int) $ownDealer->id], now()->toDateString());
        $this->forget();

        $html = $this->listAs($salesman, 'blue');
        $this->assertStringContainsString('ZBK-OWN', $html, 'প্রস্তুতিটাই ভুল — নিজের ডিলার তালিকায় নেই।');
        $this->assertStringNotContainsString('ZBK-NOT-MINE', $html, 'প্রস্তুতিটাই ভুল — দেয়াল চালু হয়নি।');

        $csv = $this->csv(['ids' => [$ownDealer->id, $notMine->id]]);
        $this->assertStringContainsString('ZBK-OWN', $csv);
        $this->assertStringNotContainsString('ZBK-NOT-MINE', $csv, '⛔ দেয়ালের বাইরের ডিলার বাছা রপ্তানিতে এল।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $query */
    private function csv(array $query): string
    {
        return (string) $this->get(route('customer.index', ['export' => 'csv', 'picked' => 1, ...$query]))
            ->assertOk()->getContent();
    }

    private function listAs(User $user, string $look): string
    {
        $user->forceFill(['ui' => $look])->save();
        $this->app['auth']->forgetGuards();
        $this->forget();
        $this->actingAs($user->fresh());

        return (string) $this->get(route('customer.index'))->assertOk()->getContent();
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
        app(DealerScope::class)->forget();
        app(\App\Core\Services\PermissionOverrides::class)->forget();
        CompanyContext::set($this->company->id, $this->mine->id);
    }

    private function customer(string $code, Branch $branch): Customer
    {
        return Customer::query()->create(['company_id' => $this->company->id, 'code' => $code,
            'name_en' => 'Shop '.$code, 'branch_id' => $branch->id, 'is_active' => true]);
    }

    private function otherCompanyCustomer(string $code): Customer
    {
        $other = Company::query()->where('code', 'FMART')->firstOrFail();

        return CompanyContext::forCompany($other->id, fn () => Customer::query()->create(['company_id' => $other->id, 'code' => $code,
            'name_en' => 'Shop '.$code, 'branch_id' => $other->defaultBranch()?->id, 'is_active' => true]));
    }
}
