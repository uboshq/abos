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
 * ⛔ লেনদেনসহ খাতের Dr/Cr প্রকৃতি বদলায় না — Accounts লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⓘ জের প্রকৃতি ধরে চিহ্ন পায়: ব্যাংকের খাত Cr করে দিলে তার জের ঋণাত্মক দেখাত, আর ব্যাংক-মিলকরণের "আমাদের খাতা" উল্টো
 * চিহ্নে আর কখনো মিলত না। লেনদেনহীন খাতে আগের মতোই বদলানো যায়।
 */
final class AnAccountsNatureStaysOnceItHasEntriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bank_with_entries_keeps_its_debit_nature(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $accounts = app(AccountService::class);
        $bank = $accounts->create(['code' => '1102-NAT', 'name_en' => 'Nature Bank', 'name_bn' => 'প্রকৃতির ব্যাংক', 'parent_id' => StandardChart::find(StandardChart::BANK)->id]);

        // ⓘ লেনদেন নেই — বদলানো যায়, ফেরানোও যায়
        $accounts->update($bank->fresh(), ['nature' => Account::CREDIT]);
        $accounts->update($bank->fresh(), ['nature' => Account::DEBIT]);

        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: [
            ['account_id' => $bank->id, 'debit' => '1000'],
            ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'credit' => '1000'],
        ]);

        try {
            $accounts->update($bank->fresh(), ['nature' => Account::CREDIT]);
            $this->fail('⛔ লেনদেনসহ ব্যাংকের প্রকৃতি Cr হল — জের ঋণাত্মক দেখাবে');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::validation.has_entries_cannot_renature'), $e->errors()['nature'][0] ?? null);
        }

        $this->assertSame(Account::DEBIT, $bank->fresh()->nature);
        $this->assertSame(0, bccomp($bank->fresh()->balanceOn(), '1000', 4));
    }
}
