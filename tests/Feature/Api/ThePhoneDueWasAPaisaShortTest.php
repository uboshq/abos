<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Sync\CustomerDueSync;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ ফোনের শাখার বকেয়া পয়সা ছেঁটে ফেলত (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[CustomerDueSync]])।
 *
 * ⚠️ খাতা চার দশমিকে রাখে; ফোনে `bcadd(…, 2)` ছাঁটত — ১০.০০৫ ফোনে ১০.০০, ওয়েবে ১০.০১। ⭐ এখন গোল করা, ওয়েবের মতো।
 */
final class ThePhoneDueWasAPaisaShortTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_branch_due_on_the_phone_is_rounded_like_the_web(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $customer = Customer::query()->create([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'code' => 'PAISA-X',
            'name_en' => 'Half Paisa Shop', 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: 98001, trxDate: now()->toDateString(), lines: [
            ['account_id' => (int) StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => '10.0050', 'party_type' => Customer::drillSourceType(), 'party_id' => $customer->id],
            ['account_id' => (int) Account::query()->postable()->active()->where('type', Account::INCOME)->value('id'), 'credit' => '10.0050'],
        ], branchId: $company->defaultBranch()?->id);

        $batch = app(CustomerDueSync::class)->pull($owner, null, 1000);
        $mine = collect($batch->records)->first(fn ($r) => $r->entityId === (string) $customer->public_id);

        $this->assertNotNull($mine, 'দৃশ্যটাই বানানো যায়নি — গ্রাহক ফোনের টানায় নেই');
        $this->assertSame('10.01', $mine->payload['outstandingInView'], '⛔ ফোনের বকেয়া পয়সা ছেঁটে ফেলল');
    }
}
