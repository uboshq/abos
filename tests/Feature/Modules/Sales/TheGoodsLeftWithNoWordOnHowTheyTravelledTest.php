<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryChallanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * মাল বেরোল, অথচ কীভাবে গেল তার কোনো কথা নেই — মালিকের পরিকল্পনা, ধাপ ৫, ২৮ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * DO বা সরাসরি বিক্রি পাকা হত গাড়ি, বাহক কিছু না লিখেই। পরে কেউ বলতে
 * পারত না মালটা কার গাড়িতে গেল, ভাড়া কার খাতায় উঠবে, বা ক্রেতা নিজে
 * নিয়ে গেছেন কি না।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * নিশ্চিতের প্রতিটা দরজায় [[TransportRule]]: গাড়ি, বাহক, নয়তো "পরিবহন লাগবে
 * না (ক্রেতার নিজের)" টিক। খসড়ায় লাগে না। কোম্পানি পরিবহনের ঘর বন্ধ
 * রাখলে নিয়ম খাটে না।
 *
 * ⓘ দরজা তিনটা, প্রতিটার নিজের দাবি: কাউন্টারের "নিশ্চিত" (`sales.direct.store`),
 * বিলের পাতার "নিশ্চিত" যা রাখা খসড়া পাকা করে (`sales.invoice.confirm` →
 * `finishHeld()`), আর অফিসের চালান (`sales.challan.confirm`)।
 */
