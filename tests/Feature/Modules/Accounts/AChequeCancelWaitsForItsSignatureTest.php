<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Events\ChequeBounced;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Services\AccountsSignature;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * ⛔ চেক বাতিল নিজের পথে — সইয়ের অপেক্ষায় থাকলে "বাতিল" নয়, আর ছেঁড়া চেকে "চেক ফেরত"-এর ঘটনা নয়
 * (Accounts লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬)।
 *
 * ⓘ আগে [[ChequeService::cancel()]] ফেরত করে তারপর "বাতিল" বসাত। সইয়ের ছক ফেরত আটকালে কিছুই উল্টাত না, অথচ চেক
 * "বাতিল" দেখাত — দেওয়া চেকের দায় (২১১৫) রয়ে যেত, সরবরাহকারী "পরিশোধিত"; শেষ সইয়ে ফেরত আবার চলতে গিয়ে "বাতিল" দেখে
 * থামত। আর ফেরতের ঘটনা ছুটত, তাই কাউন্টারে ছেঁড়া চেকেও গ্রাহক "চেক ফেরত"-এ আটকাতেন।
 */
final class AChequeCancelWaitsForItsSignatureTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
    }

    public function test_a_held_cancel_stays_open_and_the_last_signature_cancels_and_reverses_it(): void
    {
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => AccountsSignature::MODULE,
            'action' => AccountsSignature::CHEQUE_BOUNCE, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner->id]);

        $cheque = app(ChequeService::class)->create([
            'direction' => Cheque::ISSUED, 'cheque_no' => 'CXL'.random_int(100000, 999999), 'bank_name' => 'Sonali Bank',
            'cheque_date' => now()->toDateString(), 'amount' => '5000',
            'party_type' => 'supplier', 'party_id' => Supplier::query()->firstOrFail()->id,
        ]);
        $this->assertSame(0, bccomp($this->issuedLiability(), '5000', 4), 'দৃশ্যটাই বানানো যায়নি — দেওয়া চেকের দায় বসেনি');

        $held = app(ChequeService::class)->cancel($cheque, 'ছিঁড়ে ফেলা হয়েছে');

        $this->assertNotSame(Cheque::CANCELLED, $held->status, '⛔ সইয়ের অপেক্ষায় থেকেও চেক "বাতিল" দেখাচ্ছে — অথচ কিছুই উল্টায়নি');
        $this->assertSame(0, bccomp($this->issuedLiability(), '5000', 4), '⛔ সইয়ের আগেই দায় সরল');

        $approval = app(ApprovalEngine::class)->latestFor($cheque->fresh(), AccountsSignature::CHEQUE_BOUNCE);
        $this->assertSame(Approval::PENDING, $approval?->status);

        Event::fake([ChequeBounced::class]);
        app(ApprovalEngine::class)->approve($approval, $this->owner);

        $this->assertSame(Cheque::CANCELLED, $cheque->fresh()->status, '⛔ শেষ সই পড়ল, তবু চেক বাতিল হল না');
        $this->assertSame(0, bccomp($this->issuedLiability(), '0', 4), '⛔ বাতিলের পরেও দেওয়া চেকের দায় রয়ে গেল');
        Event::assertNotDispatched(ChequeBounced::class);
    }

    public function test_cancelling_a_received_cheque_does_not_report_a_bounce(): void
    {
        Event::fake([ChequeBounced::class]);

        $cheque = app(ChequeService::class)->create([
            'direction' => Cheque::RECEIVED, 'cheque_no' => 'CXR'.random_int(100000, 999999), 'bank_name' => 'Sonali Bank',
            'cheque_date' => now()->toDateString(), 'amount' => '3000',
            'party_type' => 'customer', 'party_id' => Customer::query()->orderBy('id')->firstOrFail()->id,
        ]);

        $this->assertSame(Cheque::CANCELLED, app(ChequeService::class)->cancel($cheque, 'বদলে দেওয়া হয়েছে')->status);
        Event::assertNotDispatched(ChequeBounced::class);
    }

    /** দেওয়া চেকের দায় (২১১৫) — Cr জের ধনাত্মক */
    private function issuedLiability(): string
    {
        return (string) LedgerEntry::query()->where('account_id', StandardChart::find(StandardChart::CHEQUES_ISSUED)->id)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as n')->value('n');
    }
}
