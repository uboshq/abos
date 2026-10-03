<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * প্রতিটা বিক্রির চালান, নিজের ধাপের ট্যাবে — "ডেলিভারি চালান তালিকা"-র ট্যাব।
 *
 * ⓘ ৩ অক্টোবর ২০২৬: ট্যাবগুলো আগে আলাদা "DO" পাতায় ছিল (`/sales/do`); আসল ডেলিভারি অর্ডারের এখন নিজের
 * ডেস্ক, তাই এগুলো চালান-তালিকায় এল, আর পুরনো ঠিকানা একই ট্যাবে পাঠায়। কাউন্টারের রাখা খসড়া চালান-তালিকায়
 * নয় (মালিক, ২৮ সেপ্টেম্বর) — "খসড়া" ট্যাবে।
 *
 * ⭐ মালিকের সিদ্ধান্ত, ২৮ সেপ্টেম্বর ২০২৬: *"প্রতিটি বিক্রির চালান = একটি DO"*; ট্যাব মালিকের
 * অনুমোদিত নকশার (ধাপ ৩) — নতুন DO · খসড়া · অনুমোদনের অপেক্ষায় · ডেলিভারির অপেক্ষায় ·
 * ডেলিভার্ড · সব DO · বাতিল · DO ট্র্যাকিং। খসড়ার তালিকা এই মেনুর ভিতরে।
 *
 * ⚠️ প্রতিটা দাবি একই DO-কে দুই দিক থেকে দেখে — যে ট্যাবে থাকার কথা সেখানে আছে, আর যেখানে
 * থাকার কথা নয় সেখানে নেই; কেবল "আছে" দেখলে সব ট্যাবে সব দেখানো তালিকাও সবুজ হত।
 */
