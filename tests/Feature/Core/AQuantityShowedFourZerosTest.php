<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportResult;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * পরিমাণের ঘরে দশমিকের পরে চারটা শূন্য — মালিক, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ আগে ─────────────────────────────────────────────────────────────
 * "Profit by Product"-এর পরিমাণ `3796.0000`, `3274.0000` — রিপোর্টের ঘরে পরিমাণের কোনো ধরন ছিল না, তাই
 * কাঁচা মান ছাপা হত; মোটের সারি জোর করে দুই দশমিকে। মালিকের কথা: *"dosomiker pore eto sunno keno …
 * zetate sudu dosomiker pore vanga sonkha thake setatei sudu hobe baki gulote na"*।
 *
 * ── ⭐ এখন ([[ReportResult::number()]] → [[Money::quantity()]]) ─────────
 * পরিমাণ ৩,৭৯৬ — দশমিক কেবল সত্যিকারের ভগ্নাংশে (১২.৫)। টাকা আগের মতোই দুই ঘরে।
 */
final class AQuantityShowedFourZerosTest extends TestCase
{
    use RefreshDatabase;

    /** নামে পরিমাণের মতো, আসলে নয় — কারণসহ। */
    private const NOT_A_QUANTITY = [
        'sales.margin.below_floor' => 'মার্জিনের সীমার নিচে কি না — হ্যাঁ/না লেখা, পরিমাণ নয়',
        // ⓘ নামের শেষে held/available, অথচ টাকা — দুই দশমিকেই দেখানোর কথা
        'sales.credit_use.held' => 'বাকির সীমায় আটকে থাকা টাকা — টাকা, পরিমাণ নয়',
        'sales.credit_use.available' => 'সীমায় এখনো বাকি টাকা — টাকা, পরিমাণ নয়',
        'finance.tenancy_arrears.deposit_held' => 'ভাড়াটের হাতে রাখা জামানত — টাকা, পরিমাণ নয়',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /** ⛔→⭐ গোটা সংখ্যা দশমিক ছাড়া, ভগ্নাংশ যেমন আছে — সারিতে আর মোটে; টাকা দুই ঘরে। */
    public function test_the_profit_by_product_screen_shows_plain_quantities(): void
    {
        [$whole, $half] = Product::query()->orderBy('id')->take(2)->get()->all();

        // ⓘ ভগ্নাংশ কেবল ভগ্নাংশ-চলা এককে বেচা যায় (ম১৯, c3c6a9b9) — প্রশ্নটা দেখানোর, তাই এককটা ভগ্নাংশের
        $half->unit->forceFill(['allows_fraction' => true])->save();

        $this->sell($whole, '45', '10');
        $this->sell($half, '12.5', '10');

        $page = $this->get(route('sales.report.show', [
            'slug' => 'by-product',
            'from' => now()->subDay()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]))->assertOk();

        $html = $page->getContent();

        $this->assertStringNotContainsString('45.0000', $html, '⛔ পরিমাণে চারটা শূন্য।');
        $this->assertStringNotContainsString('45.000', $html, '⛔ পরিমাণে তিনটা শূন্য।');
        $this->assertStringNotContainsString('12.5000', $html, '⛔ ভগ্নাংশের পিছনে শূন্য।');
        $this->assertMatchesRegularExpression('/>\s*45\s*</', $html, 'গোটা পরিমাণটা দশমিক ছাড়া দেখায়নি।');
        $this->assertMatchesRegularExpression('/>\s*12\.5\s*</', $html, 'ভগ্নাংশ ১২.৫ হিসেবে দেখায়নি।');

        // ⓘ মোটের সারি — ৫৭.৫, "57.50" নয়
        $this->assertMatchesRegularExpression('/>\s*57\.5\s*</', $html, '⛔ মোটের পরিমাণ দশমিকের শূন্যসহ।');

        // ⭐ টাকা বদলায়নি — ৪৫০.০০ দুই ঘরেই
        $this->assertStringContainsString('450.00', $html, '⛔ টাকার দুই দশমিক হারিয়েছে।');
    }

    /**
     * ⛔ পাহারা: রিপোর্টের প্রতিটা সংখ্যার ধরনের ঘরে নিজের রূপ আছে — নতুন ধরন বা পরিমাণের ঘর কাঁচা মানে
     * (`@default`) পড়ে গেলে লাল। ⓘ আর পরিমাণের রূপটা [[ReportResult::number()]] — চার দশমিক বা গোটা
     * সংখ্যায় দশমিক এলে লাল।
     */
    public function test_every_number_type_has_its_own_shape(): void
    {
        $cell = (string) file_get_contents(app_path('Modules/Accounts/Resources/views/report/partials/cell.blade.php'));

        foreach (['MONEY', 'QUANTITY', 'PERCENT'] as $type) {
            $this->assertStringContainsString('ReportColumn::'.$type.')', $cell,
                "⛔ রিপোর্টের ঘরে ReportColumn::{$type}-এর নিজের রূপ নেই — কাঁচা মান ছাপা হবে।");
        }

        $quantity = ReportColumn::fromArray(['key' => 'qty', 'label' => 'x', 'type' => ReportColumn::QUANTITY], 0);

        $this->assertSame('3,796', ReportResult::number('3796.0000', $quantity));
        $this->assertSame('12.5', ReportResult::number('12.5000', $quantity));
        $this->assertSame('0', ReportResult::number('0.0000', $quantity));
        $this->assertSame('12,34,567.25', ReportResult::number('1234567.2500', $quantity));
    }

    /**
     * ⛔ পাহারা: প্রতিটা রিপোর্টের পরিমাণের নামের কলাম পরিমাণ হিসেবেই ঘোষিত — টাকা হিসেবে ঘোষণা করলে "7.00"
     * দেখাত (মালিক, ১ অক্টোবর ২০২৬: মজুদের রিপোর্টে 7.0000 / 0.0000)। ⓘ নতুন রিপোর্টেও একই — নাম দেখে ধরা।
     */
    public function test_every_quantity_column_in_every_report_is_a_quantity(): void
    {
        $engine = app(\App\Core\Engines\Report\ReportEngine::class);
        $wrong = [];

        foreach ($engine->keys() as $key) {
            foreach ($engine->get($key)->columns as $column) {
                if (preg_match('/(^|_)(qty|quantity|floor|reserved|hold|held|unplaced|on_hand|available)(_change)?$/', $column->key) === 1
                    && ! isset(self::NOT_A_QUANTITY[$key.'.'.$column->key])
                    && $column->type !== ReportColumn::QUANTITY) {
                    $wrong[] = "{$key}.{$column->key} ({$column->type})";
                }
            }
        }

        $this->assertSame([], $wrong, '⛔ এই পরিমাণের কলামগুলো পরিমাণ হিসেবে ঘোষিত নয় — দশমিকের শূন্য দেখাবে।');
    }

    /** ⭐ মজুদ — গুদাম ধরে (মালিকের ছবির পাতা): কোনো পরিমাণে ".0000" নেই। */
    public function test_the_stock_by_warehouse_screen_shows_plain_quantities(): void
    {
        $html = $this->get(route('inventory.report.show', [
            'slug' => 'stock-by-warehouse',
            'from' => now()->subYear()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('inventory', strtolower($html));
        $this->assertDoesNotMatchRegularExpression('/>\s*-?[\d,]+\.0000\s*</', $html, '⛔ মজুদের পরিমাণে চারটা শূন্য।');
        $this->assertDoesNotMatchRegularExpression('/>\s*-?[\d,]+\.000\s*</', $html, '⛔ মজুদের পরিমাণে তিনটা শূন্য।');
    }

    private function sell(Product $product, string $qty, string $rate): void
    {
        $invoice = app(SalesInvoiceService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $product->id, 'qty' => $qty, 'rate' => $rate]]);

        app(SalesInvoiceService::class)->confirm($invoice);
    }
}
