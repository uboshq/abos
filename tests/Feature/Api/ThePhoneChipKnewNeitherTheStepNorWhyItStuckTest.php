<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\SignedPaperFinisher;
use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\NotificationEvent;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\VoucherWriter;
use App\Modules\Hr\Models\ExpenseClaim;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\ExpenseClaimService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনের কাগজের চিপ জানত না কোন ধাপে সই আটকে, আর সই শেষে পোস্ট কেন হয়নি — a6, ২০ অক্টোবর ২০২৬।
 *
 * ⓘ অ্যাপের চিপ "সইয়ের অপেক্ষায় · ২-এর ১" দেখায় `approval_level`/`approval_levels` পেলে, আর আটকে থাকা কাগজে বাংলা কারণ
 * ([[PaperSigningFacts]], [[ApprovalEngine::standingOf()]], [[SignedButNotPosted::among()]])।
 *
 * দাবি:
 *  - দুই ধাপের ছকে ভাউচার ধাপ ১-এ: ১ আর ২; ধাপ ১ সই দিলে: ২ আর ২; পাকা হলে: দুটোই null — তালিকা আর পাতা দুটোতেই।
 *  - খরচের দাবি একইভাবে।
 *  - ⛔ শেষ সইয়ের পরে মাস বন্ধ থাকায় পোস্ট আটকাল: `state` আগের মতো `draft`, `posting_failed` true, আর `post_problem` ঠিক সেই
 *    লেখা যা ঘণ্টার খবরে লেখক পেলেন ("কারণ: …")। সাধারণ খসড়ায় false/null।
 *  - ⛔ তালিকা সারি ধরে কোয়েরি বাড়ায় না — ২ সারি আর ৬ সারিতে কোয়েরির সংখ্যা এক।
 */
