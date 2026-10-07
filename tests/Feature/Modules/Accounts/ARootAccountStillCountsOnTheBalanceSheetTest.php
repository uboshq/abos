<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\BalanceSheetService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ গোড়ায় বসা পোস্টযোগ্য খাত স্থিতিপত্রে উঠত না — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️১০)।
 *
 * ⓘ [[BalanceSheetService::side()]] গাছ শুরু করত গোড়ার সন্তান থেকে; খাতের পর্দা মা-ছাড়া পোস্টযোগ্য খাত বানাতে দেয়, আর তার জের
 * কোথাও যোগ হত না — রেওয়ামিলে খাতটা আছে, স্থিতিপত্র "মেলে না"।
 */
final class ARootAccountStillCountsOnTheBalanceSheetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_postable_account_at_the_root_is_its_own_head(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, null);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $before = app(BalanceSheetService::class)->build();

        // ⓘ মা-ছাড়া সম্পদ খাত — যেমন কেউ গোড়ায় "জামানত" খুলে ফেললেন
        $loose = Account::query()->create([
            'company_id' => $company->id, 'code' => '1999', 'name_en' => 'Loose Deposit', 'name_bn' => 'আলগা জামানত',
            'parent_id' => null, 'type' => Account::ASSET, 'nature' => Account::DEBIT, 'is_group' => false,
            'is_active' => true, 'status' => DocumentStatus::CONFIRMED,
        ]);
        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: [
            ['account_id' => $loose->id, 'debit' => '7000'],
            ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'credit' => '7000'],
        ]);

        $after = app(BalanceSheetService::class)->build();

        $this->assertSame($before['agrees'], $after['agrees'], '⛔ গোড়ার খাতের জের বাদ পড়ে স্থিতিপত্রের মিল বদলাল');
        $this->assertSame(0, bccomp(bcadd($before['totals']['assets'], '7000', 4), $after['totals']['assets'], 4), '⛔ সম্পদের মোটে গোড়ার খাতের ৭,০০০ নেই');

        $head = collect($after['assets'])->first(fn (array $h) => (int) $h['head']->id === (int) $loose->id);
        $this->assertNotNull($head, '⛔ গোড়ার খাতটা স্থিতিপত্রে কোথাও নেই');
        $this->assertSame(0, bccomp('7000', (string) $head['total'], 4));
    }
}
