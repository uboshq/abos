<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Documents\Models\Document;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Modules\Documents\MakesDocuments;
use Tests\TestCase;

/**
 * ⛔ মালিকের অভিযোগ (২৩–২৪ অক্টোবর ২০২৬): "নোটিফিকেশনে ক্লিক করলে দেখা যায় না" — পর্দায় "৪০৪ — এই ঠিকানায় কিছু নেই"।
 *
 * <p>নোটিফিকেশনের দরজা ([[NotificationService::mayOpen()]]) মানুষের শাখার **নাগাল** দেখে লিংকে পাঠায়, কিন্তু কাগজের পাতা
 * মডেলটা খুঁজত [[ScopedToUserBranch]]-এর `user-branch` স্কোপে — হেডারে একটা শাখা বাছা থাকলে কেবল সেই শাখা। তাই মালিক হেডারে
 * MMS রেখে NTK-র ক্রয় বিল, পরিশোধ, মাল গ্রহণ, ফেরত, ক্রয় আদেশ, আদায়, চালান, বিক্রয় আদেশ, ডকুমেন্ট বা বাকির পাতার নোটিশ চাপলে
 * ৪০৪ পেতেন। ভাউচারের সারাই ([[OpensInReach]]) এখন এই কাগজগুলোতেও: খোঁজা সব শাখা জুড়ে, খোলা নাগাল ধরে।
 *
 * দাবি, প্রতিটা কাগজে:
 *  - মালিক, হেডারে MMS: NTK-র কাগজের পাতা ২০০, আর পাতায় ঠিক সেই কাগজের নম্বর।
 *  - MMS-এ সীমিত কেরানি (দেখার চাবিসহ): NTK-র কাগজ ৪০৪ (নাগাল ভাঙে না), MMS-এর কাগজ ২০০।
 */
final class APaperOpensInReachNotInTheHeaderTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        Customer::query()->orderBy('id')->firstOrFail()->forceFill(['credit_limit' => '100000'])->save();
    }

    public function test_a_purchase_bill_opens_in_reach(): void
    {
        $this->assertOpensInReach('purchase.bill.show', 'purchase.bill.view', fn () => app(PurchaseBillService::class)->create(
            $this->purchaseHead(), [['product_id' => $this->purchaseProduct(), 'qty' => '10', 'rate' => '100']],
        ), 'ক্রয় বিল');
    }

    public function test_a_supplier_payment_opens_in_reach(): void
    {
        $this->assertOpensInReach('purchase.payment.show', 'purchase.payment.view', fn () => app(PaymentService::class)->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->value('id'), 'trx_date' => now()->toDateString(), 'amount' => '100'], [],
        ), 'সরবরাহকারীর পরিশোধ');
    }

    public function test_a_goods_receipt_opens_in_reach(): void
    {
        $this->assertOpensInReach('purchase.receipt.show', 'purchase.receipt.view', fn () => app(PurchaseReceiptService::class)->create(
            $this->purchaseHead(), [['product_id' => $this->purchaseProduct(), 'received_qty' => '10', 'rate' => '100']],
        ), 'মাল গ্রহণ');
    }

    public function test_a_purchase_return_opens_in_reach(): void
    {
        $this->assertOpensInReach('purchase.return.show', 'purchase.return.view', fn () => app(PurchaseReturnService::class)->create(
            $this->purchaseHead(), [['product_id' => $this->purchaseProduct(), 'qty' => '2', 'rate' => '100']],
        ), 'ক্রয় ফেরত');
    }

    public function test_a_purchase_order_opens_in_reach(): void
    {
        $this->assertOpensInReach('purchase.order.show', 'purchase.order.view', fn () => app(PurchaseOrderService::class)->create(
            $this->purchaseHead(), [['product_id' => $this->purchaseProduct(), 'ordered_qty' => '10', 'rate' => '100']],
        ), 'ক্রয় আদেশ');
    }

    public function test_a_collection_opens_in_reach(): void
    {
        $this->assertOpensInReach('sales.collection.show', 'sales.collection.view', fn () => app(CollectionService::class)->create(
            ['customer_id' => $this->customerId(), 'trx_date' => now()->toDateString(), 'amount' => '50', 'instrument' => 'cash'], [],
        ), 'আদায়');
    }

    public function test_a_delivery_challan_opens_in_reach(): void
    {
        $this->assertOpensInReach('sales.challan.show', 'sales.challan.view', fn () => app(DeliveryChallanService::class)->create([
            'customer_id' => $this->customerId(),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => $this->salesProduct(), 'delivered_qty' => '1', 'rate' => '10']]), 'চালান');
    }

    public function test_a_sales_order_opens_in_reach(): void
    {
        $this->assertOpensInReach('sales.order.show', 'sales.order.view', fn () => app(SalesOrderService::class)->create(
            ['customer_id' => $this->customerId(), 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => $this->salesProduct(), 'ordered_qty' => '1', 'rate' => '10']],
        ), 'বিক্রয় আদেশ');
    }

    /** ⓘ ডকুমেন্ট নিজের দেয়ালে খোলে ([[Document::resolveRouteBinding()]]) — গোপনীয়তার দেয়াল থাকে, শাখা এখন নাগাল ধরে */
    public function test_a_document_opens_in_reach(): void
    {
        $there = $this->upload('Netrakona lease');
        DB::table($there->getTable())->where('id', $there->id)->update(['branch_id' => $this->netrakona->id]);
        $here = $this->upload('Mymensingh lease');
        $this->assertSame((int) $this->main->id, (int) $here->branch_id, 'প্রস্তুতিটাই ভুল — MMS-এর ডকুমেন্ট MMS-এ বসেনি');

        $this->pinTo($this->owner, $this->main);
        $this->assertNull(Document::query()->find($there->id),
            'প্রস্তুতিটাই ভুল — হেডারে MMS বাছা, তবু শাখার ছাঁকনি NTK-র ডকুমেন্ট দেখায়; তাহলে পুরনো বাঁধনও খুলত');
        $page = $this->get(route('documents.show', $there->id));
        $this->assertSame(200, $page->status(), '⛔ হেডারে MMS বাছা মালিক NTK-র ডকুমেন্ট খুলতে পারলেন না (নোটিশের ৪০৪)');
        $this->assertStringContainsString('Netrakona lease', (string) $page->getContent(), '⛔ মালিক ডকুমেন্টের পাতা পেলেন, কিন্তু সেই ডকুমেন্টটা নয়');

        $clerk = $this->person('dms-clerk@abos.test', ['documents.view', 'documents.download', 'documents.print'], limitedTo: $this->main);
        $this->pinTo($clerk, null);
        $this->assertSame(200, $this->get(route('documents.show', $here->id))->status(), 'প্রস্তুতিটাই ভুল — MMS-এ সীমিত কেরানি নিজের শাখার ডকুমেন্টই খুলতে পারলেন না');
        $this->assertSame(404, $this->get(route('documents.show', $there->id))->status(), '⛔ MMS-এ সীমিত কেরানি NTK-র ডকুমেন্ট খুলে ফেললেন — নাগাল ভাঙল');
    }

    /** ⓘ বাকির সীমা ফুরোনোর নোটিশ (`credit_control.temporary_limit_ending`) গ্রাহকের বাকির পাতায় আনে */
    public function test_a_customers_credit_profile_opens_in_reach(): void
    {
        [$here, $there] = Customer::query()->orderBy('id')->take(2)->get()->all();
        DB::table($here->getTable())->where('id', $here->id)->update(['branch_id' => $this->main->id]);
        DB::table($there->getTable())->where('id', $there->id)->update(['branch_id' => $this->netrakona->id]);

        $this->pinTo($this->owner, $this->main);
        $page = $this->get(route('credit_control.customer.show', $there->id));
        $this->assertSame(200, $page->status(), '⛔ হেডারে MMS বাছা মালিক NTK-র গ্রাহকের বাকির পাতা খুলতে পারলেন না (নোটিশের ৪০৪)');
        $html = (string) $page->getContent();
        $this->assertTrue(
            ($there->name_en !== null && $there->name_en !== '' && str_contains($html, e($there->name_en)))
            || ($there->name_bn !== null && $there->name_bn !== '' && str_contains($html, e($there->name_bn))),
            '⛔ মালিক বাকির পাতা পেলেন, কিন্তু সেই গ্রাহকের নয়',
        );

        $clerk = $this->person('credit-clerk@abos.test', ['credit_control.view'], limitedTo: $this->main);
        $this->pinTo($clerk, null);
        $this->assertSame(200, $this->get(route('credit_control.customer.show', $here->id))->status(), 'প্রস্তুতিটাই ভুল — MMS-এ সীমিত কেরানি নিজের শাখার গ্রাহকের পাতাই খুলতে পারলেন না');
        $this->assertSame(404, $this->get(route('credit_control.customer.show', $there->id))->status(), '⛔ MMS-এ সীমিত কেরানি NTK-র গ্রাহকের বাকির পাতা খুলে ফেললেন — নাগাল ভাঙল');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * একই ধরনের দুইটা কাগজ — একটা NTK-তে, একটা MMS-এ; মালিক হেডারে MMS, কেরানি MMS-এ সীমিত।
     *
     * @param  \Closure(): Model  $make
     */
    private function assertOpensInReach(string $route, string $viewKey, \Closure $make, string $what): void
    {
        $there = $this->paperIn($make, $this->netrakona);
        $here = $this->paperIn($make, $this->main);
        $number = (string) $there->getAttribute('document_no');
        $this->assertNotSame('', $number, 'প্রস্তুতিটাই ভুল — '.$what.'-এর নম্বর নেই');

        $this->pinTo($this->owner, $this->main);
        $this->assertNull($there::query()->find($there->getKey()),
            'প্রস্তুতিটাই ভুল — হেডারে MMS বাছা, তবু শাখার ছাঁকনি NTK-র '.$what.' দেখায়; তাহলে পুরনো বাঁধনও খুলত');
        $page = $this->get(route($route, $there->getKey()));
        $this->assertSame(200, $page->status(), '⛔ হেডারে MMS বাছা মালিক NTK-র '.$what.' খুলতে পারলেন না (নোটিশের ৪০৪)');
        $this->assertStringContainsString(e($number), (string) $page->getContent(), '⛔ মালিক '.$what.'-এর পাতা পেলেন, কিন্তু সেই কাগজটা নয়');

        $clerk = $this->person('clerk-'.str_replace('.', '-', $viewKey).'@abos.test', [$viewKey], limitedTo: $this->main);
        $this->pinTo($clerk, null);
        $this->assertSame(200, $this->get(route($route, $here->getKey()))->status(), 'প্রস্তুতিটাই ভুল — MMS-এ সীমিত কেরানি নিজের শাখার '.$what.'-ই খুলতে পারলেন না');
        $this->assertSame(404, $this->get(route($route, $there->getKey()))->status(), '⛔ MMS-এ সীমিত কেরানি NTK-র '.$what.' খুলে ফেললেন — নাগাল ভাঙল');
    }

    /** @param  \Closure(): Model  $make */
    private function paperIn(\Closure $make, Branch $branch): Model
    {
        $this->asOwnerInAllBranches();
        $paper = $make();

        // ⓘ কেবল কাগজের শাখা — খোলার নিয়মটাই পরখের বিষয়, খাতা নয় (খসড়া, খাতায় কিছু বসেনি)
        DB::table($paper->getTable())->where('id', $paper->getKey())->update(['branch_id' => $branch->id]);

        return $paper::acrossBranches()->findOrFail($paper->getKey());
    }

    /** কাগজ বানানো হয় মালিকের হাতে, "সব শাখা"-তে */
    private function asOwnerInAllBranches(): void
    {
        $this->owner->forceFill(['view_all_branches' => true])->save();
        $this->useCompany();
        app(DataScope::class)->forget();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner->fresh());
    }

    /** হেডারে একটা শাখা বাছা (null = "সব শাখা") */
    private function pinTo(User $user, ?Branch $branch): void
    {
        $user->forceFill(['view_all_branches' => $branch === null, 'current_branch_id' => $branch?->id ?? $user->current_branch_id])->save();
        CompanyContext::set($this->company->id, $branch?->id ?? $this->company->defaultBranch()?->id);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh());
    }

    /** @return array<string, mixed> */
    private function purchaseHead(): array
    {
        return ['supplier_id' => Supplier::query()->orderBy('id')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString()];
    }

    private function purchaseProduct(): int
    {
        return (int) Product::query()->orderBy('id')->value('id');
    }

    private function salesProduct(): int
    {
        return (int) Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id;
    }

    private function customerId(): int
    {
        return (int) Customer::query()->orderBy('id')->firstOrFail()->id;
    }
}
