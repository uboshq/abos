<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * যে শাখা বাছলেন, সেই শাখাই দেখবেন — মালিকের অভিযোগ, ২৯ সেপ্টেম্বর ২০২৬:
 * *"এক শাখার হিসাব আরেক শাখায় দেখা যায় কেন?"*
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * হেডারে শাখা বাছলে কেবল নতুন কাগজের শাখা বদলাত; কাগজের তালিকা আর রিপোর্ট
 * শাখা দেখত কেবল ব্যবহারকারীর **সীমা** ধরে — তাই সীমাহীন মালিক যে শাখাই
 * বাছুন, সব শাখা দেখতেন।
 *
 * ⭐ এখন: একটা শাখা বাছলে কেবল সেটা (শাখাহীন সারিও নয় — ওগুলো কোম্পানি-
 * স্তরের); "সব শাখা" বাছলে আগের মতো নাগাল, শাখাহীনসহ। কাজের শাখা আলাদা —
 * "সব শাখা" বাছলে নতুন কাগজের শাখা বদলায় না।
 *
 * ⓘ প্রতিটা দাবি **একই মানুষ** — কেবল হেডারের বাছাই বদলায়।
 */
final class TheBranchYouChoseIsTheBranchYouSeeTest extends TestCase
{
    use RefreshDatabase;

    private const REPORT = 'sales.by_customer';

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

        [$ours, $theirs, $head] = Customer::query()->orderBy('id')->take(3)->get()->all();
        $this->sell($ours, $this->mymensingh, '700');
        $this->sell($theirs, $this->netrakona, '300');

        // ⓘ শাখাহীন একটা বিল — প্রধান অফিসের, কোম্পানি-স্তরের
        $unbranched = $this->sell($head, $this->mymensingh, '50');
        DB::table('sal_invoices')->where('id', $unbranched)->update(['branch_id' => null]);
    }

    public function test_choosing_a_branch_shows_only_that_branch_and_all_shows_every_one(): void
    {
        $this->assertTrue((bool) $this->owner->fresh()->view_all_branches, 'শুরুর মান "সব শাখা" নয় — কাল সকালে সবার দেখা বদলে যেত।');
        $this->assertSame(['1050.0000' => 3], $this->invoices(), 'শুরুতে সব শাখা দেখা যায়নি।');

        $this->choose($this->mymensingh->id);
        $this->assertSame(['700.0000' => 1], $this->invoices(), '⛔ ময়মনসিংহ বেছেও অন্য শাখার বা শাখাহীন বিল দেখা গেল।');

        $this->choose($this->netrakona->id);
        $this->assertSame(['300.0000' => 1], $this->invoices(), '⛔ নেত্রকোনা বেছেও অন্য শাখার বিল দেখা গেল।');

        $this->choose('all');
        $this->assertSame(['1050.0000' => 3], $this->invoices(), '"সব শাখা"-তে সব ফেরেনি।');
    }

    public function test_a_report_follows_the_chosen_branch_and_all_adds_up(): void
    {
        $this->choose($this->mymensingh->id);
        $a = $this->reportTotal();

        $this->choose($this->netrakona->id);
        $b = $this->reportTotal();

        $this->choose('all');
        $all = $this->reportTotal();

        $this->assertSame(0, bccomp($a, '700', 2), '⛔ ময়মনসিংহের রিপোর্টে অন্য শাখার টাকা।');
        $this->assertSame(0, bccomp($b, '300', 2), '⛔ নেত্রকোনার রিপোর্টে অন্য শাখার টাকা।');
        $this->assertSame(0, bccomp($all, bcadd(bcadd($a, $b, 4), '50', 4), 2),
            '⛔ "সব শাখা"-র মোট ≠ ময়মনসিংহ + নেত্রকোনা + শাখাহীন।');
    }

    public function test_choosing_all_does_not_move_the_working_branch(): void
    {
        $this->choose($this->netrakona->id);
        $this->choose('all');

        $this->assertSame($this->netrakona->id, (int) $this->owner->fresh()->current_branch_id,
            '⛔ "সব শাখা" বাছতেই নতুন কাগজের শাখা বদলে গেল।');
    }

    public function test_a_clerk_held_to_one_branch_asking_for_all_still_sees_only_that_branch(): void
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'current_branch_id' => $this->mymensingh->id, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true, 'default_branch_id' => $this->mymensingh->id]);
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('sales.invoice.view', 'web')));
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->mymensingh->id,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->owner = $clerk;
        $this->choose('all');

        $this->assertSame(['750.0000' => 2], $this->invoices(),
            '⛔ এক শাখায় আটকানো কর্মী "সব শাখা" চেয়ে অন্য শাখার বিল পেলেন (বা নিজেরগুলো হারালেন)।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** হেডারের বাছাই — আসল দরজা দিয়ে, তারপর পরের অনুরোধের মতো প্রসঙ্গ। */
    private function choose(int|string $branch): void
    {
        $this->actingAs($this->owner->fresh())
            ->post(route('branch.switch'), ['branch_id' => (string) $branch])
            ->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->actingAs($this->owner);
    }

    /** @return array<string, int> দেখা বিলের মোট টাকা => কয়টা */
    private function invoices(): array
    {
        $rows = SalesInvoice::query()->where('trx_date', $this->day)->get();

        return [number_format((float) $rows->sum('total'), 4, '.', '') => $rows->count()];
    }

    private function reportTotal(): string
    {
        return (string) app(ReportEngine::class)
            ->run(self::REPORT, ['from' => $this->day, 'to' => $this->day])
            ->totals['total'];
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function sell(Customer $customer, Branch $branch, string $amount): int
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

        return (int) $invoice->id;
    }
}
