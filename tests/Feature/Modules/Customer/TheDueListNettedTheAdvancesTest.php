<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ বকেয়ার তালিকার মোটে অগ্রিম কাটাকাটি হত (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[PartyReports::dueList()]])।
 *
 * ⚠️ ক-এর বকেয়া ১,০০০, খ-এর ৭০০ আগাম জমা। ঘরের "গ্রাহকের কাছে পাওনা" বলে ১,০০০ আর তাতে চাপলে এই তালিকা খোলে — কিন্তু তালিকা
 * খ-কে −৭০০ বকেয়া ধরে মোট বলত ৩০০। ⭐ এখন বকেয়া ১,০০০ আর অগ্রিম ৭০০ আলাদা ঘরে।
 */
final class TheDueListNettedTheAdvancesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dues_and_advances_stand_in_their_own_columns(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        DB::table('ledger_entries')->where('party_type', Customer::drillSourceType())->delete();

        $receivable = (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
        $income = (int) Account::query()->postable()->active()->where('type', Account::INCOME)->value('id');
        $owes = Customer::query()->create(['company_id' => CompanyContext::id(), 'code' => 'DUE-A', 'name_en' => 'Owing Shop', 'is_active' => true]);
        $ahead = Customer::query()->create(['company_id' => CompanyContext::id(), 'code' => 'DUE-B', 'name_en' => 'Paid Ahead Shop', 'is_active' => true]);

        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: 97001, trxDate: now()->toDateString(), lines: [
            ['account_id' => $receivable, 'debit' => '1000', 'party_type' => Customer::drillSourceType(), 'party_id' => $owes->id],
            ['account_id' => $income, 'credit' => '1000'],
        ]);
        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: 97002, trxDate: now()->toDateString(), lines: [
            ['account_id' => $income, 'debit' => '700'],
            ['account_id' => $receivable, 'credit' => '700', 'party_type' => Customer::drillSourceType(), 'party_id' => $ahead->id],
        ]);

        $result = app(ReportEngine::class)->run('customer.due_list', ['to' => now()->toDateString()], 1, 100);

        $this->assertSame(0, bccomp('1000', (string) $result->totals['outstanding'], 2), '⛔ বকেয়ার মোটে অগ্রিম কাটা গেল: '.$result->totals['outstanding']);
        $this->assertSame(0, bccomp('700', (string) ($result->totals['advance'] ?? '-1'), 2), '⛔ অগ্রিম আলাদা ঘরে নেই');
    }
}
