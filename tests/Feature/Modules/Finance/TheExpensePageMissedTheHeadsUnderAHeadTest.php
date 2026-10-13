<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\YearEndService;
use App\Modules\Finance\Services\HeadTotals;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ খরচের (আর আয়ের) পাতা খাতের নিচের খাত গুনত না, আর বছর বন্ধের দাখিলাও গুনত — লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬
 * ([[HeadTotals::forParent()]])।
 *
 * ⭐ দাবি:
 *   · দল-খাতের নিচের খাতে খরচ হলে দলের সারিতে সেই অঙ্ক — শূন্য দেখিয়ে লুকায় না
 *   · বছর বন্ধের দাখিলা খরচ গোনায় নয়
 */
final class TheExpensePageMissedTheHeadsUnderAHeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_head_with_heads_under_it_shows_their_money_and_the_year_close_is_left_out(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $parent = Account::query()->where('code', StandardChart::OPERATING_EXPENSES)->firstOrFail();
        $group = Account::query()->create([
            'parent_id' => $parent->id, 'code' => '529901', 'name_en' => 'Vehicle hire', 'type' => $parent->type,
            'nature' => $parent->nature, 'is_group' => true,
        ]);
        $truck = Account::query()->create([
            'parent_id' => $group->id, 'code' => '52990101', 'name_en' => 'Truck hire', 'type' => $parent->type,
            'nature' => $parent->nature, 'is_group' => false,
        ]);
        $cash = Account::query()->money()->postable()->active()->where('money_kind', Account::CASH)->orderBy('code')->firstOrFail();
        $on = now()->subDays(2)->toDateString();

        app(PostingEngine::class)->post(sourceType: 'test:truck', sourceId: 1, trxDate: $on, lines: [
            ['account_id' => $truck->id, 'debit' => '7000'], ['account_id' => $cash->id, 'credit' => '7000'],
        ]);
        // ⓘ বছর বন্ধের দাখিলার মতো একটা উল্টো সারি — গোনায় আসার কথা নয়
        app(PostingEngine::class)->post(sourceType: YearEndService::CLOSE_SOURCE, sourceId: 99, trxDate: $on, lines: [
            ['account_id' => StandardChart::find(StandardChart::RETAINED_EARNINGS)->id, 'debit' => '7000'],
            ['account_id' => $truck->id, 'credit' => '7000'],
        ]);

        $rows = collect(app(HeadTotals::class)->forParent(StandardChart::OPERATING_EXPENSES, now()->subDays(5)->toDateString(),
            now()->toDateString(), debitPositive: true));
        $row = $rows->first(fn (array $r) => (int) $r['account']->id === (int) $group->id);

        $this->assertNotNull($row, '⛔ নিচের খাতে খরচ, অথচ দল-খাতটাই খরচের পাতায় নেই');
        $this->assertSame(0, bccomp((string) $row['now'], '7000', 4), '⛔ দল-খাতের খরচ ভুল — বছর বন্ধের দাখিলা গোনা হল?');
    }
}
