<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * খোলা জের মালিকের মূলধনে — মালিক, ৫ অক্টোবর ২০২৬: *"Customer balance & Opening stock eguli ki Maliker capital e zabena?"*
 *
 * দাবি — একই কোম্পানি, সুইচ চালু → বন্ধ → চালু:
 *  · চালু (ডিফল্ট): গ্রাহকের খোলা বাকির বিপরীত দিক মালিকের মূলধনে (৩১০০), সংরক্ষিত মুনাফা (৩৩০০) ছোঁয় না।
 *  · বন্ধ: আগের মতো সংরক্ষিত মুনাফায়।
 *  · আবার চালু: আবার মূলধনে।
 */
final class TheOpeningBalanceIsTheOwnersCapitalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_the_switch_sends_the_other_side_to_capital_or_to_retained_earnings(): void
    {
        $capital = $this->balance(StandardChart::OWNER_CAPITAL);
        $retained = $this->balance(StandardChart::RETAINED_EARNINGS);

        app(OpeningBalanceService::class)->forReceivable('customer', 901, 'OB-1', '1000', now()->toDateString());
        $this->assertSame('-1000.0000', bcsub($this->balance(StandardChart::OWNER_CAPITAL), $capital, 4), '⛔ চালু, তবু খোলা বাকি মূলধনে বসেনি।');
        $this->assertSame('0.0000', bcsub($this->balance(StandardChart::RETAINED_EARNINGS), $retained, 4), '⛔ চালু, তবু সংরক্ষিত মুনাফা ছোঁয়া হয়েছে।');

        app(SettingsService::class)->set('accounts.opening_to_capital', false);
        app(OpeningBalanceService::class)->forReceivable('customer', 902, 'OB-2', '400', now()->toDateString());
        $this->assertSame('-400.0000', bcsub($this->balance(StandardChart::RETAINED_EARNINGS), $retained, 4), 'বন্ধ, তবু সংরক্ষিত মুনাফায় যায়নি।');

        app(SettingsService::class)->set('accounts.opening_to_capital', true);
        app(OpeningBalanceService::class)->forReceivable('customer', 903, 'OB-3', '250', now()->toDateString());
        $this->assertSame('-1250.0000', bcsub($this->balance(StandardChart::OWNER_CAPITAL), $capital, 4), '⛔ আবার চালু করে মূলধনে ফেরেনি।');
    }

    private function balance(string $code): string
    {
        $id = (int) StandardChart::find($code)?->id;

        return bcadd((string) DB::table('ledger_entries')->where('account_id', $id)->sum(DB::raw('debit - credit')), '0', 4);
    }
}
