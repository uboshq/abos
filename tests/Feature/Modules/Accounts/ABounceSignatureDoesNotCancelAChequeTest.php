<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Services\AccountsSignature;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * ⭐ চেক "ফেরত"-এর সই "বাতিল" পার করে না, "বাতিল"-এর সই "ফেরত" নয় — হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (জোড়া লাগানোর পরে)।
 *
 * ⓘ দুইটাই একই সইয়ের ছকে (`cheque_bounce`), তফাত কেবল অনুরোধের তথ্যে (`cancel`)। ⛔ ফেরতের অনুরোধে তথ্যটা না লিখলে আগের কোনো
 * ফেরতের সই বাতিলের চাপে "পুরনো সারি" ধরে খাটত — অন্য কাজ, অন্য খাতা ([[DocumentApproval::signedTheSame()]])।
 */
final class ABounceSignatureDoesNotCancelAChequeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_two_kinds_are_signed_apart(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $flow = ApprovalFlow::create(['company_id' => $company->id, 'module' => AccountsSignature::MODULE,
            'action' => AccountsSignature::CHEQUE_BOUNCE, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $owner->id]);

        $cheque = app(ChequeService::class)->create([
            'direction' => Cheque::ISSUED, 'cheque_no' => 'BNC'.random_int(100000, 999999), 'bank_name' => 'Sonali Bank',
            'cheque_date' => now()->toDateString(), 'amount' => '5000',
            'party_type' => 'supplier', 'party_id' => Supplier::query()->firstOrFail()->id,
        ]);

        // ⓘ ফেরতের সই চাওয়া আর দেওয়া — কিন্তু সইয়ের শ্রোতা চুপ (শেষ করা আটকে গেছে, এমন অবস্থা)
        $held = app(ChequeService::class)->bounce($cheque, 'ব্যাংক ফেরত দিয়েছে');
        $this->assertNotSame(Cheque::BOUNCED, $held->status, 'প্রস্তুতি — ফেরত সইয়ের অপেক্ষায় থাকার কথা');
        Event::fake([\App\Core\Events\ApprovalDecided::class]);
        app(ApprovalEngine::class)->approve(app(ApprovalEngine::class)->latestFor($cheque, AccountsSignature::CHEQUE_BOUNCE), $owner);

        $after = app(ChequeService::class)->cancel($cheque->fresh(), 'ছিঁড়ে ফেলা হয়েছে');

        $this->assertNotSame(Cheque::CANCELLED, $after->status, '⛔ চেক ফেরতের সই দিয়ে চেক বাতিল পার হল।');
        $this->assertSame(\App\Models\Approval::PENDING, app(ApprovalEngine::class)->latestFor($cheque->fresh(), AccountsSignature::CHEQUE_BOUNCE)?->status,
            '⛔ বাতিলের জন্য নতুন সই চাওয়া হয়নি।');
    }
}
