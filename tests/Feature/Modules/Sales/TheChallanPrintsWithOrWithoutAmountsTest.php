<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\DocumentDelivery;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryChallanLine;
use App\Modules\Sales\Support\PaperDesigns;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * চালান ছাপা — টাকাসহ না টাকা ছাড়া, মোট পরিমাণ, আর পণ্যের কোডের সুইচ (মালিক, ২-৩ অক্টোবর ২০২৬)।
 *
 *   টাকা ছাড়া    দর, টাকা, মোট — কোনো সংখ্যা নেই; A4, A5, থার্মাল তিনটাতেই
 *   টাকাসহ       মোট = ইনভয়েসের মোট (ছাড়ের পরে ১,৫০০.০০), চালানের নিজের যোগ (১,৫৫৫.৫৪) নয়; ছাড়ের সারিও
 *   কোনটা        ছাপার খাতায় লেখা — `with_amounts` / `without_amounts`
 *   মোট পরিমাণ   ২ + ফ্রি ১ = ৩
 *   পণ্যের কোড   ডিফল্টে কাগজের কোথাও নেই (তিন মাপে); চালানের সুইচ চালু করলে আছে
 */
final class TheChallanPrintsWithOrWithoutAmountsTest extends TestCase
{
    use RefreshDatabase;

    private const RATE = '777.77';

    private const OWN_TOTAL = '1,555.54';

    private const INVOICE_TOTAL = '1,500.00';

    private const DISCOUNT = '55.54';

    private const PAPER = ['a4' => 'a4', 'a5' => 'a5', 'thermal' => '80mm'];

    private User $owner;

    private DeliveryChallan $challan;

    private string $code;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $product = Product::query()->orderBy('id')->firstOrFail();
        $this->code = (string) $product->code;

        $this->challan = DeliveryChallan::query()->create([
            'branch_id' => $company->defaultBranch()?->id,
            'document_no' => 'CHL-AMT-0001',
            'customer_id' => Customer::query()->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
            'total' => '1555.5400',
            'status' => DocumentStatus::CONFIRMED,
        ]);

        $line = DeliveryChallanLine::query()->create([
            'delivery_challan_id' => $this->challan->id,
            'product_id' => $product->id,
            'line_no' => 1,
            'delivered_qty' => '2.0000',
            'free_qty' => '1.0000',
            'rate' => self::RATE,
            'amount' => '1555.5400',
        ]);

        // ⓘ চালানের ইনভয়েস — ছাড়ের পরে ১,৫০০; চালানের নিজের যোগ ১,৫৫৫.৫৪ থেকে আলাদা, যাতে কোনটা ছাপা হলো তা বোঝা যায়
        $invoice = DB::table('sal_invoices')->insertGetId([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id,
            'document_no' => 'INV-AMT-0001', 'customer_id' => $this->challan->customer_id,
            'warehouse_id' => $this->challan->warehouse_id, 'trx_date' => now()->toDateString(),
            'subtotal' => '1555.5400', 'discount' => self::DISCOUNT, 'tax' => '0', 'total' => '1500.0000',
            'status' => DocumentStatus::CONFIRMED, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sal_invoice_lines')->insert([
            'sales_invoice_id' => $invoice, 'product_id' => $product->id, 'delivery_challan_line_id' => $line->id,
            'qty' => '2.0000', 'rate' => self::RATE, 'amount' => '1555.5400', 'line_no' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_without_amounts_carries_no_money_on_any_paper_and_is_recorded(): void
    {
        foreach (self::PAPER as $size => $paper) {
            $html = $this->paper($size, ['prices' => 0]);

            foreach ([self::RATE, self::OWN_TOTAL, self::INVOICE_TOTAL] as $money) {
                $this->assertStringNotContainsString($money, $html, "⛔ {$size}: টাকা ছাড়া চালানে {$money} ছাপা হয়েছে।");
            }
        }

        $this->assertSame('without_amounts', DocumentDelivery::query()->latest('id')->value('variant'), '⛔ কোনটা ছাপা হলো, খাতায় নেই।');
    }

    public function test_with_amounts_totals_like_the_invoice_not_the_challan(): void
    {
        foreach (self::PAPER as $size => $paper) {
            $html = $this->paper($size, ['prices' => 1]);

            $this->assertStringContainsString(self::RATE, $html, "{$size}: টাকাসহ চালানে দর নেই — দৃশ্যটাই ভুল।");
            $this->assertStringContainsString(self::INVOICE_TOTAL, $html, "⛔ {$size}: টাকাসহ চালানের মোট ইনভয়েসের মোট (১,৫০০) নয়।");
            $this->assertStringContainsString(self::DISCOUNT, $html, "⛔ {$size}: ইনভয়েসের ছাড়ের সারি নেই — হিসাব মেলে না।");
        }

        $this->assertSame('with_amounts', DocumentDelivery::query()->latest('id')->value('variant'));
    }

    public function test_the_total_quantity_column_adds_the_free_goods(): void
    {
        foreach (self::PAPER as $size => $paper) {
            $html = $this->paper($size, ['prices' => 0]);

            $this->assertMatchesRegularExpression('/data-total-qty[^>]*>/', $html, "⛔ {$size}: মোট পরিমাণের কলাম নেই।");
            $this->assertMatchesRegularExpression('/data-total-qty-sum[^>]*>\s*3\b/u', $html, "⛔ {$size}: মোট পরিমাণ ২ + ফ্রি ১ = ৩ নয়।");
        }
    }

    public function test_the_product_code_is_nowhere_until_its_switch_is_on(): void
    {
        foreach (self::PAPER as $size => $paper) {
            $this->assertStringNotContainsString($this->code, $this->paper($size, ['prices' => 0]),
                "⛔ {$size}: সুইচ বন্ধ, তবু পণ্যের কোড {$this->code} ছাপা হয়েছে।");
        }

        app(SettingsService::class)->set('sales.print.challan_show.product_code', true);

        foreach (self::PAPER as $size => $paper) {
            $this->assertStringContainsString($this->code, $this->paper($size, ['prices' => 0]),
                "{$size}: সুইচ চালু, তবু পণ্যের কোড নেই — জালটা কিছুই মাপছে না।");
        }
    }

    /** বাছা নকশাটা আঁকা HTML — composer দিয়ে ডেটা ধরে আবার আঁকা ([[EveryDesignKeepsThePaperRulesTest::paperIn()]]-এর ধাঁচ) */
    private function paper(string $size, array $query): string
    {
        $code = PaperDesigns::defaultFor('challan', $size);
        app(SettingsService::class)->set(PaperDesigns::key('challan', $size), $code);
        $view = (string) PaperDesigns::template('challan', $size, $code);

        $data = null;
        View::composer($view, function ($v) use (&$data) {
            $data = $v->getData();
        });

        $this->actingAs($this->owner)
            ->get(route('sales.print.challan', [$this->challan, 'paper' => self::PAPER[$size], ...$query]))
            ->assertOk();
        $this->assertNotNull($data, "{$size}: নকশা ({$view}) আঁকা হয়নি।");

        $html = (string) view($view, $data)->render();
        View::getFacadeRoot()->getDispatcher()->forget('composing: '.$view);

        return $html;
    }
}
