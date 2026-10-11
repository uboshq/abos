<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Models\AuditTrail;
use App\Models\Notification;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\CreditControl\Listeners\BlockTheCustomerOnABouncedCheque;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\CreditExposure;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ চেক ফেরত গেলে বাকি নিজেই বন্ধ, খোলেন কেবল মালিক — বাকি নিয়ন্ত্রণ ধাপ ২.৪ (মালিকের উত্তর ৪, ১১ অক্টোবর ২০২৬)।
 *
 *   · ফেরতের দুই দরজাতেই (চেকের নিজের পাতা, আদায়ের কাগজের চেক) গ্রাহকের বাকি বন্ধ — কারণ "চেক ফেরত — <চেক নং>"; মালিককে খবর।
 *   · অন্য কেউ (সীমার চাবিওয়ালাও) খুলতে গেলে সার্ভার ৪০৩; টাকা দিলেও খোলে না।
 *   · মালিক খোলেন কারণসহ, আর খোলা নিরীক্ষার খাতায় ওঠে।
 *   · একই ঘটনা দুবার এলে বন্ধও একবার, খবরও একবার।
 */
final class ABouncedChequeBlocksUntilTheOwnerSaysTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    private Customer $dealer;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCreditCompany();

        $this->dealer = $this->customer('BOUNCE-1', '100000');
        $this->bank = Account::query()->create([
            'company_id' => CompanyContext::id(), 'code' => '1102-CC', 'name_en' => 'CC Bank', 'name_bn' => 'সিসি ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK, 'is_active' => true, 'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    public function test_a_bounced_cheque_blocks_the_customer_and_tells_the_owner(): void
    {
        $cheque = $this->receive('5000', 'BC-111');

        // ⓘ ফেরত বসান হিসাবরক্ষক — নিজের কাজের খবর কেউ নিজে পান না, তাই মালিক নিজে বসালে খবর যেত না
        $this->actingAs($this->staffWith(['accounts.cheque.manage']));
        app(ChequeService::class)->bounce($cheque, 'তহবিল নেই');

        $this->assertSame(Cheque::BOUNCED, $cheque->fresh()->status, 'দৃশ্যটাই হয়নি — চেক ফেরত বসেনি।');
        $fresh = $this->dealer->fresh();
        $this->assertTrue($fresh->isBlockedByBouncedCheque(), '⛔ চেক ফেরতেও বাকি খোলা।');
        $this->assertSame('চেক ফেরত — BC-111', $fresh->credit_block_reason);

        // ⓘ দেয়াল থামায়, পুরো টাকায় চলে
        try {
            app(CreditExposure::class)->assertRoom($fresh, '100');
            $this->fail('⛔ চেক ফেরতের গ্রাহক বাকিতে কিনেছেন।');
        } catch (ValidationException) {
        }
        app(CreditExposure::class)->assertRoom($fresh, '100', '100');

        $this->assertSame(1, Notification::query()->where('type', BlockTheCustomerOnABouncedCheque::NOTICE)->where('user_id', $this->owner->id)->count(),
            '⛔ মালিক খবর পাননি।');
    }

    public function test_a_collection_cheque_bounced_from_sales_blocks_too(): void
    {
        $collection = app(CollectionService::class)->confirm(app(CollectionService::class)->create(
            ['customer_id' => $this->dealer->id, 'trx_date' => now()->toDateString(), 'amount' => '8000',
                'account_id' => StandardChart::find(StandardChart::CHEQUES_IN_HAND)->id, 'allows_holding' => true],
            [],
        ));
        $cheque = app(ChequeService::class)->record([
            'amount' => '8000', 'cheque_no' => 'BC-222', 'cheque_date' => now()->toDateString(), 'bank_name' => 'Sonali Bank',
            'party_type' => 'customer', 'party_id' => $this->dealer->id, 'collection_id' => $collection->id,
        ]);

        app(CollectionService::class)->bounceReceivedCheque($cheque->fresh(), 'ব্যাংক ফিরিয়েছে');

        $this->assertTrue($this->dealer->fresh()->isBlockedByBouncedCheque());
        $this->assertStringContainsString('BC-222', (string) $this->dealer->fresh()->credit_block_reason);
    }

    public function test_someone_else_cannot_release_it_not_even_with_the_customer_key(): void
    {
        app(ChequeService::class)->bounce($this->receive('5000', 'BC-333'), 'তহবিল নেই');

        $clerk = $this->staffWith(['customer.view', 'customer.update', 'credit_control.view']);

        $this->actingAs($clerk)
            ->delete(route('customer.credit_block.destroy', $this->dealer->id), ['reason' => 'টাকা দিয়েছে'])
            ->assertForbidden();

        $this->assertTrue($this->dealer->fresh()->isBlockedByBouncedCheque(), '⛔ মালিক ছাড়া কেউ চেক ফেরতের বন্ধ খুলেছেন।');

        // ⓘ হাতে বসানো বন্ধ আগের মতোই সীমার চাবিতে খোলে
        $other = $this->customer('BOUNCE-HAND', '1000');
        app(CustomerService::class)->blockCredit($other, 'হাতে');
        $this->actingAs($clerk)->delete(route('customer.credit_block.destroy', $other->id), ['reason' => 'মিটেছে'])->assertRedirect();
        $this->assertFalse($other->fresh()->isCreditBlocked());
    }

    public function test_the_release_button_is_drawn_only_for_the_owner(): void
    {
        app(ChequeService::class)->bounce($this->receive('5000', 'BC-777'), 'তহবিল নেই');

        $this->get(route('credit_control.customer.show', $this->dealer->id))->assertOk()
            ->assertSee('data-credit-release-form', false)
            ->assertSee('চেক ফেরত — BC-777');

        $clerk = $this->staffWith(['customer.view', 'customer.update', 'credit_control.view']);
        $this->actingAs($clerk)->get(route('credit_control.customer.show', $this->dealer->id))->assertOk()
            ->assertDontSee('data-credit-release-form', false);
    }

    public function test_a_payment_does_not_release_it(): void
    {
        app(ChequeService::class)->bounce($this->receive('5000', 'BC-444'), 'তহবিল নেই');

        $cash = Account::query()->where('company_id', CompanyContext::id())->where('money_kind', 'cash')->where('is_group', false)->value('id');
        $this->postLines([
            ['account_id' => $cash, 'debit' => '50000'],
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => '50000', 'party_type' => 'customer', 'party_id' => $this->dealer->id],
        ]);

        $this->assertTrue($this->dealer->fresh()->isBlockedByBouncedCheque(), '⛔ টাকা দেওয়ায় চেক ফেরতের বন্ধ খুলে গেছে।');
        $this->assertSame('blocked', app(CreditExposure::class)->positionOf($this->dealer->fresh())['state']);
    }

    public function test_the_owner_releases_it_with_a_reason_and_it_is_logged(): void
    {
        app(ChequeService::class)->bounce($this->receive('5000', 'BC-555'), 'তহবিল নেই');

        $this->delete(route('customer.credit_block.destroy', $this->dealer->id), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertTrue($this->dealer->fresh()->isCreditBlocked());

        $this->delete(route('customer.credit_block.destroy', $this->dealer->id), ['reason' => 'নগদে মিটিয়েছেন'])->assertRedirect();

        $fresh = $this->dealer->fresh();
        $this->assertFalse($fresh->isCreditBlocked());
        $this->assertNull($fresh->credit_block_source);

        $log = AuditTrail::query()->forRecord(Customer::class, (int) $this->dealer->id)->where('action', 'credit_unblocked')->latest('id')->firstOrFail();
        $this->assertSame('নগদে মিটিয়েছেন', $log->reason);
        $this->assertSame($this->owner->id, (int) $log->user_id);
    }

    public function test_a_hand_block_becomes_a_cheque_block_and_a_repeat_changes_nothing(): void
    {
        app(CustomerService::class)->blockCredit($this->dealer, 'পুরনো ঝামেলা');

        $service = app(CustomerService::class);
        $this->assertTrue($service->blockForBouncedCheque($this->dealer->id, $this->company->id, 'BC-666'));
        $this->assertFalse($service->blockForBouncedCheque($this->dealer->id, $this->company->id, 'BC-666'), '⛔ একই চেক দুবার বসেছে।');

        $fresh = $this->dealer->fresh();
        $this->assertTrue($fresh->isBlockedByBouncedCheque(), '⛔ হাতের বন্ধ থাকায় চেক ফেরতের বন্ধ খোলা যেত সীমার চাবিতে।');
        $this->assertSame('পুরনো ঝামেলা · চেক ফেরত — BC-666', $fresh->credit_block_reason);
    }

    public function test_a_cheque_we_gave_blocks_nobody(): void
    {
        $cheque = app(ChequeService::class)->create([
            'direction' => Cheque::ISSUED, 'cheque_no' => 'OUT-1', 'bank_name' => 'Sonali Bank', 'cheque_date' => now()->toDateString(),
            'amount' => '1000', 'party_type' => 'supplier', 'party_id' => \App\Modules\Supplier\Models\Supplier::query()->value('id'),
            'bank_account_id' => $this->bank->id,
        ]);

        app(ChequeService::class)->bounce($cheque, 'ভুল');

        $this->assertSame(Cheque::BOUNCED, $cheque->fresh()->status);
        $this->assertSame(0, Customer::query()->whereNotNull('credit_blocked_at')->count(), '⛔ আমাদের দেওয়া চেক ফেরতে কোনো গ্রাহক বন্ধ হয়েছেন।');
    }

    private function receive(string $amount, string $no): Cheque
    {
        return app(ChequeService::class)->create([
            'direction' => Cheque::RECEIVED, 'cheque_no' => $no, 'bank_name' => 'Sonali Bank', 'cheque_date' => now()->toDateString(),
            'amount' => $amount, 'party_type' => 'customer', 'party_id' => $this->dealer->id, 'bank_account_id' => $this->bank->id,
        ]);
    }
}
