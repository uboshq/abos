<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Bulk\BulkOutcome;
use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\AllBranchesView;
use App\Core\Services\DataScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\SalesOrderStatus as S;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ বিক্রয়ের তালিকা থেকে একসাথে কাজ — তালিকার একসাথে কাজ, ধাপ ২ (মালিক, ১৯ নভেম্বর ২০২৬, নমুনা Q2W6mM3S v3; [[SalesBulkController]])।
 *
 * দাবি:
 *   · "অনুমোদনে পাঠান" খসড়া আদেশকে আদেশের নিজের "নিশ্চিত" দরজা দিয়ে পাঠায় (ছক নেই → নিশ্চিত, ছক আছে → সইয়ের অপেক্ষায়); খসড়া নয় এমনটা
 *     কারণসহ বাদ।
 *   · চাবি ছাড়া পুরো অনুরোধ ৪০৩ — চার কাগজেই; অন্য শাখার কাগজ "পাওয়া গেল না", ছোঁয়াও হয় না।
 *   · কারণ ছাড়া বাতিল হয় না; কারণসহ খসড়া বাতিল, কারণটা কাগজে।
 *   · নিজের কাগজ নিজে অনুমোদন নয় (মালিক ছাড়া); মালিক নিজের কাগজ অনুমোদন দেন।
 *   · তালিকার টিক-বাক্স আর বার কেবল ABOS রূপে — SAP (`tiles`) আর Dynamic-এ নেই।
 *
 * ⓘ দাবিগুলো ডাটাবেসের অবস্থা দেখে — কেবল ফেরার কোড নয়।
 */
final class SalesListsActOnPickedPapersTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $customer;

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
        app(SettingsService::class)->set('customer.credit_limit_enabled', true);
        app(SettingsService::class)->set(SalesOrderService::REPLACES_DO, true);

        // ⓘ আদেশের কোনো ছক আগে থেকে নয় — কোন দাবিতে সই লাগবে তা দাবি নিজে বসায়
        ApprovalFlow::query()->where('module', 'sales')->where('action', SalesOrderService::APPROVAL_ACTION)->update(['is_active' => false]);
        app()->forgetInstance(ApprovalEngine::class);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '100000000'])->save();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->where('is_active', true)
            ->where('sale_price', '>', 0)->orderBy('id')->firstOrFail();

        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse,
            sourceType: StockService::ADJUSTMENT, sourceId: $this->product->id, floor: '100',
        );
    }

    /** ⭐ খসড়া যায়, পাকা কাগজ "খসড়া নয়" বলে বাদ — ছক নেই, তাই নিশ্চিত */
    public function test_submit_sends_the_drafts_and_skips_what_is_not_a_draft(): void
    {
        $clerk = $this->staff(['sales.order.view', 'sales.order.create', 'sales.order.update']);
        $this->actingAs($clerk);

        $first = $this->draft();
        $second = $this->draft();
        $cancelled = $this->draft();
        app(SalesOrderService::class)->cancel($cancelled, 'দৃশ্যের জন্য');

        $this->post(route('sales.bulk', ['paper' => 'order', 'action' => 'submit']), [
            'ids' => [$first->id, $second->id, $cancelled->id],
        ])->assertRedirect(route('sales.order.index'))->assertSessionHas(BulkOutcome::FLASH);

        $this->assertSame(S::CONFIRMED, $first->fresh()->status, '⛔ বাছা খসড়া আদেশ পাঠানো হয়নি।');
        $this->assertSame(S::CONFIRMED, $second->fresh()->status, '⛔ বাছা দ্বিতীয় খসড়া পাঠানো হয়নি।');
        $this->assertNotNull($first->fresh()->submitted_at, '⛔ আদেশের নিজের জমার দরজা দিয়ে যায়নি (জমার সময় নেই)।');
        $this->assertSame(DocumentStatus::CANCELLED, $cancelled->fresh()->status);

        $details = (string) session(BulkOutcome::FLASH)['details'];
        $this->assertStringContainsString((string) $cancelled->document_no.': '.__('core.bulk.why.not_draft'), $details,
            '⛔ বাতিল আদেশটা কেন বাদ গেল তা বলা নেই।');
        $this->assertStringNotContainsString((string) $first->document_no, $details);
    }

    /** ⛔ চাবি ছাড়া কিছুই নয় — চার কাগজেই ৪০৩, আর খসড়া খসড়াই থাকে */
    public function test_a_user_without_the_key_is_refused_for_every_paper(): void
    {
        $order = $this->draft();

        // ⓘ কেবল দেখার চাবি — পাঠানো, বাতিল, অনুমোদনের চাবি নেই
        $viewer = $this->staff(['sales.order.view', 'sales.challan.view', 'sales.invoice.view', 'sales.return.view']);
        $this->actingAs($viewer);

        foreach (['order', 'challan', 'invoice', 'return'] as $paper) {
            foreach (['submit', 'cancel', 'approve'] as $action) {
                $this->post($action === 'approve' ? route('sales.bulk.approve', ['paper' => $paper]) : route('sales.bulk', ['paper' => $paper, 'action' => $action]),
                    ['ids' => [$order->id], 'reason' => 'x'])
                    ->assertForbidden();
            }
        }

        // ⓘ কাজের চাবি আছে, তালিকা দেখার চাবি নেই — তবুও ৪০৩
        $this->actingAs($this->staff(['sales.order.update', 'sales.order.cancel']));
        $this->post(route('sales.bulk', ['paper' => 'order', 'action' => 'submit']), ['ids' => [$order->id]])->assertForbidden();

        $this->assertSame(DocumentStatus::DRAFT, $order->fresh()->status, '⛔ চাবি ছাড়া আদেশ নড়ল।');
    }

    /** ⛔ অন্য শাখার কাগজ — "পাওয়া গেল না", আর অবস্থা একটুও বদলায় না */
    public function test_a_paper_in_another_branch_is_not_found_and_left_alone(): void
    {
        $here = $this->draft();
        $there = $this->draft();
        $other = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'BLK2', 'name_en' => 'Bulk branch two', 'is_active' => true]);
        DB::table('sal_orders')->where('id', $there->id)->update(['branch_id' => $other->id]);

        $clerk = $this->staff(['sales.order.view', 'sales.order.create', 'sales.order.update']);
        $clerk->forceFill(['view_all_branches' => false])->save();
        $this->actingAs($clerk->fresh());

        $this->post(route('sales.bulk', ['paper' => 'order', 'action' => 'submit']), ['ids' => [$here->id, $there->id]])
            ->assertRedirect();

        $this->assertSame(S::CONFIRMED, $here->fresh()->status);
        $this->assertSame(DocumentStatus::DRAFT, SalesOrder::query()->withoutGlobalScopes()->findOrFail($there->id)->status,
            '⛔ অন্য শাখার আদেশ একসাথে পাঠানোয় নড়ল।');
        $this->assertStringContainsString('#'.$there->id.': '.__('core.bulk.why.not_found'),
            (string) session(BulkOutcome::FLASH)['details'], '⛔ অন্য শাখার আদেশ "পাওয়া গেল না" বলে বাদ যায়নি।');
    }

    /** ⛔ কারণ ছাড়া বাতিল নয়; কারণসহ খসড়া বাতিল, কারণটা কাগজে */
    public function test_cancel_needs_a_reason_and_keeps_it(): void
    {
        $order = $this->draft();
        $this->actingAs($this->staff(['sales.order.view', 'sales.order.cancel']));

        $this->post(route('sales.bulk', ['paper' => 'order', 'action' => 'cancel']), ['ids' => [$order->id], 'reason' => '  '])
            ->assertRedirect();
        $this->assertSame(DocumentStatus::DRAFT, $order->fresh()->status, '⛔ কারণ ছাড়া আদেশ বাতিল হলো।');
        $this->assertStringContainsString(__('core.bulk.why.no_reason'), (string) session(BulkOutcome::FLASH)['details']);

        $this->post(route('sales.bulk', ['paper' => 'order', 'action' => 'cancel']), ['ids' => [$order->id]])->assertRedirect();
        $this->assertSame(DocumentStatus::DRAFT, $order->fresh()->status, '⛔ কারণের ঘর ছাড়াই আদেশ বাতিল হলো।');

        $this->post(route('sales.bulk', ['paper' => 'order', 'action' => 'cancel']), ['ids' => [$order->id], 'reason' => 'গ্রাহক আর নেবেন না'])
            ->assertRedirect();
        $fresh = $order->fresh();
        $this->assertSame(DocumentStatus::CANCELLED, $fresh->status, '⛔ কারণসহ বাছা খসড়া বাতিল হয়নি।');
        $this->assertSame('গ্রাহক আর নেবেন না', $fresh->cancel_reason);
    }

    /** ⛔ নিজের কাগজ নিজে অনুমোদন নয় — একসাথে পাঠানো আদেশ সইয়ের অপেক্ষায়, বানানেওয়ালার "অনুমোদন দিন" বাদ */
    public function test_the_maker_cannot_approve_his_own_order(): void
    {
        $maker = $this->staff(['sales.order.view', 'sales.order.create', 'sales.order.update', 'approval.decide']);
        $this->flow($maker);
        $this->actingAs($maker);

        $order = $this->draft();
        $this->post(route('sales.bulk', ['paper' => 'order', 'action' => 'submit']), ['ids' => [$order->id]])->assertRedirect();
        $this->assertSame(S::AWAITING_APPROVAL, $order->fresh()->status, '⛔ ছক থাকা সত্ত্বেও একসাথে পাঠানো আদেশ সইয়ে যায়নি।');
        $approval = $this->pending($order);
        $this->assertNotNull($approval, 'প্রস্তুতিটাই ভুল — আদেশের অনুরোধ নেই।');
        $this->assertSame((int) $maker->id, (int) $approval->requested_by);

        // ⓘ দ্বিতীয়বার পাঠানো নয়
        $this->post(route('sales.bulk', ['paper' => 'order', 'action' => 'submit']), ['ids' => [$order->id]])->assertRedirect();
        $this->assertSame(1, Approval::query()->where('approvable_type', SalesOrder::class)->where('approvable_id', $order->id)->count());

        $this->post(route('sales.bulk.approve', ['paper' => 'order']), ['ids' => [$order->id]])->assertRedirect();

        $this->assertSame(Approval::PENDING, $approval->fresh()->status, '⛔ বানানেওয়ালা নিজের আদেশ নিজে অনুমোদন দিলেন।');
        $this->assertSame(S::AWAITING_APPROVAL, $order->fresh()->status);
        $this->assertStringContainsString((string) $order->document_no.': '.__('core.bulk.why.own_paper'),
            (string) session(BulkOutcome::FLASH)['details']);
    }

    /** ⭐ মালিক নিজের কাগজ নিজে অনুমোদন দেন (২০ নভেম্বর ২০২৬) */
    public function test_the_owner_approves_his_own_order(): void
    {
        $this->flow($this->owner);
        $this->actingAs($this->owner);

        $order = $this->draft();
        $this->post(route('sales.bulk', ['paper' => 'order', 'action' => 'submit']), ['ids' => [$order->id]])->assertRedirect();
        $approval = $this->pending($order);
        $this->assertNotNull($approval, 'প্রস্তুতিটাই ভুল — আদেশ সইয়ে যায়নি।');

        $this->post(route('sales.bulk.approve', ['paper' => 'order']), ['ids' => [$order->id]])->assertRedirect();

        $this->assertSame(Approval::APPROVED, $approval->fresh()->status, '⛔ মালিক নিজের আদেশ একসাথে অনুমোদন দিতে পারলেন না।');
        $this->assertNotSame(S::AWAITING_APPROVAL, $order->fresh()->status, '⛔ অনুমোদনের পরেও আদেশ সইয়ের অপেক্ষায়।');
        $this->assertNull(session(BulkOutcome::FLASH)['details'] ?? null);
    }

    /**
     * ⭐ "সব শাখা" = কেবল দেখা (মালিক, ২০ নভেম্বর ২০২৬) — একসাথে পাঠানো থামে, কিন্তু একসাথে অনুমোদন চলে, একটা একটা অনুমোদনের মতো
     *   ([[AllBranchesView::ALLOWED]]: `sales.bulk.approve`)।
     */
    public function test_in_the_all_branches_view_bulk_approve_runs_but_submit_waits_for_a_branch(): void
    {
        $this->flow($this->owner);
        $order = $this->draft();
        $this->post(route('sales.bulk', ['paper' => 'order', 'action' => 'submit']), ['ids' => [$order->id]])->assertRedirect();
        $approval = $this->pending($order);
        $this->assertNotNull($approval, 'প্রস্তুতিটাই ভুল — আদেশ সইয়ে যায়নি।');
        $draft = $this->draft();

        // ⓘ পরীক্ষার ঘরে নিয়মটা বন্ধ থাকে ([[TestCase]]) — যে দাবি এটা মাপে, সে নিজে চালু করে
        config(['abos.branch_pin.all_branches_read_only' => true]);
        $this->post(route('branch.switch'), ['branch_id' => 'all'])->assertRedirect();
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner->fresh());
        $this->assertTrue(app(AllBranchesView::class)->on($this->owner->fresh()), 'প্রস্তুতিটাই ভুল — মালিক "সব শাখা"-তে নেই।');

        $this->post(route('sales.bulk', ['paper' => 'order', 'action' => 'submit']), ['ids' => [$draft->id]]);
        $this->assertSame(S::DRAFT, $draft->fresh()->status, '⛔ "সব শাখা"-তে একসাথে পাঠানো লেখা হলো।');

        $this->post(route('sales.bulk.approve', ['paper' => 'order']), ['ids' => [$order->id]])->assertRedirect();
        $this->assertSame(Approval::APPROVED, $approval->fresh()->status, '⛔ "সব শাখা"-তে একসাথে অনুমোদন থামল — একটা একটা অনুমোদন তো চলে।');
    }

    /** ⭐ টিক-বাক্স আর বার কেবল ABOS রূপে — SAP আর Dynamic-এ চার তালিকার কোনোটাতেই নেই */
    public function test_the_pick_column_and_bar_show_only_in_the_abos_looks(): void
    {
        $this->draft();
        $lists = ['order' => 'sales.order.index', 'challan' => 'sales.challan.index', 'invoice' => 'sales.invoice.index', 'return' => 'sales.return.index'];

        foreach (['rose' => true, 'tiles' => false, 'dynamic' => false] as $look => $shows) {
            $this->owner->forceFill(['ui' => $look])->save();
            $this->actingAs($this->owner->fresh());

            foreach ($lists as $paper => $route) {
                $page = $this->get(route($route))->assertOk();
                $form = 'id="sales-'.$paper.'-bulk"';

                if ($shows) {
                    $page->assertSee($form, false)->assertSee('data-bulk-form', false)
                        ->assertSee(route('sales.bulk', ['paper' => $paper, 'action' => 'submit']), false);
                } else {
                    $page->assertDontSee($form, false)->assertDontSee('data-bulk-pick', false)->assertDontSee('sales-'.$paper.'-bulk', false);
                }
            }

            // ⓘ আদেশের খসড়াটার সারিতে টিক-বাক্স — ABOS-এ আছে, বাকিগুলোয় নেই
            $orders = $this->get(route('sales.order.index'));
            $shows ? $orders->assertSee('form="sales-order-bulk"', false) : $orders->assertDontSee('form="sales-order-bulk"', false);
        }
    }

    /** ⛔ যাঁর কোনো কাজ খোলা নেই (কেবল দেখার চাবি, রপ্তানিও নয়) — বারও নেই, বারহীন টিক-বাক্সও নয় ([[BulkRun::barOpen()]]) */
    public function test_a_viewer_with_nothing_to_do_gets_no_pick_boxes(): void
    {
        $this->draft();
        $viewer = $this->staff(['sales.order.view']);
        $viewer->forceFill(['ui' => 'rose'])->save();
        $this->actingAs($viewer->fresh());

        $this->get(route('sales.order.index'))->assertOk()
            ->assertDontSee('data-bulk-pick', false)
            ->assertDontSee('id="sales-order-bulk"', false);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function draft(): SalesOrder
    {
        return app(SalesOrderService::class)->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'ordered_qty' => '2', 'rate' => (string) $this->product->sale_price]],
        )->fresh();
    }

    private function pending(SalesOrder $order): ?Approval
    {
        return Approval::query()->where('approvable_type', SalesOrder::class)->where('approvable_id', $order->id)
            ->where('status', Approval::PENDING)->first();
    }

    private function flow(User $signer): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'code' => 'BLK-SO', 'module' => 'sales',
            'action' => SalesOrderService::APPROVAL_ACTION, 'document_type' => 'SalesOrder', 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'এরিয়া ম্যানেজার',
            'approver_type' => 'user', 'approver_id' => $signer->id,
        ]);
        app()->forgetInstance(ApprovalEngine::class);
    }

    /** @param  list<string>  $keys */
    private function staff(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id,
            'current_branch_id' => $this->company->defaultBranch()?->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        foreach ($keys as $key) {
            CompanyContext::forCompany($this->company->id,
                fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
