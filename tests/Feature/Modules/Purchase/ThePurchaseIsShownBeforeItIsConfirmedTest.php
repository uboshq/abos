<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ কেনার আগে সারাংশ — মালিক, ৪ অক্টোবর ২০২৬: *"নিশ্চিত করুন botam caple ekta overvew dekhabe … sob kichutei"*
 * ([[DirectPurchaseOverview]], [[DirectPurchaseOverviewController]])।
 *
 * দাবি:
 *   একই মানুষ — কেনার চাবি ছাড়া ৪০৩, চাবি দিলে সারাংশ, আর কিছুই লেখা হয় না (বিল, মজুদ — কিছু নয়);
 *   সারাংশের "সরবরাহকারীর বিল" আর একই ঘরে সত্যি জমা দিলে বিলের মোট — হুবহু এক অঙ্ক (সারির ছাড় আর বিলের শতাংশ-ছাড়সহ);
 *   ভুল ঘর বা অঙ্কের দেয়াল — পপ-আপে ভুলের কথা আর "নিশ্চিত হবে না", পুরো পাতা নয়;
 *   সইয়ের ছক না থাকলে সইয়ের কথা নেই, ছক বসালে আছে;
 *   কেনার পাতা ঠিকানা, পপ-আপের খোলস আর "নিশ্চিত" বোতামের চিহ্ন বহন করে।
 */
final class ThePurchaseIsShownBeforeItIsConfirmedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $first;

    private Product $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        [$this->first, $this->second] = Product::query()->orderBy('id')->take(2)->get()->all();
        Product::query()->whereKey([$this->first->id, $this->second->id])->update(['track_batch' => false]);
    }

    public function test_the_same_buyer_needs_the_key_and_the_overview_writes_nothing(): void
    {
        $buyer = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $buyer->companies()->attach($this->company->id, ['is_active' => true]);

        $this->actingAs($buyer->fresh())->post(route('purchase.direct.overview'), $this->form())->assertForbidden();

        CompanyContext::forCompany($this->company->id,
            fn () => $buyer->givePermissionTo(Permission::findOrCreate('purchase.bill.create', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $before = [PurchaseBill::query()->count(), StockMovement::query()->count()];

        $this->actingAs($buyer->fresh())->post(route('purchase.direct.overview'), $this->form())->assertOk()
            ->assertSee('data-overview-blocks="0"', false)
            ->assertSee(__('purchase::overview_confirm.net'));

        $this->assertSame([PurchaseBill::query()->count(), StockMovement::query()->count()], $before,
            '⛔ সারাংশ দেখতে গিয়েই বিল বা মজুদ লেখা হয়ে গেল।');
    }

    public function test_the_overview_shows_the_same_net_the_bill_will_carry(): void
    {
        // ⓘ সইয়ের ছক থাকলে বিল খসড়ায় থামে — অঙ্ক একই থাকে, তবু জমাটা সোজা পথে হোক
        ApprovalFlow::query()->where('module', 'purchase')->where('action', 'bill')->delete();
        $form = $this->form(['bill_discount' => '10', 'bill_discount_mode' => 'percent']);

        $html = $this->post(route('purchase.direct.overview'), $form)->assertOk()->getContent();

        $this->post(route('purchase.direct.store'), $form)->assertRedirect()->assertSessionHasNoErrors();
        $bill = PurchaseBill::query()->latest('id')->firstOrFail();

        // ⓘ ১০ × ৩৭.৩৩ − ১৩ = ৩৬০.৩০, আর ৭ × ১১.১১ = ৭৭.৭৭; মোট ৪৩৮.০৭, ১০% বিলের ছাড় সারিতে ভাগ — একটা গোল-করা অঙ্ক
        $this->assertSame(0, bccomp((string) $bill->total, '394.26', 2), '⛔ পরীক্ষার হিসাবই বদলে গেছে: '.$bill->total);
        $this->assertStringContainsString(Money::format((string) $bill->total), $html,
            '⛔ সারাংশ এক অঙ্ক দেখাল, বিল আরেক অঙ্কে বসল।');
    }

    public function test_a_wrong_form_or_an_impossible_figure_shows_its_mistakes_in_the_popup(): void
    {
        $broken = $this->post(route('purchase.direct.overview'), ['warehouse_id' => $this->warehouse->id],
            ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html'])->assertOk()
            ->assertSee('data-overview-blocks="1"', false)
            ->assertSee(__('overview.not_ready'));
        $this->assertStringNotContainsString('<html', $broken->getContent(), '⛔ পপ-আপে পুরো পাতা ঢুকল।');

        // ⛔ বিলের ছাড় মোটের বেশি — ঘরের নিয়মে পার, অঙ্কের দেয়ালে আটকায় ([[DirectPurchaseService::spreadBillDiscount()]])
        $this->post(route('purchase.direct.overview'), $this->form(['bill_discount' => '100000']))->assertOk()
            ->assertSee('data-overview-blocks="1"', false)
            ->assertSee(__('overview.not_ready'));
    }

    public function test_the_signature_is_mentioned_only_when_a_flow_asks_for_it(): void
    {
        ApprovalFlow::query()->where('module', 'purchase')->where('action', 'bill')->delete();

        $this->post(route('purchase.direct.overview'), $this->form())->assertOk()
            ->assertDontSee(__('purchase::overview_confirm.signature'));

        $flow = ApprovalFlow::create(['module' => 'purchase', 'action' => 'bill', 'is_active' => true]);
        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->owner->id,
        ]);
        // ⓘ ইঞ্জিন ছকগুলো একবার পড়ে মনে রাখে; পরীক্ষায় অ্যাপটা দুই অনুরোধ জুড়ে বাঁচে, লাইভে প্রতিটা অনুরোধ নতুন
        app()->forgetInstance(\App\Core\Engines\Approval\ApprovalEngine::class);

        $this->post(route('purchase.direct.overview'), $this->form())->assertOk()
            ->assertSee(__('purchase::overview_confirm.signature'));
    }

    public function test_the_purchase_page_carries_the_popup_and_the_overview_address(): void
    {
        $this->get(route('purchase.direct.create'))->assertOk()
            ->assertSee('data-confirm-overview="'.route('purchase.direct.overview').'"', false)
            ->assertSee('data-confirm-overview-dialog', false)
            ->assertSee('data-overview-trigger', false)
            ->assertDontSee('data-overview-draft', false);
    }

    /** @param  array<string, mixed>  $overrides */
    private function form(array $overrides = []): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'OV-'.fake()->unique()->numberBetween(1000, 9999),
            'lines' => [
                ['product_id' => $this->first->id, 'qty' => '10', 'rate' => '37.33', 'discount' => '13', 'sales_price' => '50'],
                ['product_id' => $this->second->id, 'qty' => '7', 'rate' => '11.11', 'sales_price' => '15'],
            ],
            ...$overrides,
        ];
    }
}
