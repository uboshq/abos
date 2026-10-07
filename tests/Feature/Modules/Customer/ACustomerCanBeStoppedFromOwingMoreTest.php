<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Services\CreditExposure;
use App\Modules\Sales\Services\OrderStanding;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ "বাকি বন্ধ" — একজন গ্রাহকের নতুন বাকি হাতে বন্ধ, বাকি ও আদায় (৫ অক্টোবর ২০২৬; [[CustomerService::blockCredit()]])।
 *
 *   · বসানো আর তোলা কেবল সীমা বদলানোর চাবিতে (`customer.update`), কারণসহ, আর দুটোই নিরীক্ষার খাতায়।
 *   · বসানো থাকলে সীমায় জায়গা থাকলেও নতুন বাকি নেই — DO/আদেশ, কাউন্টার/চালান/বিল, সারাংশ সব পথে; পুরো টাকা দিলে,
 *     বা আগের অগ্রিমে ঢাকলে, কেনা চলে।
 *   · গ্রাহকের পাতায় আর কাউন্টারের ক্রেতার ঘরে দেখা যায়।
 */
final class ACustomerCanBeStoppedFromOwingMoreTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

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
        app(SettingsService::class)->set('customer.credit_limit_enabled', true);

        $this->customer = Customer::query()->create([
            'code' => 'BLOCK-ME', 'name_en' => 'Block Me', 'name_bn' => 'Block Me', 'is_active' => true,
            'credit_limit' => '100000', 'branch_id' => $this->company->defaultBranch()?->id,
        ]);
    }

    public function test_only_the_limit_key_sets_or_clears_it_and_both_reach_the_audit_trail(): void
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk->givePermissionTo(['customer.view']);

        $this->actingAs($clerk->fresh())->post(route('customer.credit_block.store', $this->customer), ['reason' => 'চেক ফেরত'])
            ->assertForbidden();
        $this->assertFalse($this->customer->fresh()->isCreditBlocked(), '⛔ চাবি ছাড়া বাকি বন্ধ বসেছে।');

        $this->actingAs($this->owner)->post(route('customer.credit_block.store', $this->customer), ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertFalse($this->customer->fresh()->isCreditBlocked(), '⛔ কারণ ছাড়া বসেছে।');

        $this->actingAs($this->owner)->post(route('customer.credit_block.store', $this->customer), ['reason' => 'চেক ফেরত'])
            ->assertSessionHasNoErrors();

        $fresh = $this->customer->fresh();
        $this->assertTrue($fresh->isCreditBlocked());
        $this->assertSame('চেক ফেরত', $fresh->credit_block_reason);
        $this->assertSame((int) $this->owner->id, (int) $fresh->credit_blocked_by);
        $this->assertTrue($this->audited('credit_blocked', 'চেক ফেরত'), '⛔ বসানোটা নিরীক্ষার খাতায় নেই।');

        $this->actingAs($clerk->fresh())->delete(route('customer.credit_block.destroy', $this->customer), ['reason' => 'x'])
            ->assertForbidden();
        $this->assertTrue($this->customer->fresh()->isCreditBlocked(), '⛔ চাবি ছাড়া বাকি বন্ধ উঠেছে।');

        $this->actingAs($this->owner)->delete(route('customer.credit_block.destroy', $this->customer), ['reason' => 'টাকা এসেছে'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($this->customer->fresh()->isCreditBlocked());
        $this->assertTrue($this->audited('credit_unblocked', 'টাকা এসেছে'), '⛔ তোলাটা নিরীক্ষার খাতায় নেই।');
    }

    public function test_a_blocked_customer_buys_only_paid_in_full_on_every_path(): void
    {
        $this->block();
        $credit = app(CreditExposure::class);

        $result = $credit->check($this->customer->fresh(), '1000');
        $this->assertFalse($result['fits'], '⛔ বাকি বন্ধ গ্রাহকের DO/আদেশ সীমায় জায়গা পেয়ে গেছে।');
        $this->assertStringContainsString('চেক ফেরত', (string) $result['reason']);
        $this->assertSame(0, bccomp($result['short'], '1000', 4));

        try {
            $credit->assertRoom($this->customer->fresh(), '1000');
            $this->fail('⛔ বাকি বন্ধ গ্রাহক কাউন্টার/চালানে বাকি পেয়েছেন।');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('চেক ফেরত', $e->validator->errors()->first('customer_id'));
        }

        $this->assertTrue(app(OrderStanding::class)->for($this->customer->fresh(), '1000')['stop']);

        // ⭐ পুরো টাকা এখনই — চলে
        $credit->assertRoom($this->customer->fresh(), '1000', '1000');
    }

    public function test_an_advance_that_covers_the_sale_is_not_credit(): void
    {
        $this->block();

        $money = DB::table('accounts')->where('company_id', $this->company->id)->where('money_kind', 'cash')->where('is_group', false)->orderBy('id')->value('id');
        app(PostingEngine::class)->post(
            sourceType: 'test:advance', sourceId: random_int(1, 9_999_999), trxDate: now()->toDateString(),
            lines: [
                ['account_id' => $money, 'debit' => '5000'],
                ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => '5000', 'party_type' => 'customer', 'party_id' => $this->customer->id],
            ],
            branchId: $this->company->defaultBranch()?->id,
        );

        $credit = app(CreditExposure::class);
        $this->assertTrue($credit->check($this->customer->fresh(), '5000')['fits'], '⛔ অগ্রিমে ঢাকা মাল বাকি ধরা হয়েছে।');
        $credit->assertRoom($this->customer->fresh(), '5000');
        $this->assertFalse(app(OrderStanding::class)->for($this->customer->fresh(), '5000')['stop']);

        // ⓘ অগ্রিমের এক টাকা বেশি — সেটুকুই নতুন বাকি
        $this->assertFalse($credit->check($this->customer->fresh(), '5001')['fits']);
        $this->expectException(ValidationException::class);
        $credit->assertRoom($this->customer->fresh(), '5001');
    }

    public function test_the_customer_page_and_the_counter_show_it(): void
    {
        $this->block();

        $this->get(route('customer.show', $this->customer))
            ->assertOk()
            ->assertSee('data-credit-blocked', false)
            ->assertSee('চেক ফেরত');

        $terms = $this->get(route('sales.direct.create'))->assertOk()->viewData('customerTerms');
        $this->assertStringContainsString('চেক ফেরত', (string) ($terms[$this->customer->id]['stop'] ?? ''), '⛔ কাউন্টারের ক্রেতার ঘর বাকি বন্ধ জানে না।');
    }

    public function test_with_the_credit_switch_off_the_flag_stops_nothing(): void
    {
        $this->block();
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);

        $this->assertTrue(app(CreditExposure::class)->check($this->customer->fresh(), '1000')['fits']);
        app(CreditExposure::class)->assertRoom($this->customer->fresh(), '1000');
    }

    private function block(): void
    {
        $this->post(route('customer.credit_block.store', $this->customer), ['reason' => 'চেক ফেরত'])->assertSessionHasNoErrors();
    }

    private function audited(string $action, string $reason): bool
    {
        return AuditTrail::query()
            ->where('action', $action)
            ->where('auditable_id', $this->customer->id)
            ->where('reason', $reason)
            ->exists();
    }
}
