<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\DataScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CreditExposure;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ বাকির দেয়াল, পূর্ণ নিরীক্ষার পরে — ১৮ অক্টোবর ২০২৬ (fe-র D1, D2, D5)। (D7 — সুইচ বন্ধেও চেক-ফেরতের ব্লক — দাবি
 * [[ACustomerCanBeStoppedFromOwingMoreTest]]-এ।)
 *
 * ⛔ যা ভাঙা ছিল:
 *   D1 অনুমোদিত DO থেকে কাউন্টারে বিক্রিতে চালানের টাকা আর খোলা DO-র টাকা দুটোই গোনা — সীমা ১ লাখ, DO ৬০ হাজার, তবু "না"
 *   D2 মেয়াদ পেরোনো বিলের দেয়াল কেবল হেডারের শাখার বিল দেখত — অন্য শাখার পুরনো বাকি নিয়ে নতুন বাকি চলত
 *   D5 গ্রাহকের ফর্মে বা আমদানিতে সীমা বাড়ানো ছকের যেকোনো সইকারীর "হ্যাঁ"-তে বসত — মালিকের সই লাগত না
 */
final class TheCreditWallUnderTheFullAuditTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCreditCompany();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── D1 ────────────────────────────────────────────────────────────

    public function test_an_approved_delivery_order_is_counted_once_when_it_sells_at_the_counter(): void
    {
        app(CashTillService::class)->ensurePrimaryTill();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = $this->stockedProduct('CC-D1');
        // ⓘ তালিকার দামেই বিক্রি — দাম বদলের দেয়াল যেন ভুল কারণে না থামায়
        $product->forceFill(['sale_price' => '10000'])->save();
        $customer = $this->customer('D1-SHOP', '0');

        // ⓘ ৬টা × ১০,০০০ = ৬০,০০০-এর অনুমোদিত DO
        $order = DeliveryOrder::query()->create([
            'document_no' => app(NumberSeriesEngine::class)->next('DO'), 'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString(),
            'status' => DeliveryOrderStatus::ACCOUNTS_APPROVED, 'created_by' => $this->owner->id,
        ]);
        $order->lines()->create(['product_id' => $product->id, 'qty' => '6', 'rate' => '10000', 'discount_percent' => '0']);
        $order->recalculate();
        $this->assertSame(0, bccomp('60000', (string) $order->fresh()->total, 4), 'ছক ভুল: DO ৬০ হাজারের নয়');

        // ⓘ সীমা: খাতার বকেয়া + আটকে থাকা (DO-সহ) + ৪০ হাজার = সব মিলে ১ লাখের জায়গা, যার ৬০ হাজার এই DO-ই
        $credit = app(CreditExposure::class);
        $fresh = $customer->fresh();
        $held = bcadd($credit->pending($fresh), $credit->unclearedCheques($fresh), 4);
        $fresh->forceFill(['credit_limit' => bcadd(bcadd($fresh->outstanding(), $held, 4), '40000', 4)])->save();

        $lines = collect($order->fresh()->counterScreen()['lines'])->map(fn (array $l) => [
            'product_id' => $l['product_id'] ?? $product->id, 'qty' => (string) $l['qty'], 'free_qty' => '0',
            'rate' => (string) $l['rate'], 'discount_percent' => (string) ($l['discount_percent'] ?? '0'),
            'source_line_id' => $l['source_line_id'],
        ])->all();

        $this->post(route('sales.direct.store'), [
            'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id,
            'source' => 'do', 'source_id' => $order->id, 'lines' => $lines, 'own_transport' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(DeliveryOrderStatus::INVOICED, $order->fresh()->status, '⛔ DO-র বিক্রি সীমায় আটকাল — DO-র টাকা দুবার গোনা');
        $invoice = SalesInvoice::query()->whereKey((int) $order->fresh()->sales_invoice_id)->firstOrFail();
        $this->assertSame(0, bccomp('60000', (string) $invoice->total, 4));
    }

    public function test_the_scope_of_the_source_delivery_order_ends_with_its_sale(): void
    {
        $customer = $this->customer('D1-SCOPE', '0');
        $order = DeliveryOrder::query()->create([
            'document_no' => app(NumberSeriesEngine::class)->next('DO'), 'customer_id' => $customer->id,
            'trx_date' => now()->toDateString(), 'status' => DeliveryOrderStatus::ACCOUNTS_APPROVED,
            'created_by' => $this->owner->id, 'total' => '5000',
        ]);

        $credit = app(CreditExposure::class);
        $outside = $credit->pending($customer->fresh());
        $inside = CreditExposure::forSourceDeliveryOrder((int) $order->id, fn () => $credit->pending($customer->fresh()));

        $this->assertSame(0, bccomp('5000', bcsub($outside, $inside, 4), 4), 'বিক্রির ভিতরে উৎসের DO বাদ যায়নি');
        $this->assertSame(0, bccomp($outside, $credit->pending($customer->fresh()), 4), '⛔ বিক্রি শেষে DO আবার গোনা হয়নি — ছাড় লেগে রইল');
    }

    // ── D2 ────────────────────────────────────────────────────────────

    public function test_an_overdue_bill_of_another_branch_stops_new_credit(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('customer.overdue_stop', true);
        $settings->set('customer.overdue_block_days', 0);

        $branches = Branch::query()->where('company_id', $this->company->id)->orderBy('id')->get();
        $this->assertGreaterThan(1, $branches->count(), 'ছক ভুল: দুইটা শাখা লাগে');
        [$here, $there] = [$branches[0], $branches[1]];

        $c = $this->customer('D2-SHOP', '500000', (int) $there->id);
        $this->bill($c, 'INV-THERE-1', '1000', daysPastDue: 3);

        // ⓘ হেডারে এই শাখা, দেখা কেবল এই শাখা — অন্য শাখার বিলটা সাধারণ কোয়েরিতে লুকায়
        CompanyContext::set($this->company->id, (int) $here->id);
        $this->owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $here->id])->save();
        $this->actingAs($this->owner->fresh());
        app(DataScope::class)->forget();

        $result = app(CreditExposure::class)->check($c->fresh(), '500');
        $this->assertFalse($result['fits'], '⛔ অন্য শাখার মেয়াদ পেরোনো বিল দেয়াল দেখেনি');
        $this->assertStringContainsString('INV-THERE-1', (string) $result['reason']);

        try {
            app(CreditExposure::class)->assertRoom($c->fresh(), '500');
            $this->fail('⛔ অন্য শাখার মেয়াদ পেরোনো বিল থাকতে নতুন বাকি গেল');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('INV-THERE-1', $e->validator->errors()->first('customer_id'));
        }
    }

    // ── D5 ────────────────────────────────────────────────────────────

    public function test_a_limit_raised_on_the_customer_form_waits_for_the_owners_signature(): void
    {
        $manager = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $manager->companies()->attach($this->company->id, ['is_active' => true]);
        $this->assertFalse(app(\App\Core\Services\Ownership::class)->isOwnerIn($manager, (int) $this->company->id), 'ছক ভুল: ব্যবস্থাপক মালিক');

        $flow = ApprovalFlow::create(['module' => 'customer', 'action' => 'credit_limit']);
        $step = ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $manager->id]);

        $c = $this->customer('D5-SHOP', '100000');
        $ask = function (string $to) use ($c): Approval {
            try {
                app(CustomerService::class)->update($c->fresh(), ['credit_limit' => $to]);
            } catch (ValidationException) {
                // ⓘ সইয়ের অপেক্ষায় — এটাই চাওয়া
            }

            return Approval::query()->where('approvable_type', $c->getMorphClass())->where('approvable_id', $c->id)
                ->where('action', 'credit_limit')->where('status', Approval::PENDING)->latest('id')->firstOrFail();
        };

        // ⛔ মালিক নন এমন সইকারীর "হ্যাঁ" — সীমা বসে না, ফর্মেও পার হয় না
        app(ApprovalEngine::class)->approve($ask('200000'), $manager);
        $this->assertSame(0, bccomp('100000', (string) $c->fresh()->credit_limit, 4), '⛔ মালিকের সই ছাড়া সীমা বাড়ল (সইয়ের পরে)');

        try {
            app(CustomerService::class)->update($c->fresh(), ['credit_limit' => '200000']);
            $this->fail('⛔ মালিকের সই ছাড়া ফর্মে সীমা বাড়ল');
        } catch (ValidationException) {
            $this->assertSame(0, bccomp('100000', (string) $c->fresh()->credit_limit, 4));
        }

        // ⭐ মালিকের সইয়ে বসে
        $step->forceFill(['approver_id' => $this->owner->id])->save();
        // ⓘ ইঞ্জিনগুলো ছক মনে রাখে — সইকারী বদলের পরে নতুন করে বানানো (Mac-এর পালা, ১৮ অক্টোবর ২০২৬)
        app()->forgetInstance(\App\Core\Engines\Approval\ApprovalEngine::class);
        app()->forgetInstance(\App\Core\Engines\Approval\DocumentApproval::class);
        app()->forgetInstance(\App\Modules\Customer\Services\CustomerService::class);
        app(ApprovalEngine::class)->approve($ask('300000'), $this->owner);
        $this->assertSame(0, bccomp('300000', (string) $c->fresh()->credit_limit, 4), '⛔ মালিকের সইয়েও সীমা বসল না');
    }
}
