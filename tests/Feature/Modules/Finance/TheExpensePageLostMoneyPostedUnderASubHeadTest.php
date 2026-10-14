<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Services\HeadTotals;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * খরচের পাতা নাতির ঘরে বসানো টাকা হারিয়ে ফেলত — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (⚠️২)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * [[HeadTotals::forParent()]] ৫২০০-এর কেবল সরাসরি সন্তানদের **নিজের** সারি গুনত। ক্রেতা "যানবাহন" নামে একটা
 * দল বানিয়ে তার নিচে "ট্রাক ভাড়া" রাখলে টাকা বসত নাতির ঘরে; দলটার নিজের সারি নেই, তাই দুই সময়েই শূন্য,
 * আর পাতা থেকে বাদ। অথচ ড্যাশবোর্ডের তালিকা ([[HeadTotals::topUnder()]]) একই টাকা দেখাত।
 *
 * ── ⭐ দাবি ───────────────────────────────────────────────────────────
 * নাতির টাকা তার খাতের সারিতে ওঠে, আর নাতি নিজে আলাদা সারি হয় না — ড্যাশবোর্ডের নিয়মেই।
 */
final class TheExpensePageLostMoneyPostedUnderASubHeadTest extends TestCase
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

    public function test_money_on_a_grandchild_shows_on_its_head(): void
    {
        $parent = StandardChart::find(StandardChart::OPERATING_EXPENSES);

        $vehicle = $this->account('5291', 'Vehicle (test group)', $parent, isGroup: true);
        $truck = $this->account('529101', 'Truck hire (test leaf)', $vehicle, isGroup: false);

        $cash = app(CashTillService::class)->ensurePrimaryTill()->account;

        app(PostingEngine::class)->post(sourceType: 'test:nested-head', sourceId: 1, trxDate: now(), lines: [
            ['account_id' => $truck->id, 'debit' => '10000'],
            ['account_id' => $cash->id, 'credit' => '10000'],
        ]);

        $rows = app(HeadTotals::class)->forParent(
            StandardChart::OPERATING_EXPENSES,
            now()->startOfMonth()->toDateString(),
            now()->toDateString(),
            true,
        );

        $byCode = collect($rows)->keyBy(fn (array $row) => $row['account']->code);

        $this->assertTrue($byCode->has('5291'), '⛔ দলের খাতটা খরচের পাতায় নেই — নাতির ১০,০০০ টাকা হারিয়ে গেছে।');
        $this->assertSame(0, bccomp($byCode['5291']['now'], '10000', 4),
            '⛔ দলের খাতে নাতির টাকা ওঠেনি: দেখাচ্ছে '.$byCode['5291']['now'].', হওয়ার কথা ১০,০০০।');
        $this->assertFalse($byCode->has('529101'), '⛔ নাতিটা নিজের নামে আলাদা সারি হয়ে গেছে — ড্যাশবোর্ডের নিয়মের উল্টো।');
    }

    private function account(string $code, string $name, Account $parent, bool $isGroup): Account
    {
        return Account::query()->create([
            'code' => $code,
            'name_en' => $name,
            'name_bn' => $name,
            'type' => Account::EXPENSE,
            'parent_id' => $parent->id,
            'is_group' => $isGroup,
            'nature' => Account::defaultNatureFor(Account::EXPENSE),
        ]);
    }
}