final class TheGoodsLeftWithNoWordOnHowTheyTravelledTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        // ⚠️ স্পষ্ট করে চালু — ডেমোর সেটিং বদলালেও দাবিটা নিয়মটাই মাপে
        app(SettingsService::class)->set('sales.field_transport', true);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->orderBy('id')->firstOrFail();
    }

    // ── দরজা ১: কাউন্টারের "নিশ্চিত" ───────────────────────────────────

    /** ⛔ পরিবহনের কোনো কথা ছাড়া নিশ্চিত — থামে, আর কিছুই লেখা হয় না। */
    public function test_the_counter_refuses_to_confirm_with_no_transport_and_writes_nothing(): void
    {
        $bills = SalesInvoice::query()->withTrashed()->count();
        $challans = DeliveryChallan::query()->withTrashed()->count();

        $this->sell()->assertSessionHasErrors('transport');

        $this->assertSame($bills, SalesInvoice::query()->withTrashed()->count(), '⛔ থেমেও বিল থেকে গেছে।');
        $this->assertSame($challans, DeliveryChallan::query()->withTrashed()->count(), '⛔ থেমেও চালান থেকে গেছে।');
    }

    /** ⭐ তিনটা উত্তরের যেকোনো একটাই যথেষ্ট — আর টিকটা চালানে মনে থাকে। */
    public function test_any_one_answer_lets_the_counter_confirm_and_the_tick_is_kept(): void
    {
        $this->sell(['own_transport' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue((bool) $this->lastChallan()->own_transport, '⛔ "ক্রেতার নিজের" টিক চালানে পৌঁছায়নি।');
        $this->assertSame(DocumentStatus::CONFIRMED, $this->lastChallan()->status);

        $this->sell(['vehicle_no' => 'ঢাকা মেট্রো ট ১১-২২৩৩'])->assertSessionHasNoErrors();
        $this->assertFalse((bool) $this->lastChallan()->own_transport);

        $this->sell(['carrier_name' => 'করিম ট্রান্সপোর্ট'])->assertSessionHasNoErrors();

        // ⚠️ চালকের নাম একা উত্তর নয় — কোন গাড়ি, সেটাই প্রশ্ন
        $this->sell(['driver_name' => 'রফিক'])->assertSessionHasErrors('transport');
    }

    /** ⭐ খসড়া রাখায় পরিবহন লাগে না — কেবল নিশ্চিতে। */
    public function test_saving_a_draft_needs_no_transport(): void
    {
        $this->sell(['save_as_draft' => '1'])->assertSessionHasNoErrors();

        $this->assertSame('draft', SalesInvoice::query()->latest('id')->firstOrFail()->status);
    }

    // ── দরজা ২: বিলের পাতার "নিশ্চিত" — রাখা খসড়া পাকা করে ─────────────

    /**
     * ⛔ পরিবহন ছাড়া রাখা খসড়া বিলের পাতা থেকে পাকা হয় না।
     *
     * ⓘ এই দরজা কাউন্টারের `complete()` এড়িয়ে যায় ([[DirectSaleService::finishHeld()]]) —
     * তাই নিয়মটা এখানে আলাদা করে লাগে; নইলে "খসড়া রাখুন" দিয়ে নিয়মটা ঘুরে আসা যেত।
     */
    public function test_a_parked_draft_with_no_transport_cannot_be_finished_from_the_bill_page(): void
    {
        $this->sell(['save_as_draft' => '1']);
        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        $this->from(route('sales.invoice.show', $invoice))
            ->post(route('sales.invoice.confirm', $invoice))
            ->assertSessionHasErrors('transport');

        $this->assertSame('draft', $invoice->fresh()->status, '⛔ থেমেও বিল পাকা হয়ে গেছে।');
        $this->assertSame(DocumentStatus::DRAFT, $this->lastChallan()->status, '⛔ থেমেও চালান পাকা, মাল বেরিয়ে গেছে।');
    }

    /**
     * ⭐ সইয়ে থাকা বিক্রি — পরিবহন ছাড়া হলেও শেষ সইয়ে শেষ হয়।
     *
     * ⓘ ধাপ ৫-এর আগে সইয়ে যাওয়া বিক্রিতে (লাইভে INV-0005, 0006) পরিবহন নেই। সইয়ে থাকা
     * বিক্রি বদলানো যায় না, তাই ওগুলো এখানে থামলে সই হয়েও চিরকাল আটকে থাকত।
     * ⚠️ দৃশ্য: টিক দিয়ে পাঠানো, তারপর চালান থেকে টিক মুছে "পুরনো" বানানো — নতুন
     * বিক্রি তো দরজাতেই থামে ([[test_the_counter_refuses_to_confirm_with_no_transport_and_writes_nothing]])।
     */
    public function test_a_sale_held_for_a_signature_before_the_rule_still_finishes_when_signed(): void
    {
        $this->counterDepositFlow();

        $this->sell(['own_transport' => '1', ...$this->bankDeposit()])->assertSessionHasNoErrors();
        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $this->assertTrue(\App\Modules\Sales\Services\DirectSaleService::isHeldForSignature($invoice),
            'দৃশ্যটাই বানানো যায়নি — বিক্রিটা সইয়ে যায়নি।');

        $this->lastChallan()->forceFill(['own_transport' => false])->save();

        $voucher = $invoice->heldCounterDeposits()->firstOrFail();
        $approval = \App\Models\Approval::query()->where('approvable_id', $voucher->id)
            ->where('action', \App\Modules\Accounts\Services\VoucherApproval::COUNTER_DEPOSIT)->firstOrFail();

        /*
         * ⚠️ শেষ সইয়ের স্বয়ংক্রিয় শেষটা থামিয়ে রাখা — নিয়মটা বিলের পাতার বোতামে, আর বোতামই
         * লাগে যখন স্বয়ংক্রিয় শেষ কোনো কারণে থামে ([[HeldCounterSaleFinisher]])। ঘটনা চললে
         * দাবিটা বোতাম ছুঁতই না, আর ছাড়টা তুলে দিলেও সবুজ থাকত (মিউট্যান্ট বেঁচেছিল)।
         */
        \Illuminate\Support\Facades\Event::fake([\App\Core\Events\ApprovalDecided::class]);
        app(\App\Core\Engines\Approval\ApprovalEngine::class)->approve($approval, auth()->user());
        $this->assertSame('draft', $invoice->fresh()->status, 'দৃশ্যটাই বানানো যায়নি — বিক্রি নিজে শেষ হয়ে গেছে।');

        $this->from(route('sales.invoice.show', $invoice))
            ->post(route('sales.invoice.confirm', $invoice))
            ->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $invoice->fresh()->status,
            '⛔ পরিবহন ছাড়া পুরনো সইয়ে থাকা বিক্রি সই পেয়েও বোতামে আটকে রইল।');
    }

    // ── দরজা ৩: অফিসের চালান ─────────────────────────────────────────

    /** ⛔→⭐ একই চালান, একই মানুষ: পরিবহন ছাড়া থামে, গাড়ির নম্বর বসালে পাকা হয়। */
    public function test_the_office_challan_confirms_only_once_the_transport_is_named(): void
    {
        $challan = app(DeliveryChallanService::class)->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'delivered_qty' => '2', 'rate' => '100']]);

        $this->from(route('sales.challan.show', $challan))
            ->post(route('sales.challan.confirm', $challan))
            ->assertSessionHasErrors('transport');

        $this->assertSame(DocumentStatus::DRAFT, $challan->fresh()->status);

        $challan->forceFill(['vehicle_no' => 'চট্ট মেট্রো ন ২২-৪৪৫৫'])->save();

        $this->from(route('sales.challan.show', $challan))
            ->post(route('sales.challan.confirm', $challan))
            ->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CONFIRMED, $challan->fresh()->status);
    }

    // ── কোম্পানি পরিবহনের ঘর বন্ধ রাখলে ─────────────────────────────────

    /** ⛔→⭐ একই কোম্পানি, একই মানুষ: ঘর চালু থাকলে থামে, বন্ধ করলে চলে। */
    public function test_a_company_that_hides_the_transport_fields_is_not_held_to_them(): void
    {
        $this->sell()->assertSessionHasErrors('transport');

        app(SettingsService::class)->set('sales.field_transport', false);

        $this->sell()->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::CONFIRMED, $this->lastChallan()->status);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $extra */
    private function sell(array $extra = []): TestResponse
    {
        return $this->from(route('sales.direct.create'))->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '100']],
            ...$extra,
        ]);
    }

    private function counterDepositFlow(): void
    {
        $flow = \App\Models\ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(),
            'module' => \App\Modules\Accounts\Services\VoucherApproval::MODULE,
            'action' => \App\Modules\Accounts\Services\VoucherApproval::COUNTER_DEPOSIT,
            'document_type' => '',
            'threshold_amount' => null,
            'is_active' => true,
        ]);

        \App\Models\ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => 'user',
            'approver_id' => auth()->id(),
        ]);
    }

    /** @return array<string, mixed>  ব্যাংকে জমা — যেটা সই চায় ([[TheCounterDepositWaitedForItsSignatureTest]]-এর হুবহু) */
    private function bankDeposit(): array
    {
        $bank = \App\Modules\Accounts\Models\Account::query()
            ->ofMoneyKind(\App\Modules\Accounts\Models\Account::BANK)->postable()->active()->orderBy('id')->first();

        if ($bank === null) {
            $sibling = \App\Modules\Accounts\Models\Account::query()
                ->ofMoneyKind(\App\Modules\Accounts\Models\Account::CASH)->postable()->orderBy('id')->firstOrFail();
            $bank = $sibling->replicate(['public_id']);
            $bank->forceFill(['code' => 'BANK-SIGN', 'name_en' => 'BANK-SIGN', 'name_bn' => 'BANK-SIGN',
                'money_kind' => \App\Modules\Accounts\Models\Account::BANK])->save();
        }

        return ['deposits' => [['amount' => '100', 'account_id' => $bank->id, 'reference' => 'TRX-TRANSPORT']]];
    }

    private function lastChallan(): DeliveryChallan
    {
        return DeliveryChallan::query()->latest('id')->firstOrFail();
    }
}
