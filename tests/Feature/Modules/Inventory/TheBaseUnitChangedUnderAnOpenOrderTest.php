<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\ProductPackService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * খোলা DO-র নিচে পণ্যের মূল একক বদলে যেত — Inventory অডিট গ১৬, ৪ অক্টোবর ২০২৬।
 *
 * ⛔ মূল একক বদলের আগে কেবল হাতে-লেখা ১৪টা টেবিল দেখা হত ([[PackSnapshot::TABLES]])। DO, দরপত্র, গণনা, উৎপাদন, পরিদর্শন,
 * রিকুইজিশনসহ আরও অনেক কাগজ সেখানে নেই — তাই খোলা DO-র "২৪" পিস থেকে নীরবে ২৪ কার্টন হয়ে যেত।
 * ⭐ এখন তালিকা ডাটাবেজ থেকেই: যে টেবিলে `product_id` আর পরিমাণের ঘর আছে, তার যেকোনো সারি একক বদল থামায় — কাল নতুন কাগজ এলেও।
 */
final class TheBaseUnitChangedUnderAnOpenOrderTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⓘ কোনো কাগজে নেই এমন নতুন পণ্য
        $this->product = Product::query()->where('company_id', $company->id)->orderBy('id')->firstOrFail()->replicate(['public_id']);
        $this->product->forceFill(['code' => 'BASE-TEST-1', 'barcode' => null, 'name_en' => 'Base Test Biscuit', 'name_bn' => 'একক পরীক্ষার বিস্কুট'])->save();
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function papersTheOldListForgot(): array
    {
        return [
            'DO-র লাইন' => ['sal_delivery_order_lines', ['delivery_order_id' => 999999, 'qty' => 24]],
            'গণনার লাইন' => ['inv_stock_count_lines', ['stock_count_id' => 999999, 'book_qty' => 24, 'counted_qty' => 24, 'difference' => 0]],
            'দরপত্রের লাইন' => ['sal_quotation_lines', ['sales_quotation_id' => 999999, 'qty' => 24, 'rate' => 10, 'amount' => 240, 'line_no' => 1]],
        ];
    }

    /**
     * ⛔ পুরনো তালিকার বাইরের কাগজে পণ্যটা থাকলেও একক বদল থামে।
     *
     * ⓘ সারিটা সরাসরি বসানো (মাথার কাগজ ছাড়া) — দাবিটা পাহারার, কাগজ বানানোর পথের নয়।
     *
     * @param  array<string, mixed>  $row
     */
    #[DataProvider('papersTheOldListForgot')]
    public function test_a_paper_outside_the_old_list_still_locks_the_base_unit(string $table, array $row): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table($table)->insert([...$row, 'product_id' => $this->product->id,
            ...(\Illuminate\Support\Facades\Schema::hasColumn($table, 'company_id') ? ['company_id' => $this->product->company_id] : [])]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        try {
            app(ProductPackService::class)->assertBaseCanChange($this->product, true);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('unit_id', $e->errors());

            return;
        }

        $this->fail("⛔ {$table}-এ পণ্যটার ২৪ লেখা, তবু মূল একক বদলানো গেল — ২৪ পিস হয়ে যেত ২৪ কার্টন।");
    }

    /** ⭐ পাহারা সব দরজা বন্ধ করে না: কোনো কাগজে না থাকা পণ্যের (নিজের প্যাকের সারি থাকলেও) একক বদলানো যায় */
    public function test_a_product_on_no_paper_can_still_change_its_base_unit(): void
    {
        DB::table('inv_product_units')->insert([
            'company_id' => $this->product->company_id, 'product_id' => $this->product->id, 'unit_id' => $this->product->unit_id,
            'factor' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(ProductPackService::class)->assertBaseCanChange($this->product, true);

        $this->addToAssertionCount(1);
    }
}
