<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * রিপোর্টের পর্দা আর ফাইলে শাখা ধরে ভাগ, শাখার মোট আর সর্বমোট — মালিকের নির্দেশ,
 * ২৯ সেপ্টেম্বর ২০২৬: *"super admin er sob report branch wise alada kore dekhabe sathe grand total"*।
 *
 * ⭐ একই পর্দা, তিন অবস্থা: "সব শাখা"-তে মালিক → ভাগ আর সর্বমোট; ঐ মালিকই একটা শাখা
 * বাছলে → ভাগ নেই; এক শাখায় আটকানো কর্মী → ভাগ নেই। আর CSV-তে পর্দার একই ভাগ।
 *
 * ⓘ ইঞ্জিনের যোগফলের দাবি [[TheOwnerSeesEveryBranchAndTheGrandTotalTest]]-এ।
 */
final class TheReportScreenShowsEveryBranchAndTheGrandTotalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $mymensingh;

    private Branch $netrakona;

    private User $owner;

    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->mymensingh = $this->branch('MMS');
        $this->netrakona = $this->branch('NTK');
        $this->day = now()->toDateString();

        CompanyContext::set($this->company->id, $this->mymensingh->id);
        $this->actingAs($this->owner);

        [$ours, $theirs] = Customer::query()->orderBy('id')->take(2)->get()->all();
        $this->sell($ours, $this->mymensingh, '700');
        $this->sell($theirs, $this->netrakona, '300');
    }

    public function test_the_owner_on_all_branches_sees_each_branch_its_total_and_the_grand_total(): void
    {
        $this->screen($this->owner)
            ->assertOk()
            ->assertSee($this->mymensingh->name())
            ->assertSee($this->netrakona->name())
            ->assertSee(__('core.report.branch_total'))
            // ⓘ শাখার শিরোনাম-সারি — "সর্বমোট" শব্দটা ভাগ ছাড়াও মোটের সারিতে থাকে, তাই ওটা প্রমাণ নয়
            ->assertSee('scope="colgroup"', false);
    }

    public function test_the_same_owner_choosing_one_branch_sees_no_split(): void
    {
        $this->actingAs($this->owner)->post(route('branch.switch'), ['branch_id' => (string) $this->mymensingh->id]);

        $this->screen($this->owner->fresh())
            ->assertOk()
            ->assertDontSee(__('core.report.branch_total'))
            ->assertDontSee('scope="colgroup"', false);
    }

    public function test_a_clerk_held_to_one_branch_sees_no_split(): void
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'current_branch_id' => $this->mymensingh->id, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true, 'default_branch_id' => $this->mymensingh->id]);
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('sales.report', 'web')));
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->mymensingh->id,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->screen($clerk)
            ->assertOk()
            ->assertDontSee(__('core.report.branch_total'))
            ->assertDontSee('scope="colgroup"', false);
    }

    public function test_the_csv_carries_the_same_split_and_the_grand_total(): void
    {
        $csv = (string) $this->screen($this->owner, ['export' => 'csv'])->assertOk()->getContent();

        $this->assertStringContainsString($this->mymensingh->name(), $csv);
        $this->assertStringContainsString(__('core.report.branch_total').' — '.$this->netrakona->name(), $csv);

        // ⓘ সর্বমোটের সারি ফাইলের শেষ সারি — ভাগ ছাড়া ফাইলে কোনো মোটের সারিই থাকে না
        $lines = array_values(array_filter(preg_split('/\R/', trim($csv)) ?: []));
        $this->assertStringContainsString(__('core.report.grand_total'), (string) end($lines),
            '⛔ ফাইলের শেষ সারিটা সর্বমোট নয়।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** @param array<string, string> $extra */
    private function screen(User $user, array $extra = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();

        return $this->actingAs($user)->get(route('sales.report.show', [
            'slug' => 'by-customer', 'from' => $this->day, 'to' => $this->day, ...$extra,
        ]));
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function sell(Customer $customer, Branch $branch, string $amount): void
    {
        CompanyContext::set($this->company->id, $branch->id);

        $warehouse = Warehouse::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('branch_id', $branch->id)->orderBy('id')->value('id');

        $invoice = app(SalesInvoiceService::class)->create(
            ['customer_id' => $customer->id, 'branch_id' => $branch->id, 'warehouse_id' => $warehouse, 'trx_date' => $this->day],
            [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => $amount]],
        );
        app(SalesInvoiceService::class)->confirm($invoice);

        CompanyContext::set($this->company->id, $this->mymensingh->id);
    }
}
