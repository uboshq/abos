<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\InterestAccrual;
use App\Modules\Finance\Services\BankFacilityService;
use App\Modules\Finance\Services\InterestAccrualService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ খাতায় বকেয়া থাকতেও ঋণ বন্ধ হত, আর বন্ধ ঋণ আবার বন্ধ হয়ে দিন বদলাত — লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬ ([[BankFacilityService::close()]])।
 *
 * ⭐ দাবি: বকেয়া থাকলে বন্ধ হয় না; বন্ধ সুবিধা আবার বন্ধ হয় না (দুই ট্যাব)।
 */
final class TheLoanWasClosedWhileItWasStillOwedTest extends TestCase
{
    use RefreshDatabase;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->signer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        app(StandardChart::class)->install();

        Carbon::setTestNow('2026-11-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_facility_is_not_closed_while_the_books_owe_it_or_closed_twice(): void
    {
        $service = app(BankFacilityService::class);
        $owed = $service->open([
            'kind' => BankFacility::LTR, 'bank' => 'Sonali Bank', 'sanctioned_on' => '2026-09-01',
            'limit_amount' => '500000', 'interest_rate' => '12', 'margin_percent' => '10',
            'liability_account_id' => $this->liability()->id,
        ]);
        $this->draw($owed, '200000', '2026-09-02');

        try {
            $service->close($owed->fresh(), 'শেষ');
            $this->fail('⛔ খাতায় ২ লাখ বকেয়া, অথচ ঋণ বন্ধ হল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('note', $e->errors());
        }
        $this->assertSame(DocumentStatus::CONFIRMED, $owed->fresh()->status);

        $clear = $service->open([
            'kind' => BankFacility::GUARANTEE, 'bank' => 'Sonali Bank', 'sanctioned_on' => '2026-09-01',
            'limit_amount' => '500000', 'margin_percent' => '10',
        ]);
        $service->close($clear->fresh(), 'মেয়াদ শেষ');
        $closedOn = $clear->fresh()->closed_on->toDateString();

        Carbon::setTestNow('2026-11-20 10:00:00');

        try {
            $service->close($clear->fresh(), 'আবার');
            $this->fail('⛔ বন্ধ সুবিধা আবার বন্ধ হল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('note', $e->errors());
        }
        $this->assertSame($closedOn, $clear->fresh()->closed_on->toDateString(), '⛔ দ্বিতীয় চাপে বন্ধের দিন বদলে গেল');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function liability(): Account
    {
        return Account::query()->where('code', '2211')->firstOrFail();
    }

    private function draw(BankFacility $facility, string $amount, string $on): void
    {
        $vouchers = app(VoucherService::class);
        $vouchers->post($vouchers->create([
            'type' => Voucher::JOURNAL, 'trx_date' => $on, 'narration' => 'drawn',
            'against_type' => BankFacility::drillSourceType(), 'against_id' => $facility->id,
        ], [
            ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => $this->liability()->id, 'debit' => '0', 'credit' => $amount],
        ]));
    }

    private function flow(): void
    {
        $flow = ApprovalFlow::query()->create(['module' => 'finance', 'action' => 'bank_facility', 'is_active' => true]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->signer->id,
        ]);
        $this->app->forgetInstance(ApprovalEngine::class);
    }
}
