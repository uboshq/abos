<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Services\StockFacts;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * মজুদের ড্যাশবোর্ড — টাকা আগে, এক লাইনে, আর চার্টে টাকার অঙ্ক। মালিক, ১ অক্টোবর ২০২৬।
 *
 * মালিকের তিনটা কথা: *"ei 6ti box ek line daw"*, *"মজুদের মূল্য … ei boxta 1st e daw"*,
 * আর চার্ট দেখিয়ে *"ei chart e takar amount taw dio"*।
 *
 * ── ⓘ চার্টটা কেন টাকায় বদলাল, কেবল লেবেল বসল না ────────────────────────
 * আগের চার্ট নড়াচড়ার **পরিমাণ** যোগ করত — বস্তা, কার্টুন আর পিস এক যোগফলে; তার পাশে টাকা বসালে দুই
 * অর্থের দুই সংখ্যা এক বারে থাকত। এখন বার নিজেই টাকা: মজুদের খাতে (১১২০) ঢোকা ডেবিট, বেরোনো ক্রেডিট,
 * কেনা দরে ([[StockFacts::monthlyValueFlow()]]) — আর মাথায় ছোট করে লেখা, মাউস রাখলে পুরো অঙ্ক।
 * ⚠️ খরচের সংখ্যা, তাই `inventory.cost.view` ছাড়া আগের পরিমাণের চার্টই থাকে।
 */
final class TheStockDashboardShowsItsMoneyTest extends TestCase
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

        app(StandardChart::class)->install();
    }

    public function test_the_six_boxes_sit_in_one_row_with_the_stock_value_first(): void
    {
        $html = $this->get(route('module.dashboard', ['module' => 'inventory']))->assertOk()->getContent();

        $this->assertStringContainsString('xl:grid-cols-6', $html, '⛔ ছয়টা বাক্স বড় পর্দায় এক লাইনে বসার ব্যবস্থা নেই।');

        $value = strpos($html, e(__('inventory::overview.stock_value')));
        $available = strpos($html, e(__('inventory::overview.available')));

        $this->assertNotFalse($value, 'প্রস্তুতিটাই ভুল — মজুদের মূল্যের বাক্স নেই।');
        $this->assertNotFalse($available, 'প্রস্তুতিটাই ভুল — বিক্রয়যোগ্যের বাক্স নেই।');
        $this->assertLessThan($available, $value, '⛔ মজুদের মূল্য প্রথম বাক্স নয়।');
    }

    public function test_the_chart_counts_taka_from_the_inventory_account_and_prints_it_on_the_bars(): void
    {
        $before = $this->thisMonth();

        $this->move('150000', in: true);
        $this->move('50000', in: false);

        $after = $this->thisMonth();

        $this->assertSame(0, bccomp(bcsub($after['in'], $before['in'], 2), '150000', 2), '⛔ এ মাসে ঢোকা টাকা ১,৫০,০০০ বাড়েনি।');
        $this->assertSame(0, bccomp(bcsub($after['out'], $before['out'], 2), '50000', 2), '⛔ এ মাসে বেরোনো টাকা ৫০,০০০ বাড়েনি।');

        $html = $this->get(route('module.dashboard', ['module' => 'inventory']))->assertOk()->getContent();

        $this->assertStringContainsString(e(__('inventory::overview.flow_value')), $html, '⛔ চার্ট টাকার নয়।');
        $this->assertStringContainsString(e(StockFacts::shortTaka($after['in'])), $html, '⛔ বারের মাথায় ঢোকা টাকার অঙ্ক নেই।');
        $this->assertStringContainsString(e(StockFacts::shortTaka($after['out'])), $html, '⛔ বারের মাথায় বেরোনো টাকার অঙ্ক নেই।');
        $this->assertStringContainsString(e(Money::format($after['in'])), $html, '⛔ মাউস রাখলে পুরো অঙ্ক নেই।');
    }

    /** ⛔ একই মানুষ: খরচের চাবি ছাড়া টাকার চার্ট নেই, চাবি দিলে আছে। */
    public function test_the_money_chart_needs_the_cost_key(): void
    {
        $this->move('150000', in: true);

        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id);
        $clerk->givePermissionTo('inventory.stock.view');

        $html = $this->actingAs($clerk->fresh())->get(route('module.dashboard', ['module' => 'inventory']))->assertOk()->getContent();

        $this->assertStringNotContainsString(e(__('inventory::overview.flow_value')), $html, '⛔ খরচের চাবি ছাড়াই টাকার চার্ট দেখা গেল।');
        $this->assertStringContainsString(e(__('inventory::overview.flow')), $html, 'প্রস্তুতিটাই ভুল — পরিমাণের চার্টও নেই।');

        $clerk->givePermissionTo('inventory.cost.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $html = $this->actingAs($clerk->fresh())->get(route('module.dashboard', ['module' => 'inventory']))->assertOk()->getContent();

        $this->assertStringContainsString(e(__('inventory::overview.flow_value')), $html, '⛔ খরচের চাবি দিলেও টাকার চার্ট এল না।');
    }

    /** @return array{month: string, in: string, out: string} */
    private function thisMonth(): array
    {
        $flow = app(StockFacts::class)->monthlyValueFlow();

        $this->assertNotNull($flow, 'মালিকের খরচের চাবি আছে, অথচ টাকার চার্ট null।');

        return $flow[array_key_last($flow)];
    }

    /** মজুদের খাতে এ মাসের একটা দাখিলা — পোস্টিং ইঞ্জিন দিয়েই। */
    private function move(string $amount, bool $in): void
    {
        $inventory = StandardChart::find(StandardChart::INVENTORY);
        $capital = StandardChart::find(StandardChart::OWNER_CAPITAL);

        app(PostingEngine::class)->post(
            sourceType: 'test:stock-flow',
            sourceId: random_int(1, 999999),
            trxDate: now()->toDateString(),
            lines: $in
                ? [['account_id' => $inventory->id, 'debit' => $amount], ['account_id' => $capital->id, 'credit' => $amount]]
                : [['account_id' => $capital->id, 'debit' => $amount], ['account_id' => $inventory->id, 'credit' => $amount]],
            branchId: $this->company->defaultBranch()?->id,
        );
    }
}
