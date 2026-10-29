<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Finance\Services\CashForecast;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ ছয়টা টাকা বা পরিমাণের তালিকার নিচে মোট ছিল না — ওয়েব-মান ধাপ ২, ২০ অক্টোবর ২০২৬ (লাইভ নিরীক্ষা; মালিক, ১ অক্টোবর
 * ২০২৬: *"kono list er niche grand total nai keno"*)।
 *
 * দাবি (আসল দরজা দিয়ে):
 *  - লক্ষ্য, ডিলারের লক্ষ্য, নগদের পূর্বাভাস, প্রিন্সিপালের খোলা মজুদ আর গণনার পাতায় সারি থাকলে সর্বমোটের সারি আছে;
 *    ডেলিভারির মাপকাঠিতে দেরির সারি থাকলে আছে;
 *  - নগদের পূর্বাভাসের সর্বমোট: পাওনা = চার ঘরের যোগ, শেষের জের = শেষ ঘরের জের (যোগ নয়);
 *  - পরিমাণ এক সংখ্যায় মেশানো হয় না (প্রিন্সিপাল, গণনা): সর্বমোট বলে কয়টা লট / কয়টা পণ্য;
 *  - গণনার ঘরগুলোর লেবেল পণ্যের নামসহ।
 */
final class TheListEndedWithoutATotalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_each_list_ends_in_a_grand_total_when_it_has_rows(): void
    {
        $opened = 0;
        $wrong = [];

        foreach (['sales.target.index' => 'rows', 'sales.customer_target.index' => 'rows', 'finance.forecast.cash' => null,
            'inventory.stock.opening.principal' => 'rows', 'inventory.count.create' => 'products',
            'sales.delivery_performance.index' => 'late'] as $name => $rowsKey) {
            $response = $this->get(route($name));

            if (in_array($response->status(), [403, 404], true)) {
                continue;
            }

            $response->assertOk();
            $opened++;

            $rows = $rowsKey === null ? [1] : $response->viewData($rowsKey);
            $hasRows = is_countable($rows) ? count($rows) > 0 : ! $rows->isEmpty();

            if ($hasRows && ! str_contains((string) $response->getContent(), 'data-grand-total')) {
                $wrong[] = $name;
            }
        }

        $this->assertGreaterThanOrEqual(5, $opened, 'মাত্র '.$opened.'টা পাতা খুলল — দাবির ভিত্তি নেই');
        $this->assertSame([], $wrong, "⛔ সারি আছে, সর্বমোট নেই:\n  ".implode("\n  ", $wrong));
    }

    public function test_the_forecast_total_sums_the_flows_but_keeps_the_last_closing(): void
    {
        $forecast = app(CashForecast::class)->build();
        $html = (string) $this->get(route('finance.forecast.cash'))->assertOk()->getContent();

        $foot = substr($html, (int) strpos($html, 'data-grand-total-row'));
        $receivables = array_reduce($forecast['rows'], fn ($s, $r) => bcadd($s, (string) $r['receivables'], 4), '0');
        $closing = (string) end($forecast['rows'])['closing'];

        $this->assertStringContainsString(Money::format($receivables), $foot, '⛔ পাওনার সর্বমোট চার ঘরের যোগ নয়');
        $this->assertStringContainsString(Money::format($closing), $foot, '⛔ শেষের জের শেষ ঘরের জের নয়');
    }

    public function test_the_count_sheet_names_each_box_and_counts_products_not_mixed_units(): void
    {
        $response = $this->get(route('inventory.count.create'))->assertOk();
        $products = $response->viewData('products');

        $this->assertGreaterThan(0, count($products), 'প্রস্তুতি: ডেমোতে গোনার পণ্য নেই — দাবির ভিত্তি নেই');

        $first = collect($products)->first();
        $response->assertSee(__('inventory::field.counted_qty').' — '.$first->name())
            ->assertSee(__('inventory::message.count_products', ['count' => number_format(count($products))]));
    }
}
