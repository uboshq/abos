<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ "বাকি বন্ধ" তোলা — সীমা বাড়ানোর সইকারীর কাজ, কেবল সম্পাদনার চাবি নয় (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, গ্রাহক ১৬;
 * [[CustomerPolicy::liftCreditBlock()]])।
 *
 * ⓘ সীমা বাড়াতে "বাকির সীমা" ছকের সই লাগে, অথচ বন্ধ তোলা যেত `customer.update`-এ — যে ডাটা এন্ট্রির মানুষ ফোন নম্বর শোধরান,
 * তিনিই চেক-ফেরত দোকানের বাকি আবার খুলে দিতেন।
 */
final class LiftingACreditBlockNeedsTheLimitSignerTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_signer_of_the_limit_flow_lifts_it_while_any_editor_may_place_it(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $customer = Customer::query()->create(['company_id' => $company->id, 'code' => 'BLK-1', 'name_en' => 'Bounced Cheque Shop', 'is_active' => true]);
        $editor = $this->member('editor@block.test');
        $signer = $this->member('signer@block.test');

        // ⓘ সম্পাদনার চাবিতে বসানো চলে
        $this->actingAs($editor)->post(route('customer.credit_block.store', $customer), ['reason' => 'চেক ফেরত'])->assertSessionHasNoErrors();
        $this->assertTrue($customer->fresh()->isCreditBlocked());

        // ⛔ ছক নেই — কেবল সম্পাদনার চাবিতে তোলা যায় না
        $this->actingAs($editor)->delete(route('customer.credit_block.destroy', $customer), ['reason' => 'টাকা এসেছে'])->assertForbidden();
        $this->assertTrue($customer->fresh()->isCreditBlocked(), '⛔ সম্পাদনার চাবিতেই বাকি বন্ধ উঠে গেল');

        // ⓘ "বাকির সীমা" ছকের সইকারী তুলতে পারেন; ছকের বাইরের সম্পাদক তবু পারেন না
        $flow = ApprovalFlow::query()->create(['module' => 'customer', 'action' => 'credit_limit', 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id]);

        $this->actingAs($editor)->delete(route('customer.credit_block.destroy', $customer), ['reason' => 'টাকা এসেছে'])->assertForbidden();
        $this->actingAs($signer)->delete(route('customer.credit_block.destroy', $customer), ['reason' => 'টাকা এসেছে'])->assertSessionHasNoErrors();
        $this->assertFalse($customer->fresh()->isCreditBlocked(), 'সইকারী বন্ধ তুলতে পারেননি');
    }

    private function member(string $email): User
    {
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::factory()->create(['email' => $email, 'current_company_id' => $company->id]);
        $user->companies()->attach($company->id, ['is_active' => true]);
        $user->givePermissionTo(['customer.view', 'customer.update']);

        return $user->fresh();
    }
}
