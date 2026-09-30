<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * ক্রয় বিলের নতুন কাগজ — মালিক, ১ অক্টোবর ২০২৬: *"ok template diye daw"*।
 *
 * ⭐ দাবি: A4-এ ডিফল্ট নতুন কাগজ; সরবরাহকারীর বিল নম্বর, লট, ফ্রি আর হিসাব সব আসে;
 * বাতিল বিল বাতিল বলে; সরু রোলে আর "সাধারণ" বাছলে আগের কাগজ।
 */
final class TheNewPurchaseBillPaperTest extends TestCase
{
    use RefreshDatabase;

    private const MODERN = 'purchase::print.bill-modern';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_an_a4_bill_prints_on_the_new_paper_with_everything_on_it(): void
    {
        $bill = $this->bill();

        $html = $this->paper(route('purchase.print.bill', ['bill' => $bill->id, 'paper' => 'a4']), self::MODERN);

        $this->assertStringContainsString('OM-619-SUP', $html, 'সরবরাহকারীর বিল নম্বর কাগজে নেই।');
        $this->assertStringContainsString('LOT-NEW-PAPER', $html, 'লটের নাম কাগজে নেই।');
        $this->assertStringContainsString((string) $bill->document_no, $html);
        $this->assertStringContainsString('1,000.00', $html, 'সারির টাকা বা সর্বমোট কাগজে নেই।');
        $this->assertStringContainsString(__('purchase::bill_paper.grand'), $html);
        $this->assertStringContainsString(__('purchase::bill_paper.due'), $html);
        $this->assertStringNotContainsString('.0000', $html, 'পরিমাণে অকারণে চার শূন্য।');
    }

    public function test_a_cancelled_bill_says_so_on_the_new_paper(): void
    {
        $bill = $this->bill();
        app(PurchaseBillService::class)->cancel($bill->fresh(), 'ভুল বিল');

        $html = $this->paper(route('purchase.print.bill', $bill), self::MODERN);

        $this->assertStringContainsString(__('core.print.cancelled_notice'), $html,
            'বাতিল বিল নতুন কাগজে বৈধ বিলের মতো দেখায় — সেটা দেখিয়ে দাবি করা যেত।');
    }

    public function test_a_thermal_roll_keeps_the_plain_paper(): void
    {
        $this->paper(route('purchase.print.bill', ['bill' => $this->bill()->id, 'paper' => '80mm']), 'print.document');
    }

    public function test_the_plain_choice_brings_back_the_old_paper(): void
    {
        app(SettingsService::class)->set('purchase.print.design.bill', 'standard');

        $this->paper(route('purchase.print.bill', ['bill' => $this->bill()->id, 'paper' => 'a4']), 'print.document');
    }

    private function bill(): PurchaseBill
    {
        $product = Product::query()->orderBy('id')->firstOrFail();

        return app(DirectPurchaseService::class)->complete([
            'supplier_id' => Supplier::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'OM-619-SUP',
        ], [[
            'product_id' => $product->id,
            'unit_id' => $product->unit_id,
            'qty' => '10',
            'free_qty' => '2',
            'rate' => '100',
            'batch_no' => 'LOT-NEW-PAPER',
        ]])['bill'];
    }

    /** ছাপার পাতাটা যে ছাঁচে আঁকা হলো, সেটাই ধরে আবার আঁকা — PDF-এর ভেতর পড়া যায় না */
    private function paper(string $url, string $view): string
    {
        $seen = [];
        View::composer($view, function ($v) use (&$seen) {
            $seen = $v->getData();
        });

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertNotSame([], $seen, "কাগজটা '{$view}' ছাঁচে আঁকা হয়নি।");
        View::flushState();

        return view($view, $seen)->render();
    }
}
