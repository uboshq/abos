<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ শাখায় সীমিত কর্মী অন্য শাখার গ্রাহক খোলেন না, বদলান না, বানান না — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (গ্রাহক ১১;
 * [[CustomerPolicy]], [[CustomerRequest]])।
 *
 * ⓘ শাখার দেয়াল কেবল তালিকায় ছিল। পলিসি কেবল চাবি দেখত আর ফর্ম কেবল কোম্পানি — ময়মনসিংহের কর্মী নম্বর বসিয়ে নেত্রকোনার গ্রাহক
 * খুলতেন, তাঁর বাকির সীমা বদলাতেন, বা নেত্রকোনার নামে নতুন গ্রাহক বানাতেন।
 */
final class ACustomerOfAnotherBranchIsNotMineToEditTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $clerk;

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

        $this->clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->clerk->givePermissionTo(['customer.view', 'customer.create', 'customer.update', 'customer.delete']);
        UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $this->clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->mine->id]);
        app(DataScope::class)->forget();
    }

    public function test_another_branchs_customer_cannot_be_opened_or_changed(): void
    {
        $theirs = $this->customer('Their Shop', $this->theirs);
        $ours = $this->customer('Our Shop', $this->mine);

        $this->actingAs($this->clerk);
        $this->get(route('customer.show', $theirs))->assertForbidden();
        $this->get(route('customer.edit', $theirs))->assertForbidden();
        $this->put(route('customer.update', $theirs), ['name_en' => 'Renamed', 'credit_limit' => '999999'])->assertForbidden();
        $this->assertSame('Their Shop', $theirs->fresh()->name_en, '⛔ অন্য শাখার গ্রাহক বদলে গেল');

        // ⓘ নিজের শাখার গ্রাহক — আগের মতোই
        $this->get(route('customer.show', $ours))->assertOk();
    }

    public function test_a_new_customer_cannot_be_placed_in_a_branch_out_of_reach(): void
    {
        $this->actingAs($this->clerk)->post(route('customer.store'), ['name_en' => 'Placed Elsewhere', 'branch_id' => $this->theirs->id])
            ->assertSessionHasErrors('branch_id');
        $this->assertFalse(Customer::query()->where('name_en', 'Placed Elsewhere')->exists(), '⛔ নাগালের বাইরের শাখায় গ্রাহক বানানো গেল');

        $this->post(route('customer.store'), ['name_en' => 'Placed Here', 'branch_id' => $this->mine->id])->assertSessionHasNoErrors();
    }

    /**
     * ⛔ পোর্টালের চাবি আর আচরণের নোট — শাখার দেয়াল এখানেও (cloud/people-fixes রিভিউ, ১০ অক্টোবর ২০২৬;
     * [[CustomerPolicy::managePortal()]], [[ConductController]])। আগে কেবল চাবি দেখা হত, তাই আইডি লিখে অন্য শাখার গ্রাহকের
     * পোর্টাল খোলা-বন্ধ আর নোট লেখা-নামানো যেত।
     */
    public function test_another_branchs_portal_and_conduct_are_out_of_reach(): void
    {
        $theirs = $this->customer('Their Shop', $this->theirs);
        $ours = $this->customer('Our Shop', $this->mine);
        $this->clerk->givePermissionTo([
            \Spatie\Permission\Models\Permission::findOrCreate('customer.portal', 'web'),
            \Spatie\Permission\Models\Permission::findOrCreate('customer.conduct.manage', 'web'),
        ]);
        $note = \App\Modules\Customer\Models\CustomerConduct::query()->create(['company_id' => $this->company->id,
            'customer_id' => $theirs->id, 'type' => 'LATE_PAYMENT', 'note' => 'late', 'is_active' => true, 'recorded_by' => User::query()->where('email', 'owner@abos.test')->value('id'), 'recorded_at' => now()]);

        $this->actingAs($this->clerk);
        $this->post(route('customer.portal.store', $theirs), ['password' => 'Strong-pass-2026x', 'password_confirmation' => 'Strong-pass-2026x'])->assertForbidden();
        $this->delete(route('customer.portal.destroy', $theirs))->assertForbidden();
        $this->assertFalse((bool) $theirs->fresh()->portal_enabled, '⛔ অন্য শাখার গ্রাহকের পোর্টাল খুলে গেল');

        $this->post(route('customer.conduct.store', $theirs), ['type' => 'LATE_PAYMENT', 'note' => 'x'])->assertForbidden();
        $this->post(route('customer.conduct.retire', $note))->assertForbidden();
        $this->assertSame(1, \App\Modules\Customer\Models\CustomerConduct::query()->where('customer_id', $theirs->id)->count(), '⛔ অন্য শাখার গ্রাহকে নোট বসল');
        $this->assertTrue((bool) $note->fresh()->is_active, '⛔ অন্য শাখার গ্রাহকের নোট নামল');

        // ⓘ নিজের শাখার গ্রাহক — আগের মতোই
        $this->post(route('customer.conduct.store', $ours), ['type' => 'LATE_PAYMENT', 'note' => 'x'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, \App\Modules\Customer\Models\CustomerConduct::query()->where('customer_id', $ours->id)->count());
    }

    private function customer(string $name, Branch $branch): Customer
    {
        return Customer::query()->create(['company_id' => $this->company->id, 'code' => strtoupper(substr(md5($name), 0, 8)),
            'name_en' => $name, 'branch_id' => $branch->id, 'is_active' => true]);
    }
}
