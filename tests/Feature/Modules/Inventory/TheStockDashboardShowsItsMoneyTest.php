<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Dashboard\InventoryBoard;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * মজুদের ড্যাশবোর্ড — টাকা আগে আর চার্টে টাকার অঙ্ক (মালিক, ১ অক্টোবর ২০২৬), এখন চূড়ান্ত নমুনার বোর্ডে (HmzeE5CN2wvtyLPE5exjvV, 10 নভেম্বর 2026 রাত)।
 *
 * দাবি: পাতা খোলে সমস্যা কেন্দ্র দিয়ে, ডানে মজুদের মূল্যের কোণা; 12 মাসের মজুদের ধারা মজুদের খাতা (1120) থেকে টাকায় — এ মাসে 1,50,000 ঢুকে
 * 50,000 বেরোলে মাস-শেষের মূল্য 1,00,000 বাড়ে; ⚠️ খরচের সংখ্যা, তাই খরচের চাবি (inventory.cost.view) ছাড়া ধারার ঘর নেই, চাবি দিলে আছে।
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

    public function test_the_page_opens_on_the_problem_centre_with_the_value_corner_beside_it(): void
    {
        $html = $this->get(route('module.dashboard', ['module' => 'inventory']))->assertOk()->getContent();

        $problems = strpos($html, 'data-inv-problems');
        $corner = strpos($html, 'data-inv-value-corner');

        $this->assertNotFalse($problems, '⛔ সমস্যা কেন্দ্র নেই।');
        $this->assertNotFalse($corner, '⛔ মজুদের মূল্যের কোণা নেই।');
        $this->assertLessThan($corner, $problems, '⛔ সমস্যা কেন্দ্র মূল্যের কোণার আগে নয় (নমুনায় বাঁয়ে, চোখের সামনে)।');
        $this->assertStringNotContainsString('data-stat-grid', $html, '⛔ পুরনো সংখ্যার কার্ড এখনো পাতায়।');
    }

    public function test_the_trend_counts_taka_from_the_inventory_account(): void
    {
        $before = $this->thisMonth();

        $this->move('150000', in: true);
        $this->move('50000', in: false);

        $after = $this->thisMonth();

        $this->assertSame(0, bccomp(bcsub($after, $before, 2), '100000', 2), '⛔ এ মাসের মজুদের মূল্য খাতার মতো 1,00,000 বাড়েনি।');
    }

    /** ⛔ একই মানুষ: খরচের চাবি ছাড়া মজুদের ধারার ঘর নেই, চাবি দিলে আছে। */
    public function test_the_money_trend_needs_the_cost_key(): void
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id);
        $clerk->givePermissionTo('inventory.stock.view');

        $html = $this->actingAs($clerk->fresh())->get(route('module.dashboard', ['module' => 'inventory']))->assertOk()->getContent();
        $this->assertStringContainsString('data-inv-problems', $html, 'প্রস্তুতিটাই ভুল — বোর্ডই খোলেনি।');
        $this->assertStringNotContainsString('data-inv-trend', $html, '⛔ খরচের চাবি ছাড়াই টাকার ধারা দেখা গেল।');
        $this->assertStringNotContainsString('data-inv-wh', $html, '⛔ খরচের চাবি ছাড়াই গুদাম ধরে মজুদের মূল্য দেখা গেল।');
        $this->assertStringNotContainsString('data-inv-roll', $html, '⛔ খরচের চাবি ছাড়াই মজুদের মূল্যের হিসাব দেখা গেল।');

        // ⛔ বোর্ডের বাকি ঘরে একটাও টাকার অঙ্ক নয় — সব কেনা দামে; জায়গায় "আছে, কিন্তু আপনার জন্য নয়" চিহ্ন
        $board = substr($html, (int) strpos($html, 'data-inv-problems'));
        $this->assertStringNotContainsString('৳', $board, '⛔ খরচের চাবি ছাড়াই বোর্ডে টাকার অঙ্ক।');
        $this->assertStringContainsString(\App\Core\Security\FieldSecurity::mask(), $board, 'প্রস্তুতিটাই ভুল — লুকানো অঙ্কের চিহ্নও নেই, তাহলে বোর্ড টাকাই দেখায়নি।');

        $clerk->givePermissionTo('inventory.cost.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $html = $this->actingAs($clerk->fresh())->get(route('module.dashboard', ['module' => 'inventory']))->assertOk()->getContent();
        $this->assertStringContainsString('data-inv-trend', $html, '⛔ খরচের চাবি দিলেও টাকার ধারা এল না।');
    }

    private function thisMonth(): string
    {
        app(\App\Core\Services\LedgerBalances::class)->forget();
        $trend = app(InventoryBoard::class)->trend();

        $this->assertNotNull($trend, 'মালিকের খরচের চাবি আছে, অথচ ধারা null।');

        return $trend[array_key_last($trend)]['value'];
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
