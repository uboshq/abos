<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Accounts\Events\ChequeBounced;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Services\CreditExposure;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ চেক ফেরত এলে গ্রাহকের বাকি নিজে থেকেই বন্ধ, আর খোলেন কেবল মালিক — মালিকের উত্তর ৪, ১১ অক্টোবর ২০২৬ (ধাপ ২.৪)।
 *
 *   · ফেরত (পাশের আগে বা পরে — দুই পথ) → বাকি বন্ধ, কারণ "চেক ফেরত — <নং>", দেয়াল নতুন বাকি থামায়, মালিক খবর পান।
 *   · পরে টাকা এলেও বন্ধ ওঠে না।
 *   · সীমা বদলানোর চাবিওয়ালা (মালিক নন) খুলতে চাইলে সার্ভার ৪০৩ দেয়; মালিক কারণসহ খোলেন, আর সেটা নিরীক্ষায় ওঠে।
 *   · একই চেকের ঘটনা দুইবার এলেও একটাই বন্ধ, একটাই নিরীক্ষার সারি, একটাই খবর।
 *   · হাতে বসানো বন্ধ আগের মতোই চাবিওয়ালা খুলতে পারেন।
 */
final class ABouncedChequeBlocksUntilTheOwnerLiftsItTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $dealer;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        app(SettingsService::class)->set('customer.credit_limit_enabled', true);

        $this->dealer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->dealer->forceFill(['credit_limit' => '1000000'])->save();

        $this->bank = Account::query()->create([
            'company_id' => CompanyContext::id(), 'code' => '1102-CCB', 'name_en' => 'Bounce Bank', 'name_bn' => 'ফেরত ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK, 'is_active' => true, 'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_bounce_blocks_the_customer_stops_new_credit_and_tells_the_owner(): void
    {
        $cheque = $this->receive('25000');
        app(ChequeService::class)->deposit($cheque);
        $this->assertSame(Cheque::BOUNCED, app(ChequeService::class)->bounce($cheque->fresh(), 'তহবিল অপর্যাপ্ত')->status);

        $fresh = $this->dealer->fresh();
        $word = __('customer::credit_block.bounced_cheque', ['no' => $cheque->cheque_no]);
        $this->assertTrue($fresh->isCreditBlocked(), '⛔ চেক ফেরত এলো, অথচ গ্রাহকের বাকি খোলা।');
        $this->assertSame(Customer::BLOCK_BOUNCED_CHEQUE, $fresh->credit_block_kind);
        $this->assertSame($word, $fresh->credit_block_reason);
        $this->assertTrue($this->audited('credit_blocked', $word));

        try {
            app(CreditExposure::class)->assertRoom($fresh, '500000');
            $this->fail('⛔ চেক ফেরতের পরেও নতুন বাকি গেল।');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($word, $e->validator->errors()->first('customer_id'), 'দেয়াল থামল, তবে বাকি বন্ধের কারণে নয়।');
        }

        $this->assertSame(1, Notification::query()->withoutGlobalScopes()->where('user_id', $this->owner->id)
            ->where('type', 'customer.cheque_bounce_block')->count(), '⛔ মালিক চেক ফেরতের বন্ধের খবর পাননি।');
    }

    public function test_a_bounce_after_clearing_blocks_too(): void
    {
        $cheque = app(ChequeService::class)->clear($this->receive('5000'));
        app(ChequeService::class)->bounce($cheque, 'ব্যাংক টাকা ফিরিয়ে নিয়েছে');

        $this->assertSame(Customer::BLOCK_BOUNCED_CHEQUE, $this->dealer->fresh()->credit_block_kind, '⛔ পাশের পরের ফেরতে বন্ধ বসেনি।');
    }

    public function test_a_payment_does_not_lift_it_and_only_the_owner_does_with_a_reason(): void
    {
        $cheque = $this->receive('25000');
        app(ChequeService::class)->bounce($cheque, 'তহবিল অপর্যাপ্ত');

        // ⓘ গ্রাহক নগদে পুরো টাকা দিলেন — বন্ধ তবু থাকে
        app(PostingEngine::class)->post(
            sourceType: 'test:cash-in', sourceId: random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: [
                ['account_id' => $this->bank->id, 'debit' => '25000'],
                ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => '25000', 'party_type' => 'customer', 'party_id' => $this->dealer->id],
            ], branchId: $this->company->defaultBranch()?->id,
        );
        $this->assertTrue($this->dealer->fresh()->isCreditBlocked(), '⛔ টাকা আসতেই চেক ফেরতের বন্ধ উঠে গেল।');

        $manager = $this->staffWith(['customer.view', 'customer.update']);
        $this->actingAs($manager)->delete(route('customer.credit_block.destroy', $this->dealer), ['reason' => 'টাকা এসেছে'])
            ->assertForbidden();
        $this->assertTrue($this->dealer->fresh()->isCreditBlocked(), '⛔ মালিক নন, অথচ চেক ফেরতের বন্ধ খুলে ফেললেন।');

        $this->actingAs($this->owner)->delete(route('customer.credit_block.destroy', $this->dealer), ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertTrue($this->dealer->fresh()->isCreditBlocked(), '⛔ কারণ ছাড়া খুলল।');

        $this->actingAs($this->owner)->delete(route('customer.credit_block.destroy', $this->dealer), ['reason' => 'নগদে শোধ, মালিক দেখলেন'])
            ->assertSessionHasNoErrors();
        $fresh = $this->dealer->fresh();
        $this->assertFalse($fresh->isCreditBlocked());
        $this->assertNull($fresh->credit_block_kind);
        $this->assertTrue($this->audited('credit_unblocked', 'নগদে শোধ, মালিক দেখলেন'), '⛔ খোলাটা নিরীক্ষায় নেই।');
    }

    public function test_the_same_bounce_heard_twice_blocks_once(): void
    {
        $cheque = $this->receive('25000');
        app(ChequeService::class)->bounce($cheque, 'তহবিল অপর্যাপ্ত');
        event(ChequeBounced::from($cheque->fresh()));

        $word = __('customer::credit_block.bounced_cheque', ['no' => $cheque->cheque_no]);
        $this->assertSame($word, $this->dealer->fresh()->credit_block_reason);
        $this->assertSame(1, AuditTrail::query()->where('action', 'credit_blocked')->where('auditable_id', $this->dealer->id)->count());
        $this->assertSame(1, Notification::query()->withoutGlobalScopes()->where('user_id', $this->owner->id)
            ->where('type', 'customer.cheque_bounce_block')->count());
    }

    /**
     * ⭐ হাতে বসানো আটক তোলেন সীমার সইকারী — `customer.credit_limit` ছকের কোনো ধাপে যিনি আছেন ([[CustomerPolicy::liftCreditBlock()]],
     * 9999782c4)। ⛔ কেবল `customer.update` যথেষ্ট নয় — নিরাপদ দিক (fe, ১৩ অক্টোবর ২০২৬)। ⓘ আগে এই পরীক্ষা সম্পাদনার চাবিতেই তুলত, আর
     * পলিসির সাথে লড়ে লাল থাকত; বাউন্স-চেকের আটক কেবল মালিক তোলেন (উপরের পরীক্ষাগুলো)।
     */
    public function test_a_hand_set_block_still_lifts_with_the_limit_key(): void
    {
        $this->post(route('customer.credit_block.store', $this->dealer), ['reason' => 'হাতে বন্ধ'])->assertSessionHasNoErrors();

        $manager = $this->staffWith(['customer.view', 'customer.update']);
        $flow = \App\Models\ApprovalFlow::query()->create(['module' => 'customer', 'action' => 'credit_limit', 'threshold_amount' => null, 'is_active' => true]);
        \App\Models\ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => \App\Models\ApprovalFlowStep::BY_USER, 'approver_id' => $manager->id]);

        $this->actingAs($manager)->delete(route('customer.credit_block.destroy', $this->dealer), ['reason' => 'ঠিক আছে'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->dealer->fresh()->isCreditBlocked(), '⛔ সীমার সইকারী হাতে বসানো আটক তুলতে পারলেন না');
    }

    /** ⛔ সম্পাদনার চাবি আছে, সীমার সইকারী নন — হাতে বসানো আটক তোলা যায় না (fe, ১৩ অক্টোবর ২০২৬) */
    public function test_the_edit_key_alone_cannot_lift_a_hand_set_block(): void
    {
        $this->post(route('customer.credit_block.store', $this->dealer), ['reason' => 'হাতে বন্ধ'])->assertSessionHasNoErrors();

        $clerk = $this->staffWith(['customer.view', 'customer.update']);

        $this->actingAs($clerk)->delete(route('customer.credit_block.destroy', $this->dealer), ['reason' => 'ঠিক আছে'])
            ->assertForbidden();

        $this->assertTrue($this->dealer->fresh()->isCreditBlocked(), '⛔ কেবল সম্পাদনার চাবিতে আটক উঠে গেল');
    }

    // ── যন্ত্রপাতি ──

    private function receive(string $amount): Cheque
    {
        return app(ChequeService::class)->create([
            'direction' => Cheque::RECEIVED, 'cheque_no' => 'N'.random_int(100000, 999999), 'bank_name' => 'Sonali Bank',
            'cheque_date' => now()->toDateString(), 'amount' => $amount, 'party_type' => 'customer', 'party_id' => $this->dealer->id,
            'bank_account_id' => $this->bank->id,
        ]);
    }

    private function audited(string $action, string $reason): bool
    {
        return AuditTrail::query()->where('action', $action)->where('auditable_id', $this->dealer->id)->where('reason', $reason)->exists();
    }

    /** @param  list<string>  $keys */
    private function staffWith(array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        CompanyContext::forCompany($this->company->id, function () use ($user, $keys): void {
            $role = Role::create(['name' => 'bounce-test-'.Str::random(5), 'guard_name' => 'web']);
            $role->givePermissionTo(array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys));
            $user->assignRole($role);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
