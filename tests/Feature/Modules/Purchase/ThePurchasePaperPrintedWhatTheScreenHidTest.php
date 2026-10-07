<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ক্রয়ের কাগজ সেই দামটাই ছাপত, যা পর্দা ঢেকে রাখে — ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ⛔ পণ্যের পাতা ক্রয়মূল্য ঢাকে `inventory.cost.view` ছাড়া ([[FieldSecurity]]),
 * কিন্তু বিল আর আদেশের কাগজ `purchase.bill.view` / `purchase.order.view`
 * থাকলেই প্রতিটা সারির দর, অঙ্ক আর মোট ছাপত — ফোনের §১০ এজেন্টের ধরা।
 *
 * ⭐ একই মানুষ দুইবার ([[same-user-key-off-then-on]]): চাবি ছাড়া কাগজে দর নেই,
 * চাবি দিলে আছে। ⓘ মাপা হয় ছাপার পাতার আসল HTML — যে লেখা কাগজে যায়।
 * দরটা ইচ্ছা করে অদ্ভুত (১২৩.৪৫), যাতে অন্য কোনো সংখ্যার সাথে গুলিয়ে না যায়।
 */
final class ThePurchasePaperPrintedWhatTheScreenHidTest extends TestCase
{
    use RefreshDatabase;

    private const RATE = '123.45';

    private const AMOUNT = '1,234.50';

    private Company $company;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->clerk = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->give('purchase.bill.view');
        $this->give('purchase.order.view');
    }

    public function test_a_bill_prints_its_price_only_for_the_cost_key(): void
    {
        $bill = app(PurchaseBillService::class)->create(
            ['supplier_id' => Supplier::query()->value('id'), 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->value('id'), 'qty' => '10', 'rate' => self::RATE]],
        );

        /* ⓘ ১ অক্টোবর থেকে বিলের ডিফল্ট নতুন কাগজ — চাবির নিয়ম সেখানেও একই ([[TheNewPurchaseBillPaperTest]]) */
        $this->assertPriceFollowsTheKey(route('purchase.print.bill', $bill), 'purchase::print.bill-modern');
    }

    public function test_an_order_prints_its_price_only_for_the_cost_key(): void
    {
        $order = app(PurchaseOrderService::class)->create(
            ['supplier_id' => Supplier::query()->value('id'), 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->value('id'), 'ordered_qty' => '10', 'rate' => self::RATE]],
        );

        $this->assertPriceFollowsTheKey(route('purchase.print.order', $order));
    }

    private function assertPriceFollowsTheKey(string $url, string $view = 'print.document'): void
    {
        $this->assertFalse($this->clerk->fresh()->can('inventory.cost.view'), 'কর্মীর আগে থেকেই চাবি আছে — দাবিটা কিছু মাপছে না।');

        [$status, $html, $doc] = $this->printAs($url, $view);
        $this->assertSame(200, $status, '⛔ চাবি ছাড়া কাগজটাই বেরোল না — দাম ঢাকার বদলে দরজা বন্ধ হয়ে গেছে।');
        $this->assertStringNotContainsString(self::RATE, $html, '⛔ ক্রয়মূল্য দেখার চাবি নেই, তবু কাগজে দর ছাপা হলো।');
        $this->assertStringNotContainsString(self::AMOUNT, $html, '⛔ চাবি নেই, তবু সারির অঙ্ক ছাপা হলো।');
        $this->assertSame([], $doc->totals, '⛔ চাবি নেই, তবু মোটের সারি এলো।');
        $this->assertArrayNotHasKey('rate', $doc->lines[0]);

        $this->give('inventory.cost.view');

        [$status, $html, $doc] = $this->printAs($url, $view);
        $this->assertSame(200, $status);
        $this->assertStringContainsString(self::RATE, $html, '⛔ চাবি দেওয়ার পরেও দর ছাপা হলো না — কাগজটা সবার জন্য দাম হারিয়েছে।');
        $this->assertStringContainsString(self::AMOUNT, $html);
        $this->assertNotSame([], $doc->totals);
    }

    /** @return array{0: int, 1: string, 2: object} */
    private function printAs(string $url, string $view): array
    {
        $captured = null;
        // ⓘ ছাঁচটা ঠিক যেটা কাগজ আঁকে — অন্যটা ধরলে দাবিটা কিছু মাপত না
        View::composer($view, function ($seen) use (&$captured) {
            $captured = $seen->getData();
        });

        $this->app['auth']->forgetGuards();
        $status = $this->actingAs($this->clerk->fresh())->get($url)->getStatusCode();

        $this->assertNotNull($captured, 'ছাপার পাতা আঁকাই হয়নি — দাবিটা কিছু মাপছে না।');

        // ⓘ পাতাটা আবার আঁকা — কম্পোজারের ভেতরে আঁকলে সে নিজেকেই ডাকত
        View::flushState();
        $html = view($view, $captured)->render();

        return [$status, $html, $captured['doc']];
    }

    private function give(string $key): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $this->clerk->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
