<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Sync\SyncService;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ ফোনে যে অঙ্ক দেখে সই, সেই অঙ্কেই — ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬ ([[ApprovalApiController::approve()]])।
 *
 * ⓘ তালিকা খোলার পরে খসড়ার অঙ্ক বদলালে (RCV-0090: ৩৯,৯৯০ → ২৪,৯১৩) পুরনো কার্ডের বোতাম নতুন অঙ্কে সই দিত — সইকারী যা দেখেননি।
 * নতুন অ্যাপ দেখা অঙ্ক পাঠায়; না মিললে ৪১২ আর ফোন তালিকা আবার আনে। পুরনো অ্যাপ (অঙ্ক পাঠায় না) আগের মতোই।
 */
final class TheSignerSignsTheAmountTheySawTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    public function test_an_approval_at_an_amount_no_longer_on_the_paper_is_refused_and_the_current_one_signs(): void
    {
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);

        $signer = $this->person(['approval.decide']);
        $writer = $this->person(['accounts.voucher.create']);
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $signer->id]);

        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();
        $this->app['auth']->forgetGuards();
        $this->actingAs($writer);
        $out = app(SyncService::class)->push($writer, 'phone-seen', 'accounts', [[
            'changeId' => 'seen-1', 'entityType' => 'Voucher', 'operation' => 'CREATE',
            'payloadJson' => json_encode(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'সমন্বয়',
                'lines' => [['account_id' => $a->id, 'debit' => '100', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '100']]]),
        ]]);
        $this->assertSame('APPLIED', $out[0]['status'], json_encode($out, JSON_UNESCAPED_UNICODE));
        $voucher = Voucher::query()->where('public_id', $out[0]['entityId'])->firstOrFail();
        $approval = Approval::query()->where('approvable_type', Voucher::class)->where('approvable_id', $voucher->id)->pending()->firstOrFail();

        // ⓘ কার্ড ১০০ দেখিয়েছিল; তারপর খসড়ার অঙ্ক বদলাল
        $this->phone($signer)->getJson('/api/v1/approvals/pending')->assertOk()->assertJsonFragment(['amount' => '100.0000']);
        DB::table('vouchers')->where('id', $voucher->id)->update(['amount' => '250.0000']);

        $this->phone($signer)->postJson('/api/v1/approvals/'.$approval->public_id.'/approve', ['seenAmount' => '100.0000'])
            ->assertStatus(412)->assertJsonPath('amount', '250.0000');
        $this->assertSame(Approval::PENDING, $approval->fresh()->status, '⛔ যে অঙ্ক দেখা হয়নি তাতে সই পড়ল');

        // ⓘ নতুন অঙ্ক দেখে — চলে
        $this->phone($signer)->postJson('/api/v1/approvals/'.$approval->public_id.'/approve', ['seenAmount' => '250'])->assertOk();
        $this->assertSame(Approval::APPROVED, $approval->fresh()->status);
    }

    /** @param list<string> $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id])->save();
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function phone(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }
}
