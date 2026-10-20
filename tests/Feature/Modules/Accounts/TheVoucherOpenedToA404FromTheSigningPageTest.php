<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Drill\DrillResolver;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\AuditTrail;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ভাউচার নাগাল ধরে খোলে, মাথায় বাছা শাখা ধরে নয় — মালিকের বাগ "ভাউচার ভিউ হয় না — সইয়ের পাতা, নোটিশ সেন্টার, আর কয়েক
 * জায়গায়" (fe, ১৯ অক্টোবর ২০২৬; [[OpensInReach]], [[PaperSigners]])।
 *
 * ⛔ আগে ভাউচারের পাতা কেবল মাথায় বাছা শাখার ভাউচার খুঁজত: মালিক মাথায় নেত্রকোনা বাছা অবস্থায় সইয়ের পাতা বা নোটিশ থেকে
 * ময়মনসিংহের ভাউচারে চাপলে ৪০৪; সইকারীর `accounts.report` না থাকলে ৪০৩; স্লিপ আর রিপোর্টের "দলিল" ঘর খুঁজে পেত না;
 * নিরীক্ষা জীবিত ভাউচারকে "নেই" বলত।
 *
 * দাবি:
 *  - মালিক, মাথায় অন্য শাখা: অন্য শাখার আর শাখা ছাড়া ভাউচার — পাতা আর ছাপা ২০০; খোঁজার যন্ত্র আর নিরীক্ষা খুঁজে পায়।
 *  - শাখা-সীমিত কর্মী: নিজের নাগালের আর শাখা ছাড়া ভাউচার ২০০; নাগালের বাইরের ৪০৪ (পাতা), খোঁজার যন্ত্রে নেই, নিরীক্ষায় নেই।
 *  - সইকারী (`accounts.report` নেই, নাগালের বাইরে): যে ভাউচারে তিনি চলতি বা আগের সইকারী — পাতা ২০০; অন্য ভাউচার ৪০৪;
 *    আর সইয়ের ছাড় কেবল পড়ায় — ঐ ভাউচার পাকা করার দরজায় ৪০৪।
 */
final class TheVoucherOpenedToA404FromTheSigningPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->branch('MMS')->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
    }

    public function test_the_owner_opens_another_branchs_voucher_under_any_header_branch(): void
    {
        $mms = $this->voucher('MMS');
        $none = $this->voucher(null);

        $this->header($this->owner, 'NTK');

        foreach ([$mms, $none] as $v) {
            $this->get(route('accounts.voucher.show', $v->id))->assertOk();
            $this->get(route('accounts.voucher.print', $v->id))->assertOk();
            $this->assertNotNull(app(DrillResolver::class)->resolve('voucher', (int) $v->id), '⛔ স্লিপ/দলিলের ঘর ভাউচারটা খুঁজে পেল না');
            $this->assertNotNull($this->auditOf($v)?->auditable(), '⛔ নিরীক্ষা জীবিত ভাউচারকে "নেই" বলল');
        }
    }

    public function test_a_branch_limited_clerk_opens_only_what_is_in_reach(): void
    {
        $mms = $this->voucher('MMS');
        $ntk = $this->voucher('NTK');
        $none = $this->voucher(null);

        $clerk = $this->staff(['accounts.report'], reach: 'NTK');
        $this->header($clerk, 'NTK');

        $this->get(route('accounts.voucher.show', $ntk->id))->assertOk();
        $this->get(route('accounts.voucher.show', $none->id))->assertOk();
        $this->get(route('accounts.voucher.show', $mms->id))->assertNotFound();
        $this->assertNull(app(DrillResolver::class)->resolve('voucher', (int) $mms->id), '⛔ নাগালের বাইরের ভাউচার খোঁজার যন্ত্রে এলো');
        $this->assertNull($this->auditOf($mms)?->auditable(), '⛔ নাগালের বাইরের ভাউচার নিরীক্ষায় খুলল');
    }

    public function test_a_signer_reads_only_the_voucher_they_sign_and_cannot_post_it(): void
    {
        $signedBefore = $this->voucher('MMS');
        $waiting = $this->voucher('MMS');
        $stranger = $this->voucher('MMS');

        // ⓘ সইকারী: অন্য শাখার মানুষ, `accounts.report` নেই — কেবল অনুমোদনের চাবি
        $signer = $this->staff(['approval.decide'], reach: 'NTK');

        // আগের সইকারী — এই ভাউচারের অনুরোধে আগে "হ্যাঁ" দিয়েছেন
        $before = $this->approval($signedBefore, Approval::APPROVED);
        ApprovalDecision::query()->create(['approval_id' => $before->id, 'level' => 1, 'user_id' => $signer->id,
            'decision' => ApprovalDecision::APPROVED, 'decided_at' => now()]);

        // চলতি সইকারী — এই ভাউচারের অপেক্ষার অনুরোধে এখন সিদ্ধান্ত তাঁর
        $flow = ApprovalFlow::query()->create(['module' => 'accounts', 'action' => 'expense']);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id]);
        $this->approval($waiting, Approval::PENDING);

        $this->header($signer, 'NTK');

        $this->get(route('accounts.voucher.show', $signedBefore->id))->assertOk();
        $this->get(route('accounts.voucher.show', $waiting->id))->assertOk();
        $this->get(route('accounts.voucher.show', $stranger->id))->assertNotFound();

        // ⛔ সইয়ের ছাড় কেবল পড়ায় — নাগালের বাইরের ভাউচার পাকা করার দরজা খোলে না
        $this->post(route('accounts.voucher.post', $waiting->id))->assertNotFound();
        $this->assertSame('draft', (string) $waiting->fresh()->status);
    }

    private function voucher(?string $branchCode): Voucher
    {
        $cash = Account::query()->where('money_kind', Account::CASH)->postable()->firstOrFail();
        $expense = Account::query()->where('type', Account::EXPENSE)->postable()->firstOrFail();
        $voucher = app(VoucherService::class)->create([
            'type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'reach test',
            'instrument_no' => 'TST-VR-'.random_int(1, 999999),
        ], [
            ['account_id' => $expense->id, 'debit' => '100', 'credit' => '0'],
            ['account_id' => $cash->id, 'debit' => '0', 'credit' => '100'],
        ]);
        $voucher->forceFill(['branch_id' => $branchCode === null ? null : $this->branch($branchCode)->id])->saveQuietly();

        return Voucher::acrossBranches()->findOrFail($voucher->id);
    }

    private function approval(Voucher $voucher, string $status): Approval
    {
        return Approval::query()->create([
            'company_id' => $this->company->id, 'approvable_type' => $voucher->getMorphClass(), 'approvable_id' => $voucher->id,
            'module' => 'accounts', 'action' => 'expense', 'status' => $status, 'current_level' => 1,
            'requested_by' => $this->owner->id, 'requested_at' => now(),
        ]);
    }

    private function auditOf(Voucher $voucher): ?AuditTrail
    {
        return AuditTrail::query()->withoutGlobalScopes()->where('auditable_type', $voucher->getMorphClass())
            ->where('auditable_id', $voucher->id)->latest('id')->first();
    }

    /** @param list<string> $keys */
    private function staff(array $keys, string $reach): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, function () use ($user, $keys): void {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $user->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->branch($reach)->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function header(User $user, string $code): void
    {
        $branch = $this->branch($code);
        $user->forceFill(['view_all_branches' => false, 'current_branch_id' => $branch->id])->save();
        CompanyContext::set($this->company->id, $branch->id);
        app(DataScope::class)->forget();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh());
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
