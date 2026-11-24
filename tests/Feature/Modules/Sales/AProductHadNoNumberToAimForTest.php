<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Imports\ProductTargetImporter;
use App\Modules\Sales\Models\ProductTarget;
use App\Modules\Sales\Reports\ProductInsightReports;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\ProductTargetService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ পণ্যের মাসিক লক্ষ্য বসে, আর "লক্ষ্য বনাম বিক্রয় — পণ্য" সেই লক্ষ্যের পাশে বিলের লাইনের বিক্রি দেখায় — মালিক, ২৩ নভেম্বর ২০২৬
 * (সমন্বয়কের মারফত: "হ্যাঁ, পণ্য ধরে লক্ষ্য চাই")।
 *
 * ⓘ আগে লক্ষ্য ছিল কেবল বিক্রয়কর্মীর, ডিলারের আর রুটের — পণ্যের কোনো সংখ্যা ছিল না, তাই রিপোর্টের পণ্যের রূপটাই ছিল না।
 */
final class AProductHadNoNumberToAimForTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Product $product;

    private ProductTargetService $targets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->targets = app(ProductTargetService::class);
    }

    public function test_a_target_is_set_changed_and_cleared_one_row_per_product_and_month(): void
    {
        $month = Carbon::today()->startOfMonth();

        $this->targets->setOne((int) $this->product->id, $month, '120', '50000');
        $this->targets->setOne((int) $this->product->id, $month, '150', '');

        $rows = ProductTarget::query()->where('product_id', $this->product->id)->get();
        $this->assertCount(1, $rows, '⛔ একই পণ্য একই মাসে দুই সারি — লক্ষ্য দুইবার গোনা হত।');
        $this->assertSame('150.0000', (string) $rows[0]->qty);
        $this->assertSame('0.0000', (string) $rows[0]->amount, '⛔ ফাঁকা ঘর আগের টাকার লক্ষ্য রেখে দিল।');
        $this->assertSame((int) $this->company->id, (int) $rows[0]->company_id);

        $this->targets->setOne((int) $this->product->id, $month, '', '0');
        $this->assertSame(0, ProductTarget::query()->where('product_id', $this->product->id)->count(), '⛔ দুই ঘরই ফাঁকা বা শূন্য — তবু লক্ষ্যের সারি রইল।');
    }

    public function test_a_bad_number_a_strange_product_and_a_far_month_are_refused(): void
    {
        $month = Carbon::today()->startOfMonth();

        foreach (['-5', '1e5', 'abc'] as $bad) {
            try {
                $this->targets->setOne((int) $this->product->id, $month, $bad, '');
                $this->fail("⛔ \"{$bad}\" লক্ষ্যের পরিমাণ হিসেবে নেওয়া হল।");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(ValidationException::class);
        $this->targets->setOne(987654321, $month, '10', '');
    }

    public function test_the_page_shows_and_saves_and_a_person_without_the_key_cannot_save(): void
    {
        $month = Carbon::today()->format('Y-m');

        $this->get(route('sales.product_target.index', ['month' => $month, 'q' => $this->product->code]))->assertOk()->assertSee($this->product->code);

        $this->post(route('sales.product_target.store'), ['month' => $month.'-01', 'target' => [$this->product->id => ['qty' => '40', 'amount' => '900']]])
            ->assertRedirect(route('sales.product_target.index', ['month' => $month]));
        $this->assertSame('900.0000', (string) ProductTarget::query()->where('product_id', $this->product->id)->value('amount'));

        $stranger = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $stranger->companies()->attach($this->company->id, ['is_active' => true]);
        $this->actingAs($stranger->fresh());

        $this->get(route('sales.product_target.index'))->assertForbidden();
        $this->post(route('sales.product_target.store'), ['month' => $month.'-01', 'target' => [$this->product->id => ['qty' => '1']]])->assertForbidden();
    }

    /** ⭐ পাতায় পাতায়: এক পাতার সংরক্ষণ অন্য পাতার লক্ষ্য ছোঁয় না, মোট গোটা মাসের, খোঁজা সব পাতার পণ্যে */
    public function test_a_save_touches_only_the_rows_it_sends_and_the_total_is_the_whole_months(): void
    {
        for ($i = Product::query()->count(); $i < 55; $i++) {
            $copy = $this->product->replicate(['public_id']);
            $copy->forceFill(['code' => 'ZZ-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'barcode' => null, 'name_en' => 'Target Probe '.$i, 'name_bn' => 'Target Probe '.$i])->save();
        }
        $month = Carbon::today()->startOfMonth();
        $index = fn (array $more = []) => $this->get(route('sales.product_target.index', ['month' => $month->format('Y-m'), 'per_page' => 50] + $more))->assertOk();

        $first = $index()->viewData('rows');
        $last = $index(['page' => $first->lastPage()])->viewData('rows');
        $this->assertGreaterThan(1, $first->lastPage(), 'প্রস্তুতিটাই ভুল — পাতা একটাই, দাবি কিছু মাপছে না।');
        [$mine, $other] = [$first->items()[0]['product'], $last->items()[0]['product']];
        $this->assertNotSame((int) $mine->id, (int) $other->id);

        $this->targets->setOne((int) $other->id, $month, '7', '300');
        $this->post(route('sales.product_target.store'), ['month' => $month->toDateString(), 'page' => 1, 'target' => [$mine->id => ['qty' => '', 'amount' => '200']]])->assertRedirect();

        $this->assertSame('300.0000', (string) ProductTarget::query()->where('product_id', $other->id)->value('amount'), '⛔ এক পাতার সংরক্ষণ অন্য পাতার লক্ষ্য মুছল।');
        $this->assertSame('500.0000', $index()->viewData('sum')['amount'], '⛔ মোট গোটা মাসের নয়, কেবল এই পাতার।');

        $found = $index(['q' => $other->code])->assertSee($other->code)->viewData('rows');
        $this->assertSame([(int) $other->id], array_map(fn (array $r) => (int) $r['product']->id, $found->items()), '⛔ খোঁজা কেবল চলতি পাতায়।');
        $this->assertSame(0, count($index(['q' => '%'])->viewData('rows')->items()), '⛔ "%" লিখলে সব পণ্য এল।');
    }

    public function test_the_import_takes_the_same_road_as_the_page(): void
    {
        $importer = app(ProductTargetImporter::class);
        $month = Carbon::today()->format('Y-m');

        $this->assertSame([], $importer->check(['code' => $this->product->code, 'month' => $month, 'qty' => '75', 'amount' => '']));
        $this->assertNotEmpty($importer->check(['code' => 'NO-SUCH-PRODUCT', 'month' => $month, 'qty' => '75']), '⛔ অচেনা কোড ধরা পড়ল না।');
        $this->assertNotEmpty($importer->check(['code' => $this->product->code, 'month' => $month, 'qty' => '', 'amount' => '']), '⛔ দুই লক্ষ্যই ফাঁকা — তবু সারিটা ঠিক বলা হল।');
        $this->assertNotEmpty($importer->check(['code' => $this->product->code, 'month' => 'nonsense', 'qty' => '5']), '⛔ ভুল মাস ধরা পড়ল না।');

        $importer->import(['code' => $this->product->code, 'month' => $month, 'qty' => '75', 'amount' => '']);
        $this->assertSame('75.0000', (string) ProductTarget::query()->where('product_id', $this->product->id)->value('qty'));
        $this->assertSame(['sales.target.manage'], ProductTargetImporter::requiredPermissions());
    }

    public function test_the_report_puts_the_sale_beside_the_target_and_counts_the_rate_from_the_two(): void
    {
        $today = now()->toDateString();
        $run = fn () => app(ReportEngine::class)->run(ProductInsightReports::TARGET, ['from' => $today, 'to' => $today], 1, 500);
        $mine = fn ($result) => collect($result->rows)->first(fn (array $r) => (int) $r['product_id'] === (int) $this->product->id);

        $soldBefore = (float) ($mine($run())['qty'] ?? 0);
        $netBefore = (float) ($mine($run())['net_sales'] ?? 0);

        $this->targets->setOne((int) $this->product->id, Carbon::today()->startOfMonth(), '200', '4000');
        app(DirectSaleService::class)->complete(
            ['customer_id' => (int) Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
            [['product_id' => $this->product->id, 'qty' => '20', 'rate' => '10', 'free_qty' => '0']],
        );

        $result = $run();
        $row = $mine($result);

        $this->assertNotNull($row, '⛔ লক্ষ্য আর বিক্রি দুটোই আছে, অথচ পণ্যটা রিপোর্টে নেই।');
        $this->assertEqualsWithDelta(200.0, (float) $row['target_qty'], 0.0001, '⛔ লক্ষ্যের পরিমাণ সারিতে ওঠেনি।');
        $this->assertEqualsWithDelta(4000.0, (float) $row['target'], 0.01, '⛔ লক্ষ্যের টাকা সারিতে ওঠেনি।');
        $this->assertEqualsWithDelta($soldBefore + 20.0, (float) $row['qty'], 0.0001, '⛔ বিক্রির পরিমাণ বিলের লাইনের সমান বাড়েনি।');
        $this->assertGreaterThan($netBefore, (float) $row['net_sales'], '⛔ নিট বিক্রি বাড়েনি।');
        $this->assertEqualsWithDelta((float) $row['qty'] * 100 / 200, (float) $row['qty_achieved'], 0.01, '⛔ পরিমাণে অর্জন বিক্রি ÷ লক্ষ্য নয়।');
        $this->assertEqualsWithDelta((float) $row['net_sales'] * 100 / 4000, (float) $row['achieved'], 0.01, '⛔ টাকায় অর্জন নিট বিক্রি ÷ লক্ষ্য নয়।');

        // ⭐ মোটে টাকার হার মোট ÷ মোট; নিট বিক্রি পণ্যভিত্তিক মুনাফার রিপোর্টের সমান
        $this->assertSame(
            bcdiv(bcmul((string) $result->totals['net_sales'], '100', 6), (string) $result->totals['target'], 2),
            bcadd((string) $result->totals['achieved'], '0', 2), '⛔ মোটের অর্জন মোট ÷ মোট নয়।');
        $this->assertEqualsWithDelta(
            (float) app(ReportEngine::class)->run('sales.by_product', ['from' => $today, 'to' => $today], 1, 500)->totals['revenue'],
            (float) $result->totals['net_sales'], 0.01, '⛔ নিট বিক্রি পণ্যভিত্তিক মুনাফার রিপোর্টের মোটের সমান নয়।');

        // ⭐ পাতার মাসের মোট — খোঁজা বা পাতা যা-ই হোক, রিপোর্টের মোট
        $monthSum = $this->targets->monthTotals(Carbon::today()->startOfMonth());
        $this->assertSame('4000.0000', $monthSum['amount'], '⛔ মাসের লক্ষ্যের মোট ভুল।');
        // ⭐ লক্ষ্যের পাতার অর্জন রিপোর্টের একই অঙ্ক
        $board = collect($this->targets->board(Carbon::today()->startOfMonth(), $this->product->code)->items())->first(fn (array $r) => (int) $r['product']->id === (int) $this->product->id);
        $month = app(ReportEngine::class)->run(ProductInsightReports::TARGET, ['from' => Carbon::today()->startOfMonth()->toDateString(), 'to' => $today], 1, 500);
        $this->assertEqualsWithDelta((float) $mine($month)['net_sales'], (float) $board['sold_amount'], 0.01, '⛔ লক্ষ্যের পাতা আর রিপোর্ট দুই অঙ্ক বলছে।');
    }
}
