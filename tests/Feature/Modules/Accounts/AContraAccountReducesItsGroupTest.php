<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\GroupLedgerService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ বিপরীত খাত তার দল থেকে বাদ যায়, যোগ নয় — Accounts লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⓘ ১২৯০ সঞ্চিত অবচয় সম্পদের দলে, তবু Cr প্রকৃতির। [[Account::balanceOn()]] দলের জের গুনত প্রতিটা পাতার নিজের প্রকৃতিতে,
 * তাই অবচয় ধনাত্মক হয়ে **যোগ** হত — ১,০০০-এর গাড়ি আর ৩০০ অবচয়ে স্থায়ী সম্পদ ১,৩০০, অথচ ৭০০। দলের রিপোর্টেও
 * ([[GroupLedgerService]]) একই: অবচয়ে সম্পদ বাড়ত।
 */
final class AContraAccountReducesItsGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_accumulated_depreciation_takes_away_from_fixed_assets_everywhere(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $fixed = StandardChart::find(StandardChart::FIXED_ASSETS);
        $accumulated = StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION);
        $this->assertSame(Account::CREDIT, $accumulated->nature, 'দৃশ্যটাই বানানো যায়নি — অবচয় Cr প্রকৃতির নয়');

        $today = now()->toDateString();
        $groupBefore = $fixed->balanceOn();
        $assetsBefore = app(GroupLedgerService::class)->build($owner, $today, $today)['total'][Account::ASSET];

        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: $today, lines: [
            ['account_id' => Account::query()->where('code', '1202')->value('id'), 'debit' => '1000'],
            ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'credit' => '1000'],
        ]);
        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: $today, lines: [
            ['account_id' => StandardChart::find(StandardChart::DEPRECIATION_EXPENSE)->id, 'debit' => '300'],
            ['account_id' => $accumulated->id, 'credit' => '300'],
        ]);

        // ⓘ আগের পড়া অনুরোধের ভিতরে তোলা থাকে ([[LedgerBalances]]) — নতুন করে পড়তে ভুলে যাওয়া
        app()->forgetInstance(\App\Core\Services\LedgerBalances::class);
        $this->app->forgetScopedInstances();

        $this->assertSame(0, bccomp(bcsub(StandardChart::find(StandardChart::FIXED_ASSETS)->balanceOn(), $groupBefore, 4), '700', 4),
            '⛔ স্থায়ী সম্পদের দলে অবচয় যোগ হল, বাদ নয়');

        $assetsAfter = app(GroupLedgerService::class)->build($owner, $today, $today)['total'][Account::ASSET];
        $this->assertSame(0, bccomp(bcsub($assetsAfter, $assetsBefore, 4), '700', 4), '⛔ দলের রিপোর্টে অবচয় সম্পদ বাড়াল');
    }
}