final class EveryDeliveryOrderSitsInTheTabOfItsStageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $customer;

    private Customer $other;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->other = Customer::query()->whereKeyNot($this->customer->id)->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();

        app(SettingsService::class)->set('customer.credit_limit_enabled', false);
    }

    /** @param  array<string, mixed>  $extra */
    private function sell(Customer $for, array $extra = []): DeliveryChallan
    {
        $this->post(route('sales.direct.store'), [
            'customer_id' => $for->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => '5', 'rate' => '100']],
            // ⓘ পরিবহন বাধ্যতামূলক (ধাপ ৫) — এখানে বিষয় নয়, তাই "ক্রেতার নিজের"
            'own_transport' => '1',
            ...$extra,
        ])->assertSessionHasNoErrors();

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail()->load('lines.challanLine');

        return DeliveryChallan::query()->findOrFail($invoice->lines->first()->challanLine->delivery_challan_id);
    }

    private function tab(string $tab): string
    {
        return $this->get(route('sales.challan.index', ['tab' => $tab]))->assertOk()->getContent();
    }

    public function test_every_sale_sits_in_the_tab_of_its_stage_and_nowhere_it_should_not(): void
    {
        $draft = $this->sell($this->customer, ['save_as_draft' => '1']);
        $sold = $this->sell($this->other, ['save_as_draft' => '0']);

        $this->assertSame('draft', $draft->status, 'প্রস্তুতিটাই ভুল — খসড়া চালান নেই।');
        $this->assertSame('confirmed', $sold->status, 'প্রস্তুতিটাই ভুল — নিশ্চিত চালান নেই।');

        /* ⓵ নিশ্চিত, মাল এখনো যায়নি — কেবল "ডেলিভারির অপেক্ষায়" */
        $this->assertStringContainsString(e($sold->document_no), $this->tab('awaiting'), '⛔ নিশ্চিত DO ডেলিভারির অপেক্ষার ট্যাবে নেই।');
        $this->assertStringNotContainsString(e($sold->document_no), $this->tab('delivered'), '⛔ মাল না যেতেই DO "ডেলিভার্ড"।');
        $this->assertStringNotContainsString(e($draft->document_no), $this->tab('awaiting'), '⛔ খসড়া DO ডেলিভারির অপেক্ষায়।');

        /* ⓶ মাল পৌঁছাল — এখন কেবল "ডেলিভার্ড" */
        app(DeliveryStageService::class)->move($sold, DeliveryStage::DELIVERED, ['receiver_name' => 'রহিম']);

        $this->assertStringContainsString(e($sold->document_no), $this->tab('delivered'), '⛔ পৌঁছানো DO ডেলিভার্ড ট্যাবে নেই।');
        $this->assertStringNotContainsString(e($sold->document_no), $this->tab('awaiting'), '⛔ পৌঁছানোর পরেও অপেক্ষায়।');

        /* ⓷ "সব চালান" — পাকা সব, কাউন্টারের রাখা খসড়া নয় (সেটা "খসড়া" ট্যাবে); বাতিল করা খসড়া কেবল "বাতিল"-এ */
        $all = $this->tab('all');
        $this->assertStringNotContainsString(e($draft->document_no), $all, '⛔ কাউন্টারের রাখা খসড়া চালান-তালিকায় এল।');
        $this->assertStringContainsString(e($sold->document_no), $all);
        $this->get(route('sales.direct.drafts'))->assertOk()->assertSee(e($draft->document_no), false);

        $invoice = SalesInvoice::query()->where('customer_id', $this->customer->id)->latest('id')->firstOrFail();
        $this->post(route('sales.direct.discard', $invoice), ['reason' => 'ক্রেতা আসেননি'])->assertSessionHasNoErrors();

        $this->assertStringContainsString(e($draft->document_no), $this->tab('cancelled'), '⛔ বাতিল DO বাতিল ট্যাবে নেই।');
        $this->assertStringNotContainsString(e($draft->document_no), $this->tab('all'), '⛔ বাতিল DO "সব DO"-তে।');
        $this->assertStringNotContainsString(e($sold->document_no), $this->tab('cancelled'), '⛔ চালু DO বাতিল ট্যাবে।');
    }

    /** ⭐ খসড়ার তালিকা DO মেনুর ভিতরে — খসড়ার পাতাটাই DO-র "খসড়া" ট্যাব, একই ট্যাব-সারিসহ। */
    public function test_the_draft_list_is_the_drafts_tab_of_the_delivery_orders(): void
    {
        $this->sell($this->customer, ['save_as_draft' => '1']);

        $drafts = $this->get(route('sales.direct.drafts'))->assertOk();
        $drafts->assertSee(e(route('sales.challan.index', ['tab' => 'awaiting'])), false);
        $drafts->assertSee(e(route('sales.delivery.index')), false);
        $drafts->assertSee(__('sales::do.tab.new'));

        $page = $this->get(route('sales.challan.index'))->assertOk();
        $page->assertSee(e(route('sales.direct.drafts')), false);
        $page->assertSee(e(route('sales.direct.drafts', ['tab' => 'approval'])), false);

        /* ⓘ পুরনো আদেশের পাতা "বিক্রয় আদেশ" ভাঁজে। (খসড়া তালিকার মেনু-সারি এখন "Billing Documents" ভাঁজে — মালিক, ২ অক্টোবর ২০২৬) */
        $page->assertSee(e(route('sales.order.index')), false);
    }

    /** ⛔ চাবি ছাড়া পাতা বন্ধ — একই লোক, চাবি দিলে খোলে। */
    public function test_the_page_needs_the_challan_view_key(): void
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);

        $this->actingAs($clerk)->get(route('sales.challan.index'))->assertForbidden();
        $this->actingAs($clerk)->get(route('sales.do.index'))->assertForbidden();

        $clerk->givePermissionTo('sales.challan.view');

        // ⓘ পুরনো ঠিকানা একই ট্যাবে পৌঁছায় — বুকমার্ক হারায় না
        $this->actingAs($clerk->fresh())->get(route('sales.do.index', ['tab' => 'delivered', 'q' => 'x']))
            ->assertStatus(301)->assertRedirect(route('sales.challan.index', ['tab' => 'delivered', 'q' => 'x']));

        $this->actingAs($clerk->fresh())->get(route('sales.challan.index'))->assertOk()
            /* ⓘ বেচার চাবি নেই, তাই কাউন্টারের ট্যাবও নেই — চাপলে ৪০৩ দেখানোর চেয়ে না দেখানো */
            ->assertDontSee(e(route('sales.direct.drafts')), false);
    }
}
