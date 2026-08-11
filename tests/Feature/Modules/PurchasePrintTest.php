<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Print\PaperSize;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * ক্রয়ের কাগজ — §১২-এর যে শর্তটা Purchase-এ কখনো পূরণ হয়নি।
 *
 * Sales-এ সাতটা কাগজ ছিল, Purchase-এ একটাও নয়। ফলে অর্ডার হাতে নিয়ে
 * সরবরাহকারীর কাছে যাওয়া, বা ফেরতের কাগজ মালের সাথে পাঠানো — দুইটাই
 * অসম্ভব ছিল, অথচ দ্বিতীয়টা ছাড়া "পাঠিয়েছি" বনাম "পাইনি" ঝগড়ার কোনো
 * নিষ্পত্তি নেই।
 */
class PurchasePrintTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();
    }

    private function order()
    {
        return app(PurchaseOrderService::class)->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'ordered_qty' => '10', 'rate' => '100']],
        );
    }

    private function receipt()
    {
        return app(PurchaseReceiptService::class)->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'received_qty' => '10', 'rate' => '100']],
        );
    }

    private function bill()
    {
        return app(PurchaseBillService::class)->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100']],
        );
    }

    // ── চারটা কাগজই বেরোয় ────────────────────────────────────────

    public function test_a_purchase_order_prints(): void
    {
        $this->get(route('purchase.print.order', $this->order()))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_a_goods_receipt_prints(): void
    {
        $this->get(route('purchase.print.receipt', $this->receipt()))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_a_purchase_bill_prints(): void
    {
        $this->get(route('purchase.print.bill', $this->bill()))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    // ── তিনটা মাপেই ──────────────────────────────────────────────

    /**
     * ৫৮মিমি, ৮০মিমি, A4 — §১২-এর দাবি।
     *
     * গুদামে হাতের ছোট মেশিন, অফিসে A4। একটা মাপ কাজ না করলে ঠিক সেই
     * জায়গাটাতেই কাগজ দেওয়া যেত না।
     */
    public function test_every_paper_size_works(): void
    {
        $bill = $this->bill();

        foreach (PaperSize::all() as $paper) {
            $this->get(route('purchase.print.bill', $bill).'?paper='.$paper)
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');
        }
    }

    public function test_an_unknown_paper_size_falls_back(): void
    {
        $this->get(route('purchase.print.bill', $this->bill()).'?paper=a3')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    // ── কাগজে কী থাকে, কী থাকে না ────────────────────────────────

    /**
     * মাল বুঝে নেওয়ার কাগজে দাম থাকে না।
     *
     * গুদামের লোক গোনেন; দাম নিয়ে তাঁর কিছু করার নেই। আর দামটা কাগজে
     * থাকলে সেটা এমন অনেকের চোখে পড়ে যাদের জানার কথা নয় — ট্রাকের
     * ড্রাইভার থেকে পাশের দোকানদার।
     */
    public function test_the_goods_receipt_hides_the_money(): void
    {
        $seen = [];
        View::composer('print.document', function ($view) use (&$seen) {
            $seen = $view->getData();
        });

        $this->get(route('purchase.print.receipt', $this->receipt()))->assertOk();

        $this->assertFalse($seen['doc']->showMoney,
            'মাল বুঝে নেওয়ার কাগজে দাম দেখানো হচ্ছে — গুদামের কাগজে দাম থাকার কথা নয়।');
    }

    /** বিলের কাগজে দাম থাকে — ওটাই তো বিল। */
    public function test_the_bill_shows_the_money(): void
    {
        $seen = [];
        View::composer('print.document', function ($view) use (&$seen) {
            $seen = $view->getData();
        });

        $this->get(route('purchase.print.bill', $this->bill()))->assertOk();

        $this->assertTrue($seen['doc']->showMoney);
        $this->assertNotSame([], $seen['doc']->totals, 'বিলের কাগজে কোনো যোগফল নেই।');
    }

    // ── কে ছাপতে পারে ────────────────────────────────────────────

    /**
     * অনুমতি ছাড়া কাগজ পাওয়া যায় না।
     *
     * অনুমতিটা রুটে বসানো, কেবল পর্দায় নয়: মেনু লুকানো থাকলেও ঠিকানা
     * টাইপ করে PDF নামিয়ে ফেলা যেন না যায়।
     */
    public function test_someone_without_the_permission_gets_nothing(): void
    {
        $bill = $this->bill();

        $outsider = User::factory()->create();
        $outsider->companies()->attach($this->company, ['is_active' => true]);
        $outsider->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($outsider)
            ->get(route('purchase.print.bill', $bill))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $bill = $this->bill();

        auth()->logout();

        $this->get(route('purchase.print.bill', $bill))->assertRedirect(route('login'));
    }
}
