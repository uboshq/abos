<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\ImportRunner;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Imports\OpeningStockImporter;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * চারশো পণ্য কেউ হাতে টাইপ করবে না।
 *
 * ── কী আটকে ছিল ─────────────────────────────────────────────────────
 * খোলা মজুদের পর্দা একবারে একটা সারি নেয় — গুদামে দাঁড়িয়ে গোনার জন্য
 * ঠিক। কিন্তু পুরনো ব্যবস্থা থেকে আসা ডিপোর চারশো পণ্য ওভাবে বসানো মানে
 * চারশোবার ফর্ম ভরা। বাস্তবে কেউ করেন না, আর তখন ABOS চালু হয় **অর্ধেক
 * মজুদ নিয়ে** — যা পরে ধরা পড়ে মাস শেষে, মজুদ না মেলায়।
 *
 * এটাই ঙ·১৫-র আসল বাধা ছিল: পণ্য ও গ্রাহক ফাইল ধরে আনা যেত, মজুদ যেত না।
 */
class FourHundredProductsNobodyWillTypeTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    /**
     * একটা টাটকা পণ্য — যার খোলা মজুদ এখনো বসেনি।
     *
     * ── কেন ডেমোর পণ্য ব্যবহার করা যায় না ───────────────────────────
     * ডেমো সিডার প্রতিটা পণ্যের খোলা মজুদ বসিয়ে রাখে, আর নতুন
     * পাহারাটা ঠিক সেটাই আটকায় ("একই পণ্যের খোলা মজুদ দুইবার নয়")।
     * অর্থাৎ সিডারের পণ্য দিয়ে পরীক্ষা করলে প্রতিটা সারি বৈধভাবেই
     * আটকাত — আর টেস্টটা ভুল কারণে লাল হত।
     */
    private function freshProduct(string $code): Product
    {
        return Product::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => $code,
            'name_en' => 'Imported '.$code,
            'name_bn' => 'আনা পণ্য '.$code,
            'unit_id' => Product::query()->value('unit_id'),
            'purchase_price' => '10',
            'sale_price' => '12',
            'is_active' => true,
        ]);
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{ok: int, bad: list<string>}
     */
    private function feed(array $rows): array
    {
        $importer = app(OpeningStockImporter::class);

        $ok = 0;
        $bad = [];

        foreach ($rows as $row) {
            $row = array_merge(
                ['product_code' => '', 'warehouse' => '', 'qty' => '', 'unit_cost' => '', 'trx_date' => ''],
                $row,
            );

            $errors = $importer->check($row);

            if ($errors === []) {
                $importer->import($row);
                $ok++;

                continue;
            }

            $bad = [...$bad, ...$errors];
        }

        return ['ok' => $ok, 'bad' => $bad];
    }

    /** ফাইল থেকে মজুদ সত্যিই তাকে ওঠে। */
    public function test_stock_from_a_file_reaches_the_shelf(): void
    {
        $product = $this->freshProduct('IMP-001');

        $result = $this->feed([
            ['product_code' => $product->code, 'qty' => '40', 'unit_cost' => '95'],
        ]);

        $this->assertSame(1, $result['ok'], implode(' | ', $result['bad']));

        $this->assertSame(0, bccomp(
            app(StockService::class)->floorQty($product, $this->warehouse), '40', 4,
        ), 'ফাইলের ৪০ তাকে ওঠেনি।');
    }

    /** বারকোড দিয়েও পণ্য চেনা যায় — পুরনো কাগজে কোড না-ও থাকতে পারে। */
    public function test_a_product_can_be_named_by_its_barcode(): void
    {
        $product = $this->freshProduct('IMP-002');
        $product->update(['barcode' => '8901000009999']);

        $result = $this->feed([
            ['product_code' => '8901000009999', 'qty' => '5', 'unit_cost' => '10'],
        ]);

        $this->assertSame(1, $result['ok'], implode(' | ', $result['bad']));
    }

    /**
     * একই পণ্য ফাইলে দুইবার থাকলে দ্বিতীয়টা আটকায়।
     *
     * ── কেন এটাই সবচেয়ে জরুরি পরীক্ষা ───────────────────────────────
     * পুরনো ব্যবস্থার রপ্তানিতে একই পণ্য দুই লটে দুই সারি হয়ে আসা খুব
     * সাধারণ। দুইটাই বসে গেলে মজুদ দ্বিগুণ, আর কেউ ধরতে পারত না —
     * দুইটা সারিই দেখতে ঠিক।
     */
    public function test_the_same_product_twice_is_refused_the_second_time(): void
    {
        $product = $this->freshProduct('IMP-003');

        $first = $this->feed([['product_code' => $product->code, 'qty' => '10', 'unit_cost' => '50']]);
        $this->assertSame(1, $first['ok']);

        $second = $this->feed([['product_code' => $product->code, 'qty' => '10', 'unit_cost' => '50']]);

        $this->assertSame(0, $second['ok'], 'একই পণ্যের খোলা মজুদ দুইবার বসেছে — মজুদ দ্বিগুণ।');
        $this->assertNotEmpty($second['bad']);
    }

    /** অচেনা পণ্যের সারি বসে না, আর কোন সারিটা তা বলে দেয়। */
    public function test_an_unknown_product_is_named_not_swallowed(): void
    {
        $result = $this->feed([['product_code' => 'NO-SUCH-CODE', 'qty' => '5', 'unit_cost' => '5']]);

        $this->assertSame(0, $result['ok']);
        $this->assertStringContainsString('NO-SUCH-CODE', implode(' ', $result['bad']));
    }

    /**
     * দর ছাড়া মজুদ বসে না।
     *
     * শুরুর দিনের মালের আগে কোনো চালান নেই, তাই দরটা বের করে নেওয়ার
     * উপায় নেই। শূন্য দরে বসালে মজুদের মূল্য শূন্য হত, আর প্রথম
     * বিক্রিতেই মুনাফা পুরো বিক্রয়মূল্যের সমান দেখাত।
     */
    public function test_stock_without_a_cost_is_refused(): void
    {
        $product = $this->freshProduct('IMP-004');

        $this->assertSame(0, $this->feed([
            ['product_code' => $product->code, 'qty' => '5', 'unit_cost' => '0'],
        ])['ok']);

        $this->assertSame(0, $this->feed([
            ['product_code' => $product->code, 'qty' => '0', 'unit_cost' => '5'],
        ])['ok']);
    }

    /** গুদামের ঘর খালি রাখলে প্রধান গুদামেই বসে — এক গুদামের ডিপোতে ওটাই স্বাভাবিক। */
    public function test_an_empty_warehouse_column_means_the_default_one(): void
    {
        $product = $this->freshProduct('IMP-005');

        $this->feed([['product_code' => $product->code, 'qty' => '7', 'unit_cost' => '20']]);

        $this->assertSame(0, bccomp(
            app(StockService::class)->floorQty($product, $this->warehouse), '7', 4,
        ), 'খালি গুদামের ঘরে মালটা প্রধান গুদামে বসেনি।');
    }

    /** ইমপোর্টের পর্দায় খোলা মজুদ একটা বাছাই হিসেবে সত্যিই আছে। */
    public function test_the_screen_offers_it(): void
    {
        $this->get(route('system_admin.import.index'))
            ->assertOk()
            ->assertSee(__('inventory::menu.opening'));
    }

    /**
     * নমুনা ফাইলটা সত্যিই নামে, আর তাতে ঠিক কলামগুলোই থাকে।
     *
     * নমুনা ছাড়া মানুষ নিজের মতো হেডার লিখতেন, আর প্রতিটা ফাইল হাতে
     * মেলাতে হত।
     */
    public function test_the_template_carries_the_right_columns(): void
    {
        $csv = $this->get(route('system_admin.import.template', ['kind' => 'opening_stock']))
            ->assertOk()
            ->streamedContent();

        foreach (['product_code', 'qty', 'unit_cost'] as $column) {
            $this->assertStringContainsString($column, $csv, "নমুনায় {$column} কলামটা নেই।");
        }
    }

    /** চলাচলের সারিতে লেখা থাকে সংখ্যাটা ফাইল থেকে এসেছে। */
    public function test_the_movement_says_it_came_from_a_file(): void
    {
        $product = $this->freshProduct('IMP-006');

        $this->feed([['product_code' => $product->code, 'qty' => '3', 'unit_cost' => '11']]);

        $this->assertDatabaseHas('inv_stock_movements', [
            'product_id' => $product->id,
            'narration' => __('inventory::message.opening_from_file'),
        ]);
    }

    /** রানারটাও এটাকে চেনে — তালিকায় থাকা মানেই চালানো যাওয়া নয়। */
    public function test_the_import_runner_knows_this_kind(): void
    {
        $this->assertArrayHasKey('opening_stock', app(ImportRunner::class)->available());
    }
}
