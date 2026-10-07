<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\YearEndService;
use Carbon\Carbon;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ শাখাহীন আয় বছর বন্ধে বন্ধকারীর শাখায় শূন্য হত — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️৬)।
 *
 * ⓘ [[YearEndService::closingLines()]] প্রতিটা খাত·শাখা নিজের শাখাতেই শূন্য করে, শাখাহীনটা `branch_id => null` বলে। কিন্তু
 * [[PostingEngine::post()]] `??` দিয়ে স্পষ্ট null-কেও "বলেনি" ধরত আর কাগজের শাখা বসাত — শাখাহীন আয়-খাত খোলা থেকে যেত, আর
 * বন্ধকারীর শাখায় সেই খাতের উল্টো জের আর সঞ্চিত মুনাফা জমত।
 */
final class AnUnbranchedIncomeClosesWhereItSitsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
    }

    public function test_an_income_with_no_branch_is_closed_with_no_branch(): void
    {
        $year = FinancialYear::query()->where('is_current', true)->firstOrFail();
        $income = Account::query()->where('type', Account::INCOME)->where('is_group', false)->orderBy('code')->firstOrFail();

        // ⓘ শাখাহীন আয় — যেমন পুরনো ইমপোর্ট বা কোম্পানি-জোড়া কাগজ
        CompanyContext::set($this->company->id, null);
        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999),
            trxDate: Carbon::parse($year->starts_on)->addDays(3)->toDateString(), lines: [
                ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'debit' => '4000'],
                ['account_id' => $income->id, 'credit' => '4000'],
            ]);
        $this->assertSame(1, LedgerEntry::query()->where('account_id', $income->id)->whereNull('branch_id')->count(), 'দৃশ্যটাই বানানো যায়নি — আয়টা শাখাহীন বসেনি');

        $ntk = $this->branch('NTK')->id;
        $this->choose($ntk);

        app(YearEndService::class)->close($year);

        $closing = LedgerEntry::query()->where('source_type', YearEndService::CLOSE_SOURCE)->where('source_id', $year->id);

        $this->assertSame(0, (clone $closing)->where('account_id', $income->id)->where('branch_id', $ntk)->count(),
            '⛔ শাখাহীন আয় বন্ধকারীর শাখায় (NTK) শূন্য হল');
        $this->assertSame('0.00', $this->net($income->id, null), '⛔ বছর বন্ধের পরেও শাখাহীন আয়-খাত খোলা');
        $this->assertSame('0.00', $this->net($income->id, $ntk), '⛔ বন্ধকারীর শাখায় আয়-খাতের উল্টো জের জমল');
        $this->assertGreaterThan(0, (clone $closing)->whereNull('branch_id')
            ->where('account_id', StandardChart::find(StandardChart::RETAINED_EARNINGS)->id)->where('credit', '>', 0)->count(),
            '⛔ শাখাহীন আয়ের লাভ শাখাহীন সঞ্চিত মুনাফায় ওঠেনি');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function net(int $accountId, ?int $branchId): string
    {
        return bcadd((string) LedgerEntry::query()->where('account_id', $accountId)
            ->when($branchId === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n')->value('n'), '0', 2);
    }

    private function choose(int|string $branch): void
    {
        $this->actingAs($this->owner->fresh())->post(route('branch.switch'), ['branch_id' => (string) $branch])->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
