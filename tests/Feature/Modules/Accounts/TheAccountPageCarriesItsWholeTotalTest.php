<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ খাতার পাতায় সব পাতার ডেবিট, ক্রেডিট আর শেষ জের — মালিক, ১৭ অক্টোবর ২০২৬: "সব পর্দায় summary/grand total"।
 *
 * ⓘ আগে [[ChartOfAccountsController::show()]] কেবল সারির গোনা দিত; টেবিলের নিচে কোনো যোগফল ছিল না। ⛔ মোট পাতার নয়, পুরো খাতার —
 * ৫৩টা সারি দুই পাতায়, প্রথম পাতাতেও সর্বমোট ৫৩টারই।
 */
final class TheAccountPageCarriesItsWholeTotalTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_shows_the_whole_debit_credit_and_closing_balance(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, null);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $account = Account::query()->create([
            'company_id' => $company->id, 'code' => '1984', 'name_en' => 'Totalled Account', 'name_bn' => 'যোগের খাত', 'parent_id' => null,
            'type' => Account::ASSET, 'nature' => Account::DEBIT, 'is_group' => false, 'is_active' => true, 'status' => DocumentStatus::CONFIRMED,
        ]);
        $other = StandardChart::find(StandardChart::SALARY_PAYABLE)->id;

        // ⓘ ৫০টা ডেবিট ১০০ আর ৩টা ক্রেডিট ২৫০ — পাতায় ৫০টা, তাই প্রথম পাতায় সব ক্রেডিট নেই
        for ($i = 1; $i <= 53; $i++) {
            $amount = $i <= 50 ? '100' : '250';
            app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: 880000 + $i, trxDate: now()->subDays(60 - $i)->toDateString(), lines: $i <= 50
                ? [['account_id' => $account->id, 'debit' => $amount], ['account_id' => $other, 'credit' => $amount]]
                : [['account_id' => $other, 'debit' => $amount], ['account_id' => $account->id, 'credit' => $amount]]);
        }

        $first = $this->get(route('accounts.coa.show', $account))->assertOk();

        $this->assertSame(['debit' => '5000.00', 'credit' => '750.00'], array_map(
            fn (string $v) => bcadd($v, '0', 2), $first->viewData('grand')), '⛔ সর্বমোট প্রথম পাতার, পুরো খাতার নয়');
        $this->assertSame('4250.00', bcadd((string) $first->viewData('closing'), '0', 2), '⛔ শেষ জের ডেবিট − ক্রেডিট নয়');

        $html = $first->getContent();
        $this->assertStringContainsString('data-grand-total-row', $html, '⛔ টেবিলের নিচে সর্বমোটের সারি নেই');
        $this->assertStringContainsString('5,000.00', $html);
        $this->assertStringContainsString('(Dr) 4,250.00', $html, '⛔ শেষ জের পাতায় নেই');

        // ⓘ শেষ পাতার শেষ সারির চলমান জের = শেষ জের — দুটো একই কথা বলে
        $last = $this->get(route('accounts.coa.show', [$account, 'page' => 2]))->assertOk();
        $this->assertSame('4250.00', bcadd((string) $last->viewData('entries')->getCollection()->last()->running_balance, '0', 2));
        $this->assertSame('4250.00', bcadd((string) $last->viewData('closing'), '0', 2));
    }
}
