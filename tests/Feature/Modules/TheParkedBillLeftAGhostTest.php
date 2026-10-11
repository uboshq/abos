<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\PosService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ধরে রাখা বিলটা একটা ভূত রেখে গেল।
 *
 * ক্রেতা টাকা আনতে গেলে ক্যাশিয়ার কার্টটা ধরে রাখেন (`park`)। ফিরে
 * এলে তোলেন (`resume`) আর বেচে দেন। প্রশ্ন: যে খসড়াটা ধরে রাখা
 * হয়েছিল, সেটার কী হলো?
 */
class TheParkedBillLeftAGhostTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->product = Product::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->firstOrFail();
    }

    /** ধরে রাখা, তোলা, তারপর বেচা — খাতায় একটাই বিল থাকা উচিত। */
    public function test_a_parked_bill_that_is_sold_leaves_no_second_draft(): void
    {
        $pos = app(PosService::class);

        $cart = [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '500']];

        $parked = $pos->park(['warehouse_id' => $this->warehouse->id], $cart);
        $pos->resume($parked);

        $sold = $pos->checkout([
            'warehouse_id' => $this->warehouse->id,
            'paid' => '500',
            'resumed_invoice_id' => $parked->id,
        ], $cart)['invoice'];

        $this->assertSame(0, SalesInvoice::query()->where('status', DocumentStatus::DRAFT)->count(),
            'তোলা খসড়াটা পড়ে রইল — নম্বরও খরচ হলো, আর তালিকায় একটা মরা বিল থেকে গেল।');

        $this->assertSame($parked->id, $sold->id,
            'তোলা বিলটাই বিক্রি হয়নি — নতুন একটা বানানো হয়েছে।');

        /*
         * ⓘ খসড়া অপেক্ষা করে DRF নম্বরে, আসল নম্বর নিশ্চিত করার মুহূর্তে — মালিকের বিক্রয়ের ধারা, ২ অক্টোবর ২০২৬
         * (1e402eb1): ফেলে দেওয়া খসড়া বিক্রির নম্বরে ফাঁক রাখে না। ⚠️ আগে এখানে "নম্বর বদলায় না" চাওয়া হত —
         * ঐ নিয়মের আগের কথা (fe, ১১ অক্টোবর ২০২৬)। ⛔ যা থাকে: একই সারি, একটাই আসল নম্বর, আর খসড়ার নম্বরটা নয়।
         */
        $this->assertStringStartsWith('DRF-', (string) $parked->document_no, 'দৃশ্যটাই বানানো যায়নি — ধরে রাখা বিল খসড়ার নম্বরে নেই।');
        $this->assertStringStartsWith('INV-', (string) $sold->document_no,
            '⛔ বিক্রি হলো, অথচ বিলটা আসল নম্বর পায়নি: '.$sold->document_no);
        $this->assertSame(1, SalesInvoice::query()->where('document_no', $sold->document_no)->count(),
            '⛔ একই আসল নম্বর দুটো বিলে।');
    }

    /**
     * নম্বর না পাঠালে আগের মতোই নতুন বিল।
     *
     * পুরনো টিল, ইমপোর্ট আর পরীক্ষার কোড এটা পাঠায় না — তাদের আচরণ
     * এক চুলও বদলানো চলে না।
     */
    public function test_without_the_number_a_fresh_bill_is_made(): void
    {
        $pos = app(PosService::class);

        $sold = $pos->checkout(
            ['warehouse_id' => $this->warehouse->id, 'paid' => '500'],
            [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '500']],
        )['invoice'];

        $this->assertSame(DocumentStatus::CONFIRMED, $sold->status);
        $this->assertSame(1, SalesInvoice::query()->count());
    }

    /**
     * নিশ্চিত হওয়া বিলের নম্বর দিলে সেটা তোলা হয় না।
     *
     * তুললে ক্যাশিয়ার একই বিলের টাকা দ্বিতীয়বার নিতেন, আর মালটাও
     * দ্বিতীয়বার গুদাম থেকে নামত।
     */
    public function test_a_finished_bill_cannot_be_finished_again(): void
    {
        $pos = app(PosService::class);
        $cart = [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '500']];

        $first = $pos->checkout(['warehouse_id' => $this->warehouse->id, 'paid' => '500'], $cart)['invoice'];

        $second = $pos->checkout([
            'warehouse_id' => $this->warehouse->id,
            'paid' => '500',
            'resumed_invoice_id' => $first->id,
        ], $cart)['invoice'];

        $this->assertNotSame($first->id, $second->id,
            'নিশ্চিত হওয়া বিলটাই আবার সম্পূর্ণ করা হয়েছে — টাকা দুইবার উঠত।');
    }
}
