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
 * ⛔ দল সরালে ভিতরের লেনদেনসহ খাতের ধরন বদলায় না — Accounts লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⓘ দলের নিজের সারি থাকে না, তাই "লেনদেন আছে কি না" পাহারা পার হত, আর [[AccountService::update()]]-এর ধরনের ঢল নিচের
 * খাতগুলোকেও নতুন ধরনে নিত: "অফিস খরচ" দল চলতি সম্পদের নিচে সরালে গোটা বছরের ভাড়া লাভ-ক্ষতি ছেড়ে স্থিতিপত্রে উঠত।
 */
final class MovingAGroupDoesNotRetypeItsEntriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_group_whose_child_has_entries_cannot_move_under_another_type(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $accounts = app(AccountService::class);
        $expenseParent = (int) Account::query()->where('code', '5202')->value('parent_id');

        $group = $accounts->create(['code' => '5290', 'name_en' => 'Office Expenses', 'name_bn' => 'অফিস খরচ', 'parent_id' => $expenseParent, 'is_group' => true]);
        $rent = $accounts->create(['code' => '5291', 'name_en' => 'Office Rent', 'name_bn' => 'অফিস ভাড়া', 'parent_id' => $group->id]);
        $this->assertSame(Account::EXPENSE, $rent->type, 'দৃশ্যটাই বানানো যায়নি');

        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: [
            ['account_id' => $rent->id, 'debit' => '12000'],
            ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'credit' => '12000'],
        ]);

        try {
            $accounts->update($group->fresh(), ['parent_id' => StandardChart::find('1100')->id]);
            $this->fail('⛔ লেনদেনসহ খাতের দল সম্পদের নিচে সরল — বছরের ভাড়া লাভ-ক্ষতি ছেড়ে স্থিতিপত্রে');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::validation.has_entries_cannot_retype'), $e->errors()['type'][0] ?? null);
        }

        $this->assertSame(Account::EXPENSE, $rent->fresh()->type, '⛔ ভাড়ার খাত সম্পদ হয়ে গেছে');
        $this->assertSame(Account::EXPENSE, $group->fresh()->type);

        // ⓘ লেনদেনহীন দল আগের মতোই সরে
        $empty = $accounts->create(['code' => '5295', 'name_en' => 'Spare', 'name_bn' => 'খালি', 'parent_id' => $expenseParent, 'is_group' => true]);
        $accounts->create(['code' => '5296', 'name_en' => 'Spare child', 'name_bn' => 'খালি সন্তান', 'parent_id' => $empty->id]);
        $accounts->update($empty->fresh(), ['parent_id' => StandardChart::find('1100')->id]);
        $this->assertSame(Account::ASSET, $empty->fresh()->type);
    }
}