final class ThePhoneChipKnewNeitherTheStepNorWhyItStuckTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $first;

    private User $second;

    private User $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);
        app(SettingsService::class)->set(PhoneModules::PREFIX.'accounts', true);
        app(SettingsService::class)->set(PhoneModules::PREFIX.'hr', true);

        $this->first = $this->person(['approval.decide']);
        $this->second = $this->person(['approval.decide']);
        $this->writer = $this->person(['accounts.voucher.create', 'accounts.voucher.update', 'hr.claim.self']);
    }

    public function test_a_voucher_in_a_two_step_flow_says_one_of_two_then_two_of_two_then_nothing_once_posted(): void
    {
        $this->twoSteps('accounts', 'journal');
        [$voucher, $approval] = $this->sentForSignature();

        $this->assertStep($voucher, 1, 2, '⛔ ধাপ ১-এ অপেক্ষার ভাউচার "২-এর ১" বলে না।');

        $this->as($this->first);
        app(ApprovalEngine::class)->approve($approval->fresh(), $this->first);
        $this->assertSame(2, (int) $approval->fresh()->current_level, 'পরীক্ষার ভিত্তি — প্রথম সইয়ে কাগজ ধাপ ২-এ');

        $this->assertStep($voucher, 2, 2, '⛔ প্রথম সইয়ের পরে চিপ এখনো ধাপ ১ বলে।');

        $this->as($this->second);
        app(ApprovalEngine::class)->approve($approval->fresh(), $this->second);
        $this->assertTrue(Voucher::query()->withoutGlobalScopes()->findOrFail($voucher->id)->isPosted(), 'পরীক্ষার ভিত্তি — শেষ সইয়ে পাকা');

        $row = $this->listRow($voucher);
        $page = $this->showPage($voucher);
        foreach ([$row, $page] as $answer) {
            $this->assertSame('posted', $answer['state']);
            $this->assertNull($answer['approval_level'], '⛔ পাকা ভাউচারে এখনো সইয়ের ধাপ।');
            $this->assertNull($answer['approval_levels'], '⛔ পাকা ভাউচারে এখনো সইয়ের ধাপ।');
            $this->assertFalse($answer['posting_failed'], '⛔ পাকা ভাউচারকে "পোস্ট হয়নি" বলা হলো।');
            $this->assertNull($answer['post_problem']);
        }
    }

    public function test_a_claim_in_a_two_step_flow_says_one_of_two_then_two_of_two_then_nothing_once_approved(): void
    {
        $this->twoSteps(ExpenseClaimService::MODULE, ExpenseClaim::ACTION_EXPENSE);
        $claim = $this->claim();
        $approval = Approval::query()->where('approvable_type', $claim->getMorphClass())->where('approvable_id', $claim->id)->pending()->sole();

        $this->assertClaimStep($claim, 1, 2, '⛔ ধাপ ১-এ অপেক্ষার দাবি "২-এর ১" বলে না।');

        $this->as($this->first);
        app(ApprovalEngine::class)->approve($approval->fresh(), $this->first);
        $this->assertClaimStep($claim, 2, 2, '⛔ প্রথম সইয়ের পরে দাবির চিপ এখনো ধাপ ১ বলে।');

        $this->as($this->second);
        app(ApprovalEngine::class)->approve($approval->fresh(), $this->second);
        $this->assertSame(ExpenseClaim::APPROVED, $claim->fresh()->status, 'পরীক্ষার ভিত্তি — শেষ সইয়ে অনুমোদিত');

        $this->phone($this->writer);
        $row = collect($this->getJson(route('api.hr.claim.index'))->assertOk()->json('claims'))->firstWhere('id', $claim->public_id);
        $this->assertNull($row['approval_level'], '⛔ অনুমোদিত দাবিতে এখনো সইয়ের ধাপ।');
        $this->assertNull($row['approval_levels']);
        $this->assertFalse($row['posting_failed']);
        $this->assertNull($row['post_problem']);
    }

    public function test_a_signed_voucher_the_closed_month_refused_says_why_in_the_writers_own_words(): void
    {
        $this->twoSteps('accounts', 'journal');
        [$voucher, $approval] = $this->sentForSignature();
        $plain = $this->draft('সাধারণ খসড়া');

        $this->as($this->first);
        app(ApprovalEngine::class)->approve($approval->fresh(), $this->first);

        // ⓘ শেষ সইয়ের আগে মাসটা বন্ধ হলো — [[SignedPaperFinisher]] পোস্ট করতে গিয়ে আটকায়
        PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => (int) now()->year, 'month' => (int) now()->month,
            'reason' => 'রিপোর্ট পাঠানো হয়ে গেছে', 'locked_by' => $this->first->id, 'locked_at' => now(),
        ]);

        $this->as($this->second);
        app(ApprovalEngine::class)->approve($approval->fresh(), $this->second);
        $this->assertTrue(Voucher::query()->withoutGlobalScopes()->findOrFail($voucher->id)->isDraft(), 'পরীক্ষার ভিত্তি — বন্ধ মাসে খসড়াই');
        $this->assertSame(Approval::APPROVED, $approval->fresh()->status);

        $told = NotificationEvent::query()->where('type', SignedPaperFinisher::NOTICE)
            ->where('subject_type', Voucher::drillSourceType())->where('subject_id', $voucher->id)->latest('id')->value('body');
        $this->assertIsString($told, 'পরীক্ষার ভিত্তি — লেখক খবর পেলেন');
        $this->assertStringStartsWith(Str::before((string) __('core.approval.signed_paper_stuck_body'), ':reason'), $told);

        foreach ([$this->listRow($voucher), $this->showPage($voucher)] as $answer) {
            // ⛔ অবস্থার নাম আগের মতো — পুরনো অ্যাপ "not_posted" চেনে না, আর হাতে পোস্টের বোতাম খসড়াতেই
            $this->assertSame('draft', $answer['state'], '⛔ অবস্থার নাম বদলে পুরনো অ্যাপ ভাঙল।');
            $this->assertNull($answer['approval_level'], '⛔ সই শেষ, তবু ধাপ দেখায়।');
            $this->assertTrue($answer['posting_failed'], '⛔ সই শেষ অথচ পোস্ট আটকে — ফোন জানে না।');
            $this->assertSame($told, $answer['post_problem'], '⛔ ফোনের কারণ ঘণ্টার কারণের সাথে মেলে না।');
        }

        $this->assertTrue($this->showPage($voucher)['can_post'] ?? false, '⛔ কারণ মিটিয়ে হাতে পোস্টের বোতাম হারাল।');

        $other = $this->listRow($plain);
        $this->assertFalse($other['posting_failed'], '⛔ সই না চাওয়া খসড়াকেও "পোস্ট হয়নি" বলা হলো।');
        $this->assertNull($other['post_problem']);

        // ⓘ হাতে পোস্ট হলে কারণ নিজেই নামে — আলাদা কোনো খাতা নেই
        PeriodLock::query()->delete();
        $this->as($this->writer);
        app(VoucherWriter::class)->post(Voucher::query()->withoutGlobalScopes()->findOrFail($voucher->id));
        $after = $this->listRow($voucher);
        $this->assertSame('posted', $after['state']);
        $this->assertFalse($after['posting_failed']);
        $this->assertNull($after['post_problem'], '⛔ পাকা হওয়ার পরেও পুরনো কারণ।');
    }

    public function test_the_list_asks_the_same_number_of_questions_for_two_rows_and_for_six(): void
    {
        $this->twoSteps('accounts', 'journal');
        $this->sentForSignature();
        $this->draft('এক');

        $this->phone($this->writer);
        $this->getJson('/api/v1/accounts/vouchers')->assertOk(); // ⓘ গরম করা — সেটিং আর ছকের প্রথম পড়া মাপের বাইরে

        $two = $this->queriesOfTheList(2);

        foreach (range(1, 2) as $i) {
            $this->sentForSignature();
            $this->draft('আরও '.$i);
        }

        $six = $this->queriesOfTheList(6);

        $this->assertSame($two, $six, "⛔ তালিকা সারি ধরে কোয়েরি বাড়ায়: ২ সারিতে {$two}, ৬ সারিতে {$six}।");
    }

    // ── যন্ত্রপাতি ─────────────────────────────────────────────────────

    private function queriesOfTheList(int $rows): int
    {
        $this->phone($this->writer);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $answer = $this->getJson('/api/v1/accounts/vouchers')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount($rows, $answer->json('rows'), 'পরীক্ষার ভিত্তি — সারির সংখ্যা');
        $this->assertContains(1, collect($answer->json('rows'))->pluck('approval_level')->all(), 'পরীক্ষার ভিত্তি — ধাপের ঘর ভরা');

        return $count;
    }

    private function assertStep(Voucher $voucher, int $level, int $levels, string $why): void
    {
        foreach ([$this->listRow($voucher), $this->showPage($voucher)] as $answer) {
            $this->assertSame('awaiting', $answer['state']);
            $this->assertSame($level, $answer['approval_level'], $why);
            $this->assertSame($levels, $answer['approval_levels'], $why);
            $this->assertFalse($answer['posting_failed'], '⛔ সইয়ের অপেক্ষার কাগজকে "পোস্ট আটকে" বলা হলো।');
            $this->assertNull($answer['post_problem']);
        }
    }

    private function assertClaimStep(ExpenseClaim $claim, int $level, int $levels, string $why): void
    {
        $this->phone($this->writer);
        $row = collect($this->getJson(route('api.hr.claim.index'))->assertOk()->json('claims'))->firstWhere('id', $claim->public_id);
        $page = $this->getJson(route('api.hr.claim.show', $claim->public_id))->assertOk()->json();

        foreach ([$row, $page] as $answer) {
            $this->assertSame(ExpenseClaim::SUBMITTED, $answer['status']);
            $this->assertSame($level, $answer['approval_level'], $why);
            $this->assertSame($levels, $answer['approval_levels'], $why);
        }
    }

    /** @return array<string, mixed> */
    private function listRow(Voucher $voucher): array
    {
        $this->phone($this->writer);
        $row = collect($this->getJson('/api/v1/accounts/vouchers')->assertOk()->json('rows'))->firstWhere('id', $voucher->public_id);
        $this->assertIsArray($row, 'পরীক্ষার ভিত্তি — ভাউচারটা লেখকের তালিকায়');

        return $row;
    }

    /** @return array<string, mixed> */
    private function showPage(Voucher $voucher): array
    {
        $this->phone($this->writer);

        return $this->getJson('/api/v1/accounts/vouchers/'.$voucher->public_id)->assertOk()->json();
    }

    private function twoSteps(string $module, string $action): void
    {
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => $module, 'action' => $action, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->first->id]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 2, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->second->id]);
        $this->app->forgetInstance(ApprovalEngine::class);
    }

    /** @return array{0: Voucher, 1: Approval} লেখকের খসড়া, সইয়ের অপেক্ষায় */
    private function sentForSignature(): array
    {
        $voucher = $this->draft('সইয়ের অপেক্ষায়');
        $stopping = app(VoucherWriter::class)->post($voucher);
        $this->assertNotNull($stopping, 'পরীক্ষার ভিত্তি — ভাউচার সই ছাড়াই পাকা হলো।');

        return [$voucher->fresh(), $stopping];
    }

    private function draft(string $narration): Voucher
    {
        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();

        $this->as($this->writer);

        return app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => $narration,
                'branch_id' => $this->company->defaultBranch()->id],
            [['account_id' => $a->id, 'debit' => '500', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '500']],
        );
    }

    private function claim(): ExpenseClaim
    {
        $employee = app(EmployeeService::class)->create(['code' => 'EMP-CHIP', 'name_en' => 'Chip Worker', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        $employee->forceFill(['user_id' => $this->writer->id])->save();
        $expense = Account::query()->postable()->active()->where('type', Account::EXPENSE)->orderBy('code')->firstOrFail();
        // ⓘ শেষ সইয়ে টাকা দেওয়ার খসড়া ভাউচার বসে — প্রধান বাক্সটা থাকুক ([[AnEmployeeAsksForMoneyTest]]-এর একই ভিত্তি)
        app(\App\Modules\Accounts\Services\CashTillService::class)->ensurePrimaryTill();

        $this->as($this->writer);

        return app(ExpenseClaimService::class)->submit($this->writer, [
            'kind' => ExpenseClaim::EXPENSE, 'amount' => '1200', 'reason' => 'Bus fare', 'spent_on' => now()->toDateString(),
            'expense_account_id' => $expense->id,
        ], null)->fresh();
    }

    private function phone(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);
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
