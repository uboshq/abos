<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * সম্পদ বিক্রির লাভ অবচয়ের খাতে লুকিয়ে থাকত — চেকলিস্ট (অডিট ২৭ সেপ্টেম্বর) §২, ২ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * [[FixedAssetService::dispose()]] বইমূল্যের সাথে বিক্রির দামের তফাতটা সম্পদের **অবচয়ের খরচের খাতে** বসাত। লাভ
 * হলে অবচয় ঋণাত্মক দেখাত, আর লাভ-ক্ষতিতে একবারের বিক্রির লাভটা রোজকার খরচ কমানোর মতো পড়ত।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * লাভ ৪৩৫০ "সম্পদ বিক্রির লাভ" (আয়), লোকসান ৫৩২০ "সম্পদ বিক্রির লোকসান" (খরচ) — অবচয়ের খাত আর ছোঁয়া হয় না।
 * ৳১,০০,০০০-এর দুইটা গাড়ি, অবচয় চলেনি (বইমূল্য পুরো দাম): একটা ১,২০,০০০-এ, আরেকটা ৭০,০০০-এ।
 */
final class TheAssetSaleGainHidInTheDepreciationTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private FinancialYear $year;

    private Account $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        $this->year = FinancialYear::query()->where('is_current', true)->firstOrFail();
        $this->till = app(CashTillService::class)->ensurePrimaryTill()->account;
        $this->putMoneyIn($this->till, '500000', $this->year->starts_on->toDateString());
    }

    public function test_a_gain_lands_in_its_own_income_head_and_a_loss_in_its_own_expense_head(): void
    {
        $gainHead = StandardChart::find(StandardChart::ASSET_DISPOSAL_GAIN);
        $lossHead = StandardChart::find(StandardChart::ASSET_DISPOSAL_LOSS);
        $depreciation = StandardChart::find(StandardChart::DEPRECIATION_EXPENSE);

        $this->assertNotNull($gainHead, '⛔ ছকে "সম্পদ বিক্রির লাভ" নেই।');
        $this->assertNotNull($lossHead, '⛔ ছকে "সম্পদ বিক্রির লোকসান" নেই।');
        $this->assertSame(Account::INCOME, $gainHead->type);
        $this->assertSame(Account::EXPENSE, $lossHead->type);

        $won = $this->sell($this->van('Van A'), '120000');
        $lost = $this->sell($this->van('Van B'), '70000');

        $this->assertSame('20000.0000', $this->on($won, $gainHead->id, 'credit'), '⛔ ২০,০০০ লাভ নিজের আয়ের খাতে বসেনি।');
        $this->assertSame('30000.0000', $this->on($lost, $lossHead->id, 'debit'), '⛔ ৩০,০০০ লোকসান নিজের খরচের খাতে বসেনি।');

        foreach ([$won, $lost] as $asset) {
            $this->assertSame('0.0000', $this->on($asset, $depreciation->id, 'credit'), '⛔ বিক্রির তফাত এখনো অবচয়ের খাতে।');
            $this->assertSame('0.0000', $this->on($asset, $depreciation->id, 'debit'), '⛔ বিক্রির তফাত এখনো অবচয়ের খাতে।');
        }
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function on(FixedAsset $asset, int $accountId, string $side): string
    {
        return bcadd((string) LedgerEntry::query()
            ->where('source_type', FixedAsset::disposalSourceType())
            ->where('source_id', $asset->id)
            ->where('account_id', $accountId)
            ->sum($side), '0', 4);
    }

    private function sell(FixedAsset $asset, string $price): FixedAsset
    {
        return app(FixedAssetService::class)->dispose(
            asset: $asset,
            amount: $price,
            intoAccountId: $this->till->id,
            date: $this->year->starts_on->copy()->addMonths(2)->toDateString(),
        );
    }

    /** [[TheClosingEntryWasCountedFourDifferentWaysTest]]-এর একই গাড়ি — নগদে কেনা, অবচয় চলেনি। */
    private function van(string $name): FixedAsset
    {
        return app(FixedAssetService::class)->register([
            'name' => $name,
            'acquired_on' => $this->year->starts_on->copy()->addMonth()->toDateString(),
            'cost' => '100000',
            'salvage' => '0',
            'life_months' => 60,
            'asset_account_id' => Account::query()->postable()->where('code', '1202')->value('id'),
            'accumulated_account_id' => StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION)?->id,
            'expense_account_id' => StandardChart::find(StandardChart::DEPRECIATION_EXPENSE)?->id,
            'funded_by' => FixedAssetService::FUNDED_MONEY,
            'funding_account_id' => $this->till->id,
        ]);
    }
}
