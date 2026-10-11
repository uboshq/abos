<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\CostLayerUse;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ সরবরাহকারী অন্য দরে বিল করলে স্তর নতুন দাম নেয় — বিলের দিন থেকে, পেছনে নয় (পুরো-ERP অডিট, ৯ অক্টোবর ২০২৬, ক্রয় ⚠️৩,
 * স্তরের দিক; খাতার দিক ec)।
 *
 * ⓘ [[CostLayerService::revalue()]] স্তরের দাম বদলায় না — বাকি মাল পুরনো দামে খালি করে আর নতুন দামে নতুন স্তর বসায়, দুটোই
 * বিলের দিনে। তাই আগের মাসের মজুদ-মূল্য নড়ে না, পুরনো স্তরের কোনো ঘর বদলায় না, আর পরের বিক্রি নতুন দাম টানে। ফেরত দেয় তাকের
 * আর বিক্রি হয়ে যাওয়া অংশের পার্থক্য — খাতা কোথায় বসবে, ডাকার পক্ষ জানে।
 */
final class ALayerTakesTheBillsPriceFromTheBillsDayTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Product $product;

    private string $lastMonth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->product = Product::query()->create(['code' => 'RV-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Revalue probe',
            'name_bn' => 'দাম-বদলের নমুনা', 'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true]);
        $this->lastMonth = Carbon::today()->subMonthNoOverflow()->startOfMonth()->addDays(4)->toDateString();
    }

    public function test_the_bills_price_takes_over_from_the_bills_day_and_says_what_was_sold(): void
    {
        $costs = $this->costs();
        $costs->receive($this->product, '10', '100', 'test_receipt', 1, 'GRN-1', $this->lastMonth);
        $costs->issue($this->product, '4', 'test_sale', 1, 'S-1', $this->lastMonth);
        $closedMonth = $this->monthValue();
        $old = $this->history(CostLayer::query()->where('source_id', 1)->where('source_type', 'test_receipt')->sole());

        $split = $costs->revalue('test_receipt', 1, $this->product->id, '110', now()->toDateString());

        $this->assertSame(['shelf_qty' => '6.0000', 'shelf_diff' => '60.0000', 'sold_qty' => '4.0000', 'sold_diff' => '40.0000'], $split,
            '⛔ তাকের আর বিক্রি হওয়া অংশের ভাগ ভুল।');
        $this->assertSame(0, bccomp($this->monthValue(), $closedMonth, 4), '⛔ বিলের দিনের দাম আগের মাসের মজুদ-মূল্য বদলে দিল।');
        $this->assertSame($old, $this->history(CostLayer::query()->where('source_id', 1)->where('source_type', 'test_receipt')->orderBy('id')->first()), '⛔ পুরনো স্তরের কোনো ঘর বদলে গেল।');
        $this->assertSame(0, bccomp($costs->valueOnHand($this->product), '660', 4), '⛔ তাকের ৬টা নতুন দামে (১১০) দাঁড়াল না।');
        $this->assertSame(0, bccomp($costs->issue($this->product, '1', 'test_sale', 2, 'S-2')['cost'], '110', 4), '⛔ পরের বিক্রি নতুন দাম টানল না।');

        // ⓘ একই দামে আবার — কিছুই নয় (বিল আবার নিশ্চিত হলে দুবার ডাকা হতে পারে)
        $rows = [CostLayer::query()->count(), CostLayerUse::query()->count()];
        $this->assertSame(['shelf_qty' => '0.0000', 'shelf_diff' => '0.0000', 'sold_qty' => '0.0000', 'sold_diff' => '0.0000'],
            $costs->revalue('test_receipt', 1, $this->product->id, '110', now()->toDateString()), '⛔ একই দামে দ্বিতীয় ডাক আবার পার্থক্য দিল।');
        $this->assertSame($rows, [CostLayer::query()->count(), CostLayerUse::query()->count()], '⛔ একই দামে দ্বিতীয় ডাক সারি লিখল।');
    }

    public function test_an_unchanged_price_writes_nothing(): void
    {
        $this->costs()->receive($this->product, '5', '80', 'test_receipt', 2, 'GRN-2');
        $rows = [CostLayer::query()->count(), CostLayerUse::query()->count()];

        $this->assertSame(['shelf_qty' => '0.0000', 'shelf_diff' => '0.0000', 'sold_qty' => '0.0000', 'sold_diff' => '0.0000'],
            $this->costs()->revalue('test_receipt', 2, $this->product->id, '80', now()->toDateString()));
        $this->assertSame($rows, [CostLayer::query()->count(), CostLayerUse::query()->count()], '⛔ দাম না বদলেও সারি লেখা হল।');
    }

    public function test_a_receipt_can_still_be_cancelled_after_a_new_price_unless_its_goods_were_sold(): void
    {
        $costs = $this->costs();

        // ⓘ কিছুই বেরোয়নি — নতুন দামের পরেও বাতিল চলে, আর সব খালি হয়
        $costs->receive($this->product, '5', '80', 'test_receipt', 3, 'GRN-3');
        $costs->revalue('test_receipt', 3, $this->product->id, '90', now()->toDateString());
        $costs->cancelLayers('test_receipt', 3, now()->toDateString());
        $this->assertSame(0, bccomp((string) CostLayer::query()->where('source_id', 3)->sum('qty_remaining'), '0', 4), '⛔ বাতিলের পরেও স্তরে মাল।');

        // ⛔ দুটো বেরিয়ে গেছে — নতুন দামে সরানোর পরেও চালানটা "ছোঁয়া"
        $costs->receive($this->product, '5', '80', 'test_receipt', 4, 'GRN-4');
        $costs->issue($this->product, '2', 'test_sale', 9, 'S-9');
        $costs->revalue('test_receipt', 4, $this->product->id, '90', now()->toDateString());

        $this->expectException(ValidationException::class);
        $costs->cancelLayers('test_receipt', 4, now()->toDateString());
    }

    /** @return array<string, string> স্তরের ইতিহাসের তিন ঘর — লেখা হিসেবে, তুলনার জন্য */
    private function history(CostLayer $layer): array
    {
        return array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (string) $v,
            $layer->only(['qty_in', 'unit_cost', 'trx_date']));
    }

    private function monthValue(): string
    {
        $rows = (app(ReportEngine::class)->get('inventory.stock_value')->query)([
            'company_id' => $this->company->id, 'branch_id' => null,
            'from' => Carbon::parse($this->lastMonth)->startOfMonth()->toDateString(),
            'to' => Carbon::parse($this->lastMonth)->endOfMonth()->toDateString(),
        ])->get();

        return (string) ($rows->firstWhere('product_id', $this->product->id)?->closing_value ?? '0');
    }

    private function costs(): CostLayerService
    {
        return app(CostLayerService::class);
    }
}
