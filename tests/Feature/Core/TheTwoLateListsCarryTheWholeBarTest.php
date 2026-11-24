<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ মাস বন্ধের পাতা আর খোলা মজুদের তালিকা-অংশ পুরো বারসহ (২৪ নভেম্বর ২০২৬), কেবল তিন ABOS রূপে।
 *
 * দাবি: আগের অর্থবছরের মাস পাতায় আসে আর খোলা যায়, খোলার নিয়ম অটুট; অবস্থা আর খোঁজা ছাঁকে; না বাছলে আগের মতো চলতি বছর;
 * খোলা মজুদে খোঁজা, গুদাম আর তারিখ সারি ও নিচের মোট দুটোই ছাঁকে, উপরের মোট নয়; SAP-এ বার বা প্যানেল নেই।
 */
final class TheTwoLateListsCarryTheWholeBarTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->look('rose');
    }

    public function test_an_earlier_years_month_is_reachable_and_reopens_by_the_same_rules(): void
    {
        $current = FinancialYear::query()->where('is_current', true)->firstOrFail();
        $old = FinancialYear::query()->create(['company_id' => $this->company->id, 'name' => 'EARLIER-FY',
            'starts_on' => $current->starts_on->copy()->subYear()->toDateString(), 'ends_on' => $current->starts_on->copy()->subDay()->toDateString(), 'is_current' => false]);
        $first = $old->starts_on->copy();
        $lock = fn ($m, string $why) => PeriodLock::query()->create(['company_id' => $this->company->id, 'year' => (int) $m->year, 'month' => (int) $m->month,
            'reason' => $why, 'locked_by' => $this->owner->id, 'locked_at' => now()]);
        $a = $lock($first, 'WHY-FIRST');
        $b = $lock($first->copy()->addMonthNoOverflow(), 'WHY-SECOND');

        $this->get(route('accounts.period.index'))->assertOk()->assertDontSee('WHY-FIRST')->assertSee('EARLIER-FY');
        $this->get(route('accounts.period.index', ['fy' => $old->id]))->assertOk()->assertSee('WHY-FIRST')->assertSee('WHY-SECOND');
        $this->get(route('accounts.period.index', ['fy' => $old->id, 'q' => 'why-sec']))->assertSee('WHY-SECOND')->assertDontSee('WHY-FIRST');
        $this->get(route('accounts.period.index', ['fy' => $old->id, 'state' => 'open']))->assertDontSee('WHY-FIRST');
        $this->get(route('accounts.period.index', ['fy' => '99999999', 'state' => 'x']))->assertOk()->assertDontSee('WHY-FIRST');

        $this->post(route('accounts.period.reopen', $a), ['reason' => 'ভুল ছিল'])->assertSessionHasErrors('reason');
        $this->post(route('accounts.period.reopen', $b), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('accounts.period.reopen', $b), ['reason' => 'ভুল ছিল'])->assertSessionHasNoErrors();
        $this->assertNull(PeriodLock::query()->find($b->id));
    }

    public function test_the_opening_stock_list_is_narrowed_rows_and_footer_alike(): void
    {
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $products = Product::query()->orderBy('id')->limit(2)->get();
        foreach ([['OPN-ALPHA', '11'], ['OPN-BETA', '13']] as $i => [$code, $qty]) {
            $made = Product::query()->create(['company_id' => $this->company->id, 'code' => $code, 'name_en' => $code, 'name_bn' => $code,
                'unit_id' => $products[0]->unit_id, 'purchase_price' => '100', 'sale_price' => '120', 'is_active' => true]);
            $this->post(route('inventory.stock.opening.store'), ['product_id' => $made->id, 'warehouse_id' => $warehouse->id, 'qty' => $qty, 'unit_cost' => '100',
                'trx_date' => now()->toDateString()])->assertSessionHasNoErrors();
        }

        $page = fn (array $q) => $this->get(route('inventory.stock.opening', $q))->assertOk();
        $all = $page([]);
        $this->assertSame(0, bccomp($all->viewData('total'), $all->viewData('shownTotal'), 4));
        $one = $page(['q' => 'OPN-ALPHA']);
        $this->assertSame(['OPN-ALPHA'], collect($one->viewData('entered')->items())->pluck('product_code')->all());
        $this->assertSame(0, bccomp('1100', $one->viewData('shownTotal'), 2), '⛔ নিচের মোট ছাঁকা সারির নয়।');
        $this->assertSame($all->viewData('total'), $one->viewData('total'), '⛔ উপরের মোট ছাঁকনিতে বদলে গেল।');
        $this->assertCount(0, $page(['warehouse' => '99999999'])->viewData('entered')->items());
        $this->assertCount(0, $page(['to' => '2001-01-01'])->viewData('entered')->items());
        $this->assertGreaterThan(1, count($page(['from' => 'garbage', 'warehouse' => 'x'])->viewData('entered')->items()), 'ভুল মানে তালিকা খালি হওয়ার কথা নয়।');
        $this->assertSame('OPN-ALPHA', collect($page(['q' => 'OPN-', 'sort' => 'oldest'])->viewData('entered')->items())->first()->product_code);
    }

    public function test_only_the_three_looks_get_the_bar(): void
    {
        foreach ([route('accounts.period.index'), route('inventory.stock.opening')] as $url) {
            foreach (['rose', 'navy', 'blue'] as $look) {
                $this->look($look);
                $this->get($url)->assertOk()->assertSee('id="list-form"', false);
            }
            $this->look('tiles');
            $this->get($url)->assertOk()->assertDontSee('id="list-form"', false);
        }
    }

    private function look(string $look): void
    {
        User::query()->whereKey($this->owner->id)->update(['ui' => $look]);
        $this->actingAs(User::query()->findOrFail($this->owner->id));
    }
}
