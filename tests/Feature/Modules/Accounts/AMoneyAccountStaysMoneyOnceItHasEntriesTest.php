<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ লেনদেনসহ টাকার খাত সাধারণ খাতের নিচে সরে টাকা-নয় হয় না — Accounts লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⓘ টাকার ধরন আসে বাবা থেকে; সরালে "জের শূন্যের নিচে নয়" আর "টাকার খাতে পক্ষ নয়" পাহারা চুপচাপ উঠে যেত। ব্যাংক ↔ মোবাইল-ব্যাংকিং
 * সরানো আগের মতোই চলে।
 */
final class AMoneyAccountStaysMoneyOnceItHasEntriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bank_with_entries_cannot_leave_the_money_tree_but_can_move_to_mobile_money(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $accounts = app(AccountService::class);
        $bank = $accounts->create(['code' => '1102-MV', 'name_en' => 'Moving Bank', 'name_bn' => 'সরানো ব্যাংক', 'parent_id' => StandardChart::find(StandardChart::BANK)->id]);
        $this->assertSame(Account::BANK, $bank->money_kind);

        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: [
            ['account_id' => $bank->id, 'debit' => '1000'],
            ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'credit' => '1000'],
        ]);

        try {
            $accounts->update($bank->fresh(), ['parent_id' => StandardChart::find('1100')->id]);
            $this->fail('⛔ লেনদেনসহ ব্যাংক সাধারণ সম্পদের নিচে সরে টাকা-নয় হল');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::validation.money_kind_cannot_flip'), $e->errors()['parent_id'][0] ?? null);
        }

        $this->assertSame(Account::BANK, $bank->fresh()->money_kind);

        // ⓘ টাকার গাছের ভিতরে সরানো আগের মতোই
        $accounts->update($bank->fresh(), ['parent_id' => StandardChart::find(StandardChart::MOBILE_MONEY)->id]);
        $this->assertSame(Account::MFS, $bank->fresh()->money_kind);
    }
}
