<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Posting\PostingException;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\MoneyAccountRule;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ নিষ্ক্রিয় খাতে নতুন টাকা নয়, আর বাবা বদলে লেনদেনসহ খাতের ধরন বদলায় না — অডিট ম২০, ১০ অক্টোবর ২০২৬।
 *
 * ⓘ তিনটা ফাঁক:
 * - [[PostingEngine]] খাতটা আছে কি না, দল কি না দেখত — সক্রিয় কি না দেখত না;
 * - [[MoneyAccountRule]] একই — নিষ্ক্রিয় ব্যাংকে আদায় বা জমা বসত;
 * - [[AccountService::update()]] সরাসরি পাঠানো `type` আটকাত, অথচ ধরন আসে বাবার থেকে — লেনদেনসহ খরচের খাত সম্পদের নিচে
 *   সরালে আগের সব খরচ লাভ-ক্ষতি থেকে স্থিতিপত্রে চলে যেত।
 */
final class AnInactiveAccountTakesNoNewMoneyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_the_engine_refuses_an_inactive_account_but_the_year_close_still_empties_it(): void
    {
        $bank = $this->bank('1102-INA', false);
        $sourceId = random_int(1, 9_999_999);

        try {
            $this->postTo($bank, 'receipt_voucher', $sourceId);
            $this->fail('⛔ নিষ্ক্রিয় খাতে দাখিলা বসল');
        } catch (PostingException $e) {
            $this->assertStringContainsString('inactive', $e->getMessage());
        }

        $this->assertSame(0, LedgerEntry::query()->where('account_id', $bank->id)->count(), '⛔ নিষ্ক্রিয় খাতে সারি বসেছে');

        // ⓘ সক্রিয় করলে একই দাখিলা বসে — অর্থাৎ থামিয়েছিল কেবল নিষ্ক্রিয়তাই
        $bank->forceFill(['is_active' => true])->save();
        $this->postTo($bank, 'receipt_voucher', $sourceId);
        $this->assertSame(1, LedgerEntry::query()->where('account_id', $bank->id)->count());

        // ⓘ বছর বন্ধের সমাপনী নিষ্ক্রিয় খাতেও বসে — নাহলে জের থাকা খাত বন্ধ করলে বছরই বন্ধ হত না
        $bank->forceFill(['is_active' => false])->save();
        $this->postTo($bank, PostingEngine::YEAR_CLOSE, random_int(1, 9_999_999));
        $this->assertSame(2, LedgerEntry::query()->where('account_id', $bank->id)->count(), '⛔ সমাপনী নিষ্ক্রিয় খাতে বসতে পারেনি');
    }

    public function test_the_money_rule_refuses_an_inactive_bank(): void
    {
        $bank = $this->bank('1102-INB', false);

        try {
            app(MoneyAccountRule::class)->assert($bank->id);
            $this->fail('⛔ নিষ্ক্রিয় ব্যাংক টাকার খাত হিসেবে মানা হল');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::validation.inactive_account', ['name' => $bank->name()]), $e->errors()['account_id'][0] ?? null);
        }

        $bank->forceFill(['is_active' => true])->save();
        $this->assertSame($bank->id, app(MoneyAccountRule::class)->assert($bank->id)->id);
    }

    public function test_moving_an_account_with_entries_under_another_type_is_refused(): void
    {
        $expenseParent = Account::query()->where('code', '5202')->value('parent_id');
        $expense = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '5202-MOV', 'name_en' => 'Moving Expense', 'name_bn' => 'সরানো খরচ',
            'parent_id' => $expenseParent, 'type' => Account::EXPENSE, 'nature' => Account::DEBIT,
            'is_active' => true, 'status' => DocumentStatus::CONFIRMED,
        ]);
        $this->postTo($expense, 'journal_voucher', random_int(1, 9_999_999));

        try {
            app(AccountService::class)->update($expense, ['parent_id' => StandardChart::find(StandardChart::BANK)->id]);
            $this->fail('⛔ লেনদেনসহ খরচের খাত ব্যাংকের নিচে সরে সম্পদ হয়ে গেল');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::validation.has_entries_cannot_retype'), $e->errors()['type'][0] ?? null);
        }

        $this->assertSame(Account::EXPENSE, $expense->fresh()->type);
        $this->assertSame((int) $expenseParent, (int) $expense->fresh()->parent_id);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function bank(string $code, bool $active): Account
    {
        return Account::query()->create([
            'company_id' => $this->company->id, 'code' => $code, 'name_en' => 'Bank '.$code, 'name_bn' => 'ব্যাংক '.$code,
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK, 'is_active' => $active, 'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    private function postTo(Account $account, string $sourceType, int $sourceId): void
    {
        app(PostingEngine::class)->post(sourceType: $sourceType, sourceId: $sourceId, trxDate: now()->toDateString(), lines: [
            ['account_id' => $account->id, 'debit' => '1000'],
            ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'credit' => '1000'],
        ]);
    }
}
