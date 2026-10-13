<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ "আদায়"-এর তালিকায় কেবল বাইরে থেকে আসা টাকা — Accounts লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⓘ আগে টাকার খাতের প্রতিটা ডেবিট আদায় গোনা হত: টিল থেকে ব্যাংকে জমা, বাতিল হওয়া রসিদ — দুটোই মাসিক নগদের পাতা বাদ দেয়,
 * তাই দুই পাতার "আসা টাকা" এক মাসেই আলাদা অঙ্ক বলত।
 */
final class TheInflowListIsMoneyFromOutsideTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfers_and_reversed_receipts_are_not_collections(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $bank = Account::query()->create(['company_id' => $company->id, 'code' => '1102-INF', 'name_en' => 'Inflow Bank', 'name_bn' => 'আদায়ের ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT, 'money_kind' => Account::BANK]);
        $cash = app(CashTillService::class)->ensurePrimaryTill()->account_id;
        $receivable = StandardChart::find(StandardChart::RECEIVABLE);
        $receivable = $receivable->is_group ? Account::query()->postable()->whereKey($receivable->selfAndDescendants()->pluck('id'))->orderBy('code')->firstOrFail() : $receivable;
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $engine = app(PostingEngine::class);
        $today = now()->toDateString();

        $kept = random_int(1, 4_000_000);
        $engine->post(sourceType: 'receipt_voucher', sourceId: $kept, trxDate: $today, lines: [
            ['account_id' => $bank->id, 'debit' => '1000'],
            ['account_id' => $receivable->id, 'credit' => '1000', 'party_type' => 'customer', 'party_id' => $customer->id],
        ]);

        $transfer = $kept + 1;
        $engine->post(sourceType: 'contra_voucher', sourceId: $transfer, trxDate: $today, lines: [
            ['account_id' => $bank->id, 'debit' => '500'],
            ['account_id' => $cash, 'credit' => '500'],
        ]);

        $reversed = $kept + 2;
        $engine->post(sourceType: 'receipt_voucher', sourceId: $reversed, trxDate: $today, lines: [
            ['account_id' => $bank->id, 'debit' => '700'],
            ['account_id' => $receivable->id, 'credit' => '700', 'party_type' => 'customer', 'party_id' => $customer->id],
        ]);
        $engine->reverse(sourceType: 'receipt_voucher', sourceId: $reversed, reversalDate: $today, reason: 'ভুল রসিদ');

        $rows = collect(app(ReportEngine::class)->run('accounts.inflow', ['from' => $today, 'to' => $today], 1, 500)->rows)
            ->filter(fn ($r) => str_contains((string) ($r['account_name'] ?? ''), '1102-INF'));

        $this->assertSame([$kept], $rows->pluck('source_id')->map(fn ($i) => (int) $i)->values()->all(),
            '⛔ আদায়ের তালিকায় নিজের খাতের স্থানান্তর বা বাতিল রসিদ এসেছে');
    }
}
