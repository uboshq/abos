<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\TrackingNotices;
use App\Modules\Sales\Support\SalesOrderStatus as S;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ "বিক্রয় আদেশ আপনার সইয়ের অপেক্ষায়" খবর সইকারীকে আদেশের পাতায় পাঠাত, সইয়ের পাতায় নয় (২৪ অক্টোবর ২০২৬)।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * [[TrackingNotices::orderAwaitsYou()]] প্রাপক বাছে সই দিতে পারেন কি না ধরে ([[ApprovalEngine::canDecide()]]), অথচ লিংক
 * ছিল `sales.order.show` — যেটা খোলে `sales.order.view` থাকলে। ⚠️ সইকারীর ঐ চাবি না থাকলে খবর চাপলেই ৪০৩, আর থাকলেও ঐ
 * পাতায় সইয়ের বোতাম নেই: তিনি আদেশ দেখে আবার অনুমোদনের তালিকায় গিয়ে কাগজটা খুঁজতেন।
 *
 * ── ⭐ দাবি ──────────────────────────────────────────────────────────
 * খবরের লিংক আদেশের অপেক্ষার অনুরোধের পাতা (`approval.inbox.show`); খবর খোলার দরজা দিয়ে চাপলে সেখানে যায়, আর পাতাটা
 * সইকারীকে খোলে (২০০, আদেশের নম্বরসহ) — আদেশ দেখার চাবি ছাড়াই।
 */
final class AnOrderAwaitingASignatureOpensTheSigningPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->staff(['sales.order.view', 'sales.order.create']));

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
        app(SettingsService::class)->set('customer.credit_limit_enabled', true);
        app(SettingsService::class)->set(SalesOrderService::REPLACES_DO, true);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '100000000'])->save();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->where('is_active', true)
            ->where('sale_price', '>', 0)->orderBy('id')->firstOrFail();

        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse,
            sourceType: StockService::ADJUSTMENT, sourceId: $this->product->id, floor: '50',
        );
    }

    public function test_the_signer_is_sent_to_the_signing_page_of_that_order(): void
    {
        // ⓘ কেবল সইয়ের চাবি — আদেশ দেখার চাবি নেই
        $supervisor = $this->staff(['approval.decide']);
        $this->flow($supervisor);

        $orders = app(SalesOrderService::class);
        $order = $orders->submit($orders->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'ordered_qty' => '4', 'rate' => (string) $this->product->sale_price]],
        )->fresh(['lines']))->fresh();
        $this->assertSame(S::AWAITING_APPROVAL, $order->status, 'প্রস্তুতিটাই ভুল — আদেশ সইয়ের অপেক্ষায় নেই।');

        $approval = app(ApprovalEngine::class)->latestFor($order, SalesOrderService::APPROVAL_ACTION);
        $this->assertNotNull($approval, 'প্রস্তুতিটাই ভুল — আদেশের অনুরোধ নেই।');

        $note = Notification::query()->where('user_id', $supervisor->id)->where('type', TrackingNotices::ORDER_AWAITS)->first();
        $this->assertNotNull($note, 'প্রস্তুতিটাই ভুল — সইকারী খবরই পাননি।');
        $this->assertSame(route('approval.inbox.show', $approval->id), $note->url,
            '⛔ "সইয়ের অপেক্ষায়" খবর আদেশের পাতায় পাঠায়, যেখানে সইয়ের বোতাম নেই আর চাবি না থাকলে ৪০৩।');

        $this->app['auth']->forgetGuards();
        $this->actingAs($supervisor->fresh());

        $opened = $this->call('GET', route('notifications.open', $note));
        $this->assertSame(302, $opened->status(), '⛔ খবর খোলার দরজা সইকারীকে গন্তব্যে পাঠায়নি।');
        $this->assertSame(route('approval.inbox.show', $approval->id), $opened->headers->get('Location'), '⛔ খবর খুলে সইয়ের পাতায় যায় না।');
        $this->assertNotNull($note->fresh()->read_at, '⛔ খবর খুলে পড়া হিসেবে বসেনি।');

        $page = $this->call('GET', route('approval.inbox.show', $approval->id));
        $this->assertSame(200, $page->status(), '⛔ সইকারী আদেশের সইয়ের পাতা খুলতে পারেন না।');
        $this->assertStringContainsString((string) SalesOrder::query()->withoutGlobalScopes()->findOrFail($order->id)->document_no,
            (string) $page->getContent(), '⛔ সইয়ের পাতায় আদেশের নম্বর নেই।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function flow(User $supervisor): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'code' => 'ZQ-SO', 'module' => 'sales',
            'action' => SalesOrderService::APPROVAL_ACTION, 'document_type' => 'SalesOrder', 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'এরিয়া ম্যানেজার',
            'approver_type' => 'user', 'approver_id' => $supervisor->id,
        ]);
        app()->forgetInstance(ApprovalEngine::class);
    }

    /** @param  list<string>  $keys */
    private function staff(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        foreach ($keys as $key) {
            CompanyContext::forCompany($this->company->id,
                fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
