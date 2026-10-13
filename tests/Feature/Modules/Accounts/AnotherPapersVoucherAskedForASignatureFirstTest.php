<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\VoucherWriter;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ অন্য কাগজের ভাউচারে হাতে "পাকা" চাপলে আগে সইয়ের অনুরোধ বসত (Api/Sync নিরীক্ষা, ১৪ অক্টোবর ২০২৬; fe-র প্রশ্ন;
 * [[VoucherService::refuseIfOwnedElsewhere()]])।
 *
 * ⚠️ পাকা করার দরজা ([[VoucherWriter::post()]], ওয়েব আর ফোন দুটোরই) সই চালু থাকলে আগে অনুরোধ বসাত, তারপর তবে a6-র
 * "নিজের মডিউলে" পাহারা দেখত — সইকারী অকারণে সই দিতেন, আর শেষ সইয়ে তবে থামত। ⭐ এখন প্রথম চাপেই কারণসহ, কোনো অনুরোধ নয়।
 */
final class AnotherPapersVoucherAskedForASignatureFirstTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owned_voucher_is_refused_before_any_signature_is_asked(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $flow = ApprovalFlow::query()->create(['module' => VoucherApproval::MODULE, 'action' => Voucher::JOURNAL, 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $owner->id]);

        $lines = [
            ['account_id' => (int) Account::query()->where('code', '5202')->value('id'), 'debit' => '500'],
            ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'credit' => '500'],
        ];
        $owned = app(VoucherService::class)->create(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'হাতধারের ভাউচার'], $lines);

        $account = DB::table('fin_hand_loan_accounts')->insertGetId(['company_id' => $company->id, 'person_name' => 'রহিম',
            'status' => 'active', 'public_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fin_hand_loan_movements')->insert(['company_id' => $company->id, 'account_id' => $account, 'direction' => 'out',
            'amount' => '500', 'moved_on' => now()->toDateString(), 'voucher_id' => $owned->id,
            'public_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);

        try {
            app(VoucherWriter::class)->post($owned->fresh());
            $this->fail('⛔ অন্য কাগজের ভাউচার হাতে পাকার পথে এগোল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(0, Approval::query()->where('approvable_type', $owned->getMorphClass())->where('approvable_id', $owned->id)->count(),
            '⛔ অন্য কাগজের ভাউচারে অকারণে সইয়ের অনুরোধ বসল');
    }
}
