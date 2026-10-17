<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\VoucherWriter;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ সইয়ের অপেক্ষার কাগজ বদলালে পুরনো অনুরোধ তুলে নতুন করে চাওয়া হয় — লাইভের ঘটনা, ADI RCV-0090 (১৩ অক্টোবর ২০২৬)।
 *
 * ⛔ ১৭:৪৩-এ ৩৯,৯৯০ টাকার রসিদ, সই চাওয়া হল ৩৯,৯৯০-এ (অনুমোদন ১৯৮, অপেক্ষায়)। ১৮:৫৪-এ লেখক খসড়াটা ভেঙে ২৪,৯১৩ করলেন, নতুন
 * বিবরণসহ। অনুরোধটা তখনো বলছিল ৩৯,৯৯০ — ফোনের সইয়ের কার্ডে পুরনো অঙ্ক আর নতুন বিবরণ পাশাপাশি, মালিক বিভ্রান্ত।
 *
 * দাবি:
 *  - বদলানো খসড়ার পুরনো অনুরোধ বাতিল (তুলে নেওয়া), নতুন অনুরোধ নতুন অঙ্কে, পুরনোটার কথা বলে;
 *  - পুরনো অনুরোধে সই দেওয়া যায় না;
 *  - নতুনটায় সই দিলে ঠিক নতুন কাগজটাই খাতায়।
 */
final class AnEditedWaitingPaperIsAskedAgainTest extends TestCase
{
    use RefreshDatabase;

    private User $signer;

    private User $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);

        $this->signer = $this->person(['approval.view', 'approval.decide']);
        $this->writer = $this->person(['accounts.voucher.create', 'accounts.voucher.update']);

        $flow = ApprovalFlow::create(['company_id' => $company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->signer->id]);
    }

    public function test_an_edit_while_waiting_withdraws_the_old_request_and_asks_at_the_new_amount(): void
    {
        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();

        $this->as($this->writer);
        [$voucher] = app(VoucherWriter::class)->store(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'পুরো টাকা', 'branch_id' => CompanyContext::branchId()],
            [['account_id' => $a->id, 'debit' => '39990', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '39990']],
            asDraft: false,
        );
        $old = Approval::query()->where('approvable_type', Voucher::class)->where('approvable_id', $voucher->id)->sole();
        $this->assertSame(Approval::PENDING, $old->status, 'প্রস্তুতি — সই চাওয়ার কথা');

        // ⓘ লেখক খসড়াটা ভেঙে ২৪,৯১৩ করলেন, নতুন বিবরণ — খসড়া হিসেবেই রাখলেন
        app(VoucherWriter::class)->update(Voucher::query()->findOrFail($voucher->id), ['narration' => 'ভাগ করে — বাকিটা আলাদা রসিদে'],
            [['account_id' => $a->id, 'debit' => '24913', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '24913']],
            asDraft: true);

        $this->assertSame(Approval::CANCELLED, $old->fresh()->status,
            '⛔ ৩৯,৯৯০-এর অনুরোধ এখনো অপেক্ষায় — সইকারী পুরনো অঙ্ক দেখে নতুন কাগজে সই দেবেন।');

        $new = Approval::query()->where('approvable_type', Voucher::class)->where('approvable_id', $voucher->id)
            ->where('status', Approval::PENDING)->sole();
        $this->assertSame(0, bccomp('24913', (string) $new->amount, 4), '⛔ নতুন অনুরোধ নতুন অঙ্কে নয়।');
        $this->assertSame((int) $old->id, (int) ($new->payload['supersedes'] ?? 0), '⛔ নতুন অনুরোধ বলে না কোন অনুরোধের জায়গা নিল।');

        // ⛔ পুরনো অনুরোধে সই দেওয়া যায় না
        $this->as($this->signer);
        try {
            app(ApprovalEngine::class)->approve($old->fresh(), $this->signer);
            $this->fail('⛔ তুলে নেওয়া পুরনো অনুরোধে সই দেওয়া গেল।');
        } catch (\Throwable) {
        }
        $this->assertTrue(Voucher::query()->findOrFail($voucher->id)->isDraft(), '⛔ পুরনো অনুরোধের সইয়ে বদলানো ভাউচার খাতায় উঠল।');

        // ⭐ নতুনটায় সই — ঠিক নতুন কাগজটাই খাতায়
        app(ApprovalEngine::class)->approve($new->fresh(), $this->signer);
        $posted = Voucher::query()->findOrFail($voucher->id);
        $this->assertTrue($posted->isPosted(), '⛔ নতুন অনুরোধে সইয়ের পরেও ভাউচার খসড়া।');
        $this->assertSame(0, bccomp('24913', (string) $posted->amount, 4));
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh());
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $company->id, 'current_branch_id' => $company->defaultBranch()?->id])->save();
        CompanyContext::forCompany($company->id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
