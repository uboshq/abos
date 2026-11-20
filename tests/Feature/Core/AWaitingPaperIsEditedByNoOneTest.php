<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\WaitingPaper;
use App\Core\Services\FormBranchPin;
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
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ সইয়ের অপেক্ষার কাগজ কেউ বদলাতে পারেন না — মালিকের চূড়ান্ত সিদ্ধান্ত, ২০ নভেম্বর ২০২৬ (maker-checker; [[WaitingPaper]])।
 *
 * ── কী ঘটেছিল ────────────────────────────────────────────────────────
 * লাইভে মালিক অপেক্ষার COL-0008 বদলালেন; নতুন অনুরোধ বসল তাঁর নিজের নামে, আর নিজের অনুরোধে নিজে সই দেওয়া যায় না — আটকে গেল।
 *
 * ── ⭐ যা মাপা হয় (আসল দরজায়) ───────────────────────────────────────
 *  · সইকারী আর লেখক — কেউই অপেক্ষার কাগজের সম্পাদনার পাতা খুলতে বা জমা দিতে পারেন না; কাগজ আর অনুরোধ অটুট।
 *  · পাতায় "সম্পাদনা" বোতাম লুকানো নয়, ধূসর, কারণসহ।
 *  · সংশোধনের জন্য ফেরত পাঠানো কাগজ লেখক আজকের মতোই বদলান।
 *  · মাস্টার রেকর্ড (গ্রাহক …) এই নিয়মের বাইরে।
 *
 * ⓘ ১৪ অক্টোবরের "মালিক বদলে সই দেবেন / সইকারী বিবরণ ঠিক করবেন" সেবার পথে এখনো আছে ([[ASignerSendsBackOrCorrectsOnlyTheTextTest]]),
 * কিন্তু পর্দার দরজায় আর নয় — নতুন সিদ্ধান্ত পুরনোটার উপরে।
 */
final class AWaitingPaperIsEditedByNoOneTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $signer;

    private User $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);

        // ⓘ সইকারীরও সম্পাদনার চাবি আছে — ঠিক মালিকের COL-0008-এর মতো, চাবি থাকলেও দরজা বন্ধ
        $this->signer = $this->person(['approval.view', 'approval.decide', 'accounts.voucher.view', 'accounts.voucher.create', 'accounts.voucher.update']);
        $this->writer = $this->person(['approval.view', 'accounts.voucher.view', 'accounts.voucher.create', 'accounts.voucher.update']);

        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->signer->id]);
    }

    public function test_neither_the_signer_nor_the_maker_can_open_or_save_an_edit_of_a_waiting_paper(): void
    {
        [$voucher, $asked] = $this->waiting();
        $show = route('accounts.voucher.show', $voucher);

        foreach (['সইকারী' => $this->signer, 'লেখক' => $this->writer] as $who => $person) {
            $this->actingAs($person->fresh());

            $this->get(route('accounts.voucher.edit', $voucher))->assertRedirect($show);
            $this->assertSame(__('core.approval_hold.refused'), session(FormBranchPin::OFFER)['message'] ?? null,
                "⛔ {$who}: সম্পাদনার পাতা থামল না, বা কারণ বলল না।");

            $this->from($show)->put(route('accounts.voucher.update', $voucher), $this->journal('অপেক্ষায় বদল', '700'))
                ->assertRedirect($show);

            $this->putJson(route('accounts.voucher.update', $voucher), $this->journal('অপেক্ষায় JSON', '700'))
                ->assertStatus(409)
                ->assertJsonPath('message', __('core.approval_hold.refused'));
        }

        $fresh = Voucher::query()->withoutGlobalScopes()->findOrFail($voucher->id);
        $this->assertSame('সইয়ের অপেক্ষায়', $fresh->narration, '⛔ অপেক্ষার কাগজ বদলানো গেল।');
        $this->assertSame(0, bccomp('500', (string) $fresh->amount, 4));
        $this->assertSame(Approval::PENDING, $asked->fresh()->status, '⛔ অপেক্ষার অনুরোধ উঠে গেল।');
        $this->assertSame(1, Approval::query()->where('approvable_type', Voucher::class)->where('approvable_id', $voucher->id)->count(),
            '⛔ নতুন অনুরোধ বসল — COL-0008-এর মতো আটকে যাওয়ার পথ।');
    }

    public function test_the_edit_button_is_greyed_with_the_reason_not_hidden(): void
    {
        [$voucher] = $this->waiting();

        $html = (string) $this->actingAs($this->signer->fresh())->get(route('accounts.voucher.show', $voucher))->assertOk()->getContent();

        $this->assertStringContainsString('data-approval-hold', $html, '⛔ অপেক্ষার কাগজে সম্পাদনার বোতাম ধূসর নয়।');
        $this->assertStringContainsString(e(__('core.approval_hold.hint')), $html, '⛔ ধূসর বোতামের কারণ নেই।');
        $this->assertStringNotContainsString('href="'.e(route('accounts.voucher.edit', $voucher)).'"', $html,
            '⛔ ধূসর বোতামও সম্পাদনার পাতায় নিয়ে যায়।');
    }

    public function test_a_paper_sent_back_for_correction_is_edited_by_its_maker_as_today(): void
    {
        [$voucher, $asked] = $this->waiting();

        $this->actingAs($this->signer->fresh())
            ->post(route('approval.inbox.send_back', $asked->id), ['remarks' => 'অঙ্ক ঠিক করুন'])
            ->assertSessionHasNoErrors();
        $this->assertSame(Approval::RETURNED, $asked->fresh()->status, 'প্রস্তুতি — ফেরত যাওয়ার কথা');
        $this->assertFalse(app(WaitingPaper::class)->waits(Voucher::query()->withoutGlobalScopes()->findOrFail($voucher->id)));

        $show = route('accounts.voucher.show', $voucher);
        $this->actingAs($this->writer->fresh());

        $this->get(route('accounts.voucher.edit', $voucher))->assertOk();
        $this->from($show)->put(route('accounts.voucher.update', $voucher), $this->journal('লেখক ঠিক করলেন', '450'))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing(FormBranchPin::OFFER);

        $this->assertSame('লেখক ঠিক করলেন', Voucher::query()->withoutGlobalScopes()->findOrFail($voucher->id)->narration,
            '⛔ ফেরত পাওয়া কাগজ লেখক বদলাতে পারলেন না।');
    }

    public function test_a_master_record_is_outside_the_rule(): void
    {
        $customer = Customer::query()->firstOrFail();
        Approval::query()->create([
            'company_id' => $this->company->id, 'approvable_type' => $customer->getMorphClass(), 'approvable_id' => $customer->id,
            'module' => 'customer', 'action' => 'credit_limit', 'status' => Approval::PENDING, 'requested_by' => $this->writer->id,
        ]);

        $this->assertFalse(app(WaitingPaper::class)->waits($customer), '⛔ গ্রাহকের একটা ঘরের অনুরোধে গোটা গ্রাহক তালাবদ্ধ।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return array{0: Voucher, 1: Approval} */
    private function waiting(): array
    {
        [$a, $b] = $this->accounts();

        $this->actingAs($this->writer->fresh());
        [$voucher] = app(VoucherWriter::class)->store(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'সইয়ের অপেক্ষায়', 'branch_id' => CompanyContext::branchId()],
            [['account_id' => $a->id, 'debit' => '500', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '500']],
            asDraft: false,
        );

        $asked = Approval::query()->where('approvable_type', Voucher::class)->where('approvable_id', $voucher->id)->sole();
        $this->assertSame(Approval::PENDING, $asked->status, 'প্রস্তুতি — সই চাওয়ার কথা');
        $this->assertTrue(app(WaitingPaper::class)->waits($voucher), 'প্রস্তুতি — কাগজ অপেক্ষায় ধরা পড়ার কথা');

        return [$voucher, $asked];
    }

    /** @return array<string, mixed> */
    private function journal(string $narration, string $amount): array
    {
        [$a, $b] = $this->accounts();

        return ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => $narration, 'save_as_draft' => '1',
            'lines' => [
                ['account_id' => $a->id, 'debit' => $amount, 'credit' => '0'],
                ['account_id' => $b->id, 'debit' => '0', 'credit' => $amount],
            ]];
    }

    /** @return list<Account> */
    private function accounts(): array
    {
        return Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();
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
