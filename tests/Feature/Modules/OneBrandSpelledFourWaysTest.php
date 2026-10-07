<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Brand;
use App\Modules\MasterData\Models\ProductCategory;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * এক ব্র্যান্ড, এক সারি।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `inv_products.brand` ছিল ১২০ অক্ষরের মুক্ত লেখা — পণ্যের ফর্মে টাইপ
 * করা হত, CSV ইমপোর্টেও ওভাবেই আসত। ফলে একই ব্র্যান্ড কয়েক বানানে
 * বসত: "Nestle", "nestle", "নেসলে"।
 *
 * রোজকার কাজে কেউ টের পেত না — পণ্যের পাতায় লেখাটা ঠিকই দেখাত। টের
 * পাওয়া যেত ঠিক যেদিন "ব্র্যান্ড ধরে বিক্রয়" খোলা হত: এক ব্র্যান্ড
 * তিন সারিতে ভাগ, প্রতিটার অঙ্ক আসলের এক-তৃতীয়াংশ, আর কোনো সারিই সত্যি
 * নয়। তারপর সেই তালিকা দেখেই কেউ ঠিক করত কোন ব্র্যান্ড রাখা হবে।
 */
class OneBrandSpelledFourWaysTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    private function brand(string $name): Brand
    {
        return Brand::query()->create([
            'code' => strtoupper(str_replace(' ', '-', $name)),
            'name_en' => $name,
            'name_bn' => $name,
            'is_active' => true,
        ]);
    }

    /** একটা নিশ্চিত বিল — দেওয়া পণ্যে। */
    private function sell(Product $product, string $rate, string $qty = '1'): void
    {
        $invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => Customer::query()->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $product->id, 'qty' => $qty, 'rate' => $rate]],
        );

        app(SalesInvoiceService::class)->confirm($invoice);
    }

    /** @return array<string, array<string, mixed>> ব্র্যান্ডের নাম ধরে সারি */
    private function rows(): array
    {
        $result = app(ReportEngine::class)->run('sales.by_brand', [
            'from' => now()->subDay()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]);

        $out = [];

        foreach ($result->rows as $row) {
            $out[$row['brand_name']] = $row;
        }

        return $out;
    }

    // ── ব্র্যান্ড এখন একটা সারি ─────────────────────────────────────

    /**
     * দুইটা পণ্য একই ব্র্যান্ডে — রিপোর্টে একটাই সারি।
     *
     * এটাই পুরো কাজটা। আগে দুইজন দুই বানানে টাইপ করলে দুইটা সারি হত।
     */
    public function test_two_products_of_one_brand_make_one_row(): void
    {
        $nestle = $this->brand('Nestle');

        [$a, $b] = Product::query()->orderBy('id')->take(2)->get()->all();

        $a->update(['brand_id' => $nestle->id]);
        $b->update(['brand_id' => $nestle->id]);

        $this->sell($a, '300');
        $this->sell($b, '200');

        $rows = $this->rows();

        $this->assertArrayHasKey('Nestle', $rows);
        $this->assertSame(0, bccomp((string) $rows['Nestle']['revenue'], '500', 2));
        $this->assertSame(2, (int) $rows['Nestle']['product_count']);
    }

    /** আলাদা ব্র্যান্ড আলাদা সারিতেই থাকে। */
    public function test_two_brands_stay_apart(): void
    {
        [$a, $b] = Product::query()->orderBy('id')->take(2)->get()->all();

        $a->update(['brand_id' => $this->brand('Nestle')->id]);
        $b->update(['brand_id' => $this->brand('Pran')->id]);

        $this->sell($a, '300');
        $this->sell($b, '200');

        $rows = $this->rows();

        $this->assertSame(0, bccomp((string) $rows['Nestle']['revenue'], '300', 2));
        $this->assertSame(0, bccomp((string) $rows['Pran']['revenue'], '200', 2));
    }

    /**
     * ব্র্যান্ড না বসানো পণ্যগুলোও তালিকায় থাকে।
     *
     * বাদ দিলে এই রিপোর্টের যোগফল আর "মোট বিক্রয়" আলাদা হত, আর সেই
     * দুইটা সংখ্যা মেলাতে গিয়ে কেউ ভাবত কোথাও হিসাব ভুল। তার উপর
     * সারিটা বড় হলে বোঝা যায় মাস্টার ডেটা অসম্পূর্ণ।
     */
    public function test_products_with_no_brand_are_still_counted(): void
    {
        $product = Product::query()->firstOrFail();
        $product->update(['brand_id' => null]);

        $this->sell($product, '250');

        $rows = $this->rows();

        $this->assertArrayHasKey(__('sales::message.no_brand'), $rows);
        $this->assertSame(0, bccomp((string) $rows[__('sales::message.no_brand')]['revenue'], '250', 2));
    }

    /** ভ্যাট এখানেও বিক্রয় নয় — পণ্যভিত্তিক রিপোর্টের একই নিয়ম। */
    public function test_vat_is_not_revenue_here_either(): void
    {
        $product = Product::query()->firstOrFail();
        $product->update(['brand_id' => $this->brand('Nestle')->id]);

        $invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => Customer::query()->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $product->id, 'qty' => '1', 'rate' => '1000', 'tax' => '150']],
        );

        app(SalesInvoiceService::class)->confirm($invoice);

        $this->assertSame(0, bccomp((string) $this->rows()['Nestle']['revenue'], '1000', 2));
    }

    // ── পুরনো লেখাগুলো সারি হয়ে বসেছে ───────────────────────────────

    /**
     * মাইগ্রেশন পুরনো লেখাগুলো হারায়নি।
     *
     * ── কেন এটা সবচেয়ে জরুরি পরীক্ষা ────────────────────────────────
     * চালু ব্যবস্থায় পণ্যের ব্র্যান্ড লেখা আছে। মাইগ্রেশন কেবল নতুন
     * ঘর বানিয়ে থেমে গেলে ওই লেখাগুলো রয়ে যেত অথচ কোনো পণ্য কোনো
     * ব্র্যান্ডে বাঁধা থাকত না — আর রিপোর্টটা খুলে সবাই দেখত "ব্র্যান্ড
     * বসানো হয়নি" ছাড়া কিছুই নেই। ডেটা হারায়নি, শুধু অদৃশ্য।
     */
    public function test_the_migration_turned_old_free_text_into_rows(): void
    {
        // মাইগ্রেশন আগেই চলে গেছে, তাই পুরনো অবস্থাটা হাতে বানিয়ে
        // মাইগ্রেশনের নিজের কাজটাই আবার চালানো হয়
        $product = Product::query()->firstOrFail();

        DB::table('inv_products')->where('id', $product->id)
            ->update(['brand' => 'পুরনো ব্র্যান্ড', 'brand_id' => null]);

        $this->assertSame(0, Brand::query()->where('name_en', 'পুরনো ব্র্যান্ড')->count());

        $this->foldAgain();

        $brand = Brand::query()->where('name_en', 'পুরনো ব্র্যান্ড')->first();

        $this->assertNotNull($brand, 'পুরনো লেখাটা সারি হয়ে বসেনি।');
        $this->assertSame($brand->id, $product->fresh()->brand_id,
            'পণ্যটা নতুন সারিটার সাথে বাঁধা পড়েনি।');
    }

    /**
     * পুরনো লেখার ঘরটা মুছে ফেলা হয়নি।
     *
     * বানানভেদগুলো জোড়া লাগানোর সময় আসল লেখাটা দেখতে না পেলে কোনটা
     * কোনটার বানানভেদ তা বলাই যেত না।
     */
    public function test_the_old_text_is_kept_so_spellings_can_be_matched(): void
    {
        $this->assertTrue(
            Schema::hasColumn('inv_products', 'brand'),
            'পুরনো লেখার ঘরটা মুছে ফেলা হয়েছে — জোড়া লাগানোর সময় আসল বানানটা আর দেখা যাবে না।'
        );
    }

    /** মাইগ্রেশনের ভাঁজ করার কাজটা আবার চালানো। */
    private function foldAgain(): void
    {
        $migration = require base_path(
            'app/Modules/MasterData/Database/Migrations/2026_09_02_100000_the_same_brand_spelled_four_ways.php'
        );

        $fold = (new \ReflectionClass($migration))->getMethod('fold');
        $fold->setAccessible(true);
        $fold->invoke($migration, 'brand', 'mdm_brands', 'brand_id');
    }

    // ── পর্দা ও সেটিংস ──────────────────────────────────────────────

    /** ব্র্যান্ডের তালিকাটা সেটিংসেই বসে, কোডে নয়। */
    public function test_the_brand_list_is_a_settings_screen(): void
    {
        $this->brand('Nestle');

        $this->get(route('master_data.brand.index'))
            ->assertOk()
            ->assertSee('Nestle');
    }

    /** নতুন ব্র্যান্ড ওখান থেকেই যোগ করা যায়। */
    public function test_a_company_can_add_its_own_brand(): void
    {
        $this->post(route('master_data.brand.store'), [
            'code' => 'PRAN',
            'name_en' => 'Pran',
            'name_bn' => 'প্রাণ',
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('mdm_brands', [
            'company_id' => $this->company->id,
            'code' => 'PRAN',
        ]);
    }

    /** শ্রেণিরও নিজের তালিকা — একই ভুল ওখানেও ছিল। */
    public function test_categories_are_rows_too(): void
    {
        ProductCategory::query()->create([
            'code' => 'BISCUIT',
            'name_en' => 'Biscuit',
            'name_bn' => 'বিস্কুট',
            'is_active' => true,
        ]);

        $this->get(route('master_data.product_category.index'))
            ->assertOk()
            ->assertSee('বিস্কুট');
    }

    /**
     * পণ্যের ফর্মে এখন বাছাই, টাইপ করা নয়।
     *
     * ── কেন এটাই আসল সারানো ─────────────────────────────────────────
     * সারিগুলো বানিয়ে ফর্মটা মুক্ত লেখাই রেখে দিলে আগামীকাল থেকেই আবার
     * নতুন বানানভেদ ঢুকত, আর ছয় মাস পরে ঠিক একই জায়গায় ফিরে আসতাম।
     */
    public function test_the_product_form_offers_a_list_not_a_text_box(): void
    {
        $this->brand('Nestle');

        $html = $this->get(route('inventory.product.create'))->assertOk()->getContent();

        $this->assertStringContainsString('name="brand_id"', $html,
            'ব্র্যান্ডের ঘরটা এখনো বাছাইয়ের তালিকা নয়।');
        $this->assertStringNotContainsString('name="brand"', $html,
            'মুক্ত লেখার ঘরটা রয়ে গেছে — কাল থেকেই আবার নতুন বানান ঢুকবে।');
    }

    /** রিপোর্টটার একটা দরজা আছে — নিবন্ধিত অথচ অপৌঁছনীয় নয়। */
    public function test_the_report_has_a_door(): void
    {
        $this->get(route('sales.report.show', ['slug' => 'by-brand']))
            ->assertOk()
            ->assertSee(__('sales::menu.by_brand'));
    }
}
