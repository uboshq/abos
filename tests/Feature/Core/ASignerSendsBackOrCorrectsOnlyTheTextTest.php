<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\VoucherWriter;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ সইকারীর তিন পথ — মালিকের আদেশ, ১৪ অক্টোবর ২০২৬ ("ওকে তাই কর"); কারণ কাজের ভাগ (segregation of duties)।
 *
 *  ১. "সংশোধনে ফেরত" — প্রত্যেক সইকারী, নোট বাধ্যতামূলক; অনুরোধ নতুন অবস্থায় (ফেরত-সংশোধন, "না" নয়), কাগজ খসড়া, লেখক খবর পান;
 *     লেখক বদলে আবার পাঠালে নতুন কাগজে নতুন অনুরোধ।
 *  ২. কেবল মালিক অপেক্ষার কাগজ নিজে বদলে সই দিতে পারেন — প্রতিটা বদল নিরীক্ষায়, লেখক খবর পান, সই বদলানো কাগজের।
 *  ৩. অন্য সইকারী কেবল বিবরণ ঠিক করতে পারেন — অঙ্ক, খাত, পক্ষ, তারিখ, সারি নয়; অনুরোধ থাকে।
 */
final class ASignerSendsBackOrCorrectsOnlyTheTextTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $signer;

    private User $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);

        $this->signer = $this->person(['approval.view', 'approval.decide', 'accounts.voucher.view', 'accounts.voucher.update']);
        $this->writer = $this->person(['approval.view', 'accounts.voucher.view', 'accounts.voucher.create', 'accounts.voucher.update']);
    }

    public function test_a_signer_sends_it_back_with_a_note_and_the_resubmission_asks_again(): void
    {
        $this->signedBy($this->signer);
        [$voucher, $asked] = $this->waiting('500');

        $this->as($this->signer);
        $this->post(route('approval.inbox.send_back', $asked->id), ['remarks' => ''])->assertSessionHasErrors('remarks');
        $this->post(route('approval.inbox.send_back', $asked->id), ['remarks' => 'খাতটা ভুল — অফিস খরচে দিন'])->assertRedirect();

        $back = $asked->fresh();
        $this->assertSame(Approval::RETURNED, $back->status, '⛔ "সংশোধনে ফেরত" আলাদা অবস্থা নয়।');
        $decision = $back->decisions()->latest('id')->firstOrFail();
        $this->assertSame(ApprovalDecision::RETURNED, $decision->decision);
        $this->assertSame('খাতটা ভুল — অফিস খরচে দিন', $decision->remarks);
        $this->assertSame((int) $this->signer->id, (int) $decision->user_id);
        $this->assertTrue($this->reread($voucher)->isDraft(), '⛔ ফেরত পাঠানো কাগজ খসড়া নেই।');
        $this->assertTrue(DB::table('notifications')->where('user_id', $this->writer->id)->where('type', 'approval.returned')->exists(),
            '⛔ লেখক ফেরতের খবর পাননি।');

        // ⓘ ইনবক্সের পাতায় নোট আর কে ফেরত পাঠালেন
        $this->as($this->writer);
        $this->get(route('approval.inbox.show', $asked->id))->assertOk()->assertSee('খাতটা ভুল — অফিস খরচে দিন');

        // ⭐ লেখক সংশোধন করে আবার পাঠালেন — নতুন কাগজে নতুন অনুরোধ, পুরনোটা বন্ধ
        $this->rewrite($voucher, '500', narration: 'অফিস খরচে');
        $again = Approval::query()->where('approvable_type', Voucher::class)->where('approvable_id', $voucher->id)
            ->where('status', Approval::PENDING)->sole();
        $this->assertNotSame((int) $asked->id, (int) $again->id);
        $this->assertSame((int) $asked->id, (int) ($again->payload['supersedes'] ?? 0));
        $this->assertSame(Approval::RETURNED, $asked->fresh()->status);
    }

    public function test_the_phone_can_send_it_back_and_sees_which_buttons_it_may_show(): void
    {
        $this->signedBy($this->signer);
        [, $asked] = $this->waiting('500');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->signer->fresh(), [AuthController::APP]);

        $row = collect($this->getJson('/api/v1/approvals/pending')->assertOk()->json('rows'))->firstWhere('id', $asked->public_id);
        $this->assertTrue((bool) ($row['canSendBack'] ?? false), '⛔ ফোন জানে না "সংশোধনে ফেরত" দেখানো যায়।');
        $this->assertFalse((bool) ($row['canEditAndSign'] ?? true), '⛔ মালিক নন, অথচ "বদলে সই" দেখানো হবে।');

        $this->postJson('/api/v1/approvals/'.$asked->public_id.'/send-back', ['remarks' => 'তারিখ ভুল'])->assertOk()
            ->assertJsonPath('status', Approval::RETURNED);
    }

    public function test_another_signer_cannot_change_the_amount_or_the_account(): void
    {
        $this->signedBy($this->signer);
        [$voucher, $asked] = $this->waiting('500');

        $this->as($this->signer);
        foreach (['অঙ্ক' => ['700', null], 'খাত' => ['500', $this->accounts()[2]]] as $what => [$amount, $account]) {
            try {
                $this->rewrite($voucher, $amount, account: $account);
                $this->fail("⛔ মালিক নন এমন সইকারী অপেক্ষার কাগজের {$what} বদলালেন।");
            } catch (ValidationException $e) {
                $this->assertSame(__('accounts::validation.signer_may_only_correct_text'), $e->errors()['status'][0] ?? null,
                    json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
            }
        }

        $this->assertSame(0, bccomp('500', (string) $this->reread($voucher)->amount, 4));
        $this->assertSame(Approval::PENDING, $asked->fresh()->status);
    }

    public function test_another_signer_may_correct_the_narration_and_the_request_stays(): void
    {
        $this->signedBy($this->signer);
        [$voucher, $asked] = $this->waiting('500');

        $this->as($this->signer);
        $this->rewrite($voucher, '500', narration: 'বানান ঠিক করা');

        $this->assertSame(Approval::PENDING, $asked->fresh()->status, '⛔ কেবল বিবরণ ঠিক করায় অনুরোধটা উঠে গেল।');
        $this->assertSame(1, Approval::query()->where('approvable_type', Voucher::class)->where('approvable_id', $voucher->id)->count());
        $this->assertTrue(AuditTrail::query()->forRecord(Voucher::class, $voucher->id)->where('action', 'corrected_by_signer')->exists(),
            '⛔ সইকারীর সংশোধন নিরীক্ষায় নেই।');

        app(ApprovalEngine::class)->approve($asked->fresh(), $this->signer);
        $posted = $this->reread($voucher);
        $this->assertTrue($posted->isPosted(), '⛔ বিবরণ ঠিক করার পরে সই দিয়েও পোস্ট হল না।');
        $this->assertSame('বানান ঠিক করা', $posted->narration);
    }

    public function test_the_owner_edits_and_signs_exactly_the_edited_paper(): void
    {
        $this->signedBy($this->owner);
        [$voucher, $asked] = $this->waiting('500');

        $this->as($this->owner);
        $this->rewrite($voucher, '700', narration: 'মালিক ঠিক করলেন');

        $kept = $asked->fresh();
        $this->assertSame(Approval::PENDING, $kept->status, '⛔ মালিকের বদলে অনুরোধটা উঠে গেল — তিনি তো নিজেই সই দেবেন।');
        $this->assertSame(0, bccomp('700', (string) $kept->amount, 4), '⛔ অনুরোধ এখনো পুরনো অঙ্কে।');
        $this->assertTrue(AuditTrail::query()->forRecord(Voucher::class, $voucher->id)->where('action', 'edited_by_signer')->exists(),
            '⛔ মালিকের বদল নিরীক্ষায় নেই।');
        $this->assertTrue(DB::table('notifications')->where('user_id', $this->writer->id)->where('type', 'approval.edited_by_signer')->exists(),
            '⛔ লেখক জানলেন না তাঁর কাগজ বদলানো হয়েছে।');

        app(ApprovalEngine::class)->approve($kept, $this->owner);
        $posted = $this->reread($voucher);
        $this->assertTrue($posted->isPosted());
        $this->assertSame(0, bccomp('700', (string) $posted->amount, 4), '⛔ সই বদলানো কাগজের নয়।');
    }

    // ── হাতিয়ার ────────────────────────────────────────────────────────

    private function signedBy(User $approver): void
    {
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $approver->id]);
    }

    /** @return array{0: Voucher, 1: Approval} */
    private function waiting(string $amount): array
    {
        [$a, $b] = $this->accounts();

        $this->as($this->writer);
        [$voucher] = app(VoucherWriter::class)->store(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'প্রথম লেখা', 'branch_id' => CompanyContext::branchId()],
            [['account_id' => $a->id, 'debit' => $amount, 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => $amount]],
            asDraft: false,
        );

        $asked = Approval::query()->where('approvable_type', Voucher::class)->where('approvable_id', $voucher->id)->sole();
        $this->assertSame(Approval::PENDING, $asked->status, 'প্রস্তুতি — সই চাওয়ার কথা');

        return [$voucher, $asked];
    }

    private function rewrite(Voucher $voucher, string $amount, ?Account $account = null, ?string $narration = null): void
    {
        [$a, $b] = $this->accounts();

        app(VoucherWriter::class)->update($this->reread($voucher), $narration === null ? [] : ['narration' => $narration],
            [['account_id' => ($account ?? $a)->id, 'debit' => $amount, 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => $amount]],
            asDraft: false);
    }

    /** @return list<Account> */
    private function accounts(): array
    {
        return Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(3)->get()->all();
    }

    private function reread(Voucher $voucher): Voucher
    {
        return Voucher::query()->withoutGlobalScope('user-branch')->findOrFail($voucher->id);
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh());
    }

    /** @param  list<string>  $keys */
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
}
