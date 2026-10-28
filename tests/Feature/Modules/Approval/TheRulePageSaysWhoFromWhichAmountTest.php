<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalCondition;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\VoucherWriter;
use App\Modules\Approval\Services\ApprovalFlowService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ অনুমোদন পর্দা ভাগ ৩, দফা ১৭ — নিয়মের পাতা (মালিকের "হ্যাঁ", ২১ অক্টোবর ২০২৬; পরিকল্পনার পাতা ৪)।
 *
 * ⛔ আগে: ছক ধরলে প্রতিটা ধাপ সই দিত — ৳১,০০০-এর কাগজও মালিকের কাছে যেত, নইলে মালিককে ছক থেকে সরাতে হত; বেতনের নিয়ম একটা চাপে
 * বন্ধ হত; শর্তের ঘর হাতে লিখতে হত (ভুল নাম = নিয়ম কখনো ধরে না); আর শাখার ঘর কাগজ থেকে আসতই না, তাই "শাখা = …" কখনো মিলত না।
 *
 * দাবি:
 *   ১৭ক — ধাপের "কত টাকা থেকে": ৳১,২০,০০০-এ হিসাবরক্ষক → ম্যানেজার, তারপরই অনুমোদিত (মালিক বাদ); ৳৬ লাখে মালিক পর্যন্ত;
 *          "ধাপ x / y"-এর y আর প্রথম সইয়ের ধাপ-গোনা একই নিয়মে;
 *   ১৭খ — ধাপ ১-এ সীমা নয়, এক স্তরে দুই সীমা নয়;
 *   ১৭গ — বেতনের নিয়ম সুইচে, ফর্মে বা মোছায় বন্ধ হয় না; অন্য নিয়ম সুইচে বন্ধ-চালু হয়;
 *   ১৭ঘ — তালিকার সারি: ধাপের রেখা, শেষ অনুমোদন আর তার অঙ্ক, বেতনে "বন্ধ করা যায় না"; ফর্ম থেকে "কত টাকা থেকে" সারিতে বসে;
 *   ১৭ঙ — শর্তের ঘর তালিকা থেকে (ভাউচারেও শাখা আছে), আর "শাখা = X" শর্ত X-এর কাগজ ধরে, অন্য শাখার নয়।
 */
final class TheRulePageSaysWhoFromWhichAmountTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $clerk;

    private User $manager;

    private ApprovalFlow $flow;

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
        app()->setLocale('bn');

        $this->clerk = $this->signer('হিসাবরক্ষক মানুষ');
        $this->manager = $this->signer('ম্যানেজার মানুষ');

        ApprovalFlow::query()->where('module', 'accounts')->where('action', 'journal')->delete();
        $this->flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $this->flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->clerk->id, 'step_name' => 'হিসাবরক্ষক']);
        ApprovalFlowStep::create(['approval_flow_id' => $this->flow->id, 'level' => 2, 'approver_type' => 'user', 'approver_id' => $this->manager->id, 'step_name' => 'ম্যানেজার', 'from_amount' => '50000']);
        ApprovalFlowStep::create(['approval_flow_id' => $this->flow->id, 'level' => 3, 'approver_type' => 'user', 'approver_id' => $this->owner->id, 'step_name' => 'মালিক', 'from_amount' => '500000']);
        ApprovalEngine::flowsChanged();
    }

    public function test_dofa17a_a_step_signs_only_from_its_amount(): void
    {
        $engine = app(ApprovalEngine::class);

        $middle = $this->ask('120000');
        $this->assertSame(['level' => 1, 'levels' => 2], $engine->standingOf($middle), '⛔ দফা ১৭: ৳১,২০,০০০-এর কাগজে ধাপ গোনা ভুল — মালিকের ধাপও গোনা হচ্ছে।');
        $engine->approve($middle->fresh(), $this->clerk);
        $this->assertSame(2, (int) $middle->fresh()->current_level, 'প্রস্তুতিটাই ভুল — ম্যানেজারের ধাপে যায়নি।');
        $engine->approve($middle->fresh(), $this->manager);
        $this->assertSame(Approval::APPROVED, $middle->fresh()->status, '⛔ দফা ১৭: ৳৫ লাখের নিচের কাগজ মালিকের কাছে গেছে — "কত টাকা থেকে" খাটেনি।');

        $big = $this->ask('600000');
        $engine->approve($big->fresh(), $this->clerk);
        $engine->approve($big->fresh(), $this->manager);
        $this->assertSame(3, (int) $big->fresh()->current_level, '⛔ দফা ১৭: ৳৬ লাখের কাগজ মালিকের ধাপে যায়নি।');

        $small = $this->ask('10000');
        $engine->approve($small->fresh(), $this->clerk);
        $this->assertSame(Approval::APPROVED, $small->fresh()->status, '⛔ দফা ১৭: ৳১০ হাজারের কাগজ ম্যানেজারের কাছে গেছে।');

        $this->assertSame(1, $engine->firstSigner('accounts', 'journal', '10000', 'Voucher')['levels'] ?? null,
            '⛔ দফা ১৭: কাগজ বানানোর আগের "ধাপ 1/N"-এর N অঙ্ক মানে না।');
    }

    public function test_dofa17b_step_one_takes_no_amount_and_one_level_one_amount(): void
    {
        $service = app(ApprovalFlowService::class);
        $base = ['module' => 'accounts', 'action' => 'journal', 'document_type' => '', 'is_active' => true];

        try {
            $service->update($this->flow, $base, [['level' => 1, 'approver_type' => 'user', 'approver_id' => $this->clerk->id, 'from_amount' => '1000']]);
            $this->fail('⛔ দফা ১৭: ধাপ ১-এ "কত টাকা থেকে" বসে গেছে — তার নিচের কাগজ কারো কাছে যেত না।');
        } catch (ValidationException $e) {
            $this->assertSame(__('approval::validation.first_level_from_amount'), $e->errors()['steps'][0] ?? null);
        }

        try {
            $service->update($this->flow, $base, [
                ['level' => 1, 'approver_type' => 'user', 'approver_id' => $this->clerk->id],
                ['level' => 2, 'approver_type' => 'user', 'approver_id' => $this->manager->id, 'from_amount' => '50000'],
                ['level' => 2, 'approver_type' => 'user', 'approver_id' => $this->owner->id, 'from_amount' => '90000'],
            ]);
            $this->fail('⛔ দফা ১৭: এক ধাপে দুই রকম "কত টাকা থেকে" বসে গেছে।');
        } catch (ValidationException $e) {
            $this->assertSame(__('approval::validation.level_two_amounts', ['level' => 2]), $e->errors()['steps'][0] ?? null);
        }
    }

    public function test_dofa17c_the_payroll_rule_stays_on_and_others_switch(): void
    {
        $payroll = ApprovalFlow::query()->where('module', 'hr')->where('action', 'payroll')->first()
            ?? ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'hr', 'action' => 'payroll', 'is_active' => true]);
        $payroll->update(['is_active' => true]);
        if ($payroll->steps()->count() === 0) {
            ApprovalFlowStep::create(['approval_flow_id' => $payroll->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner->id]);
        }

        $this->from(route('approval.flow.index'))->patch(route('approval.flow.switch', $payroll->id))
            ->assertSessionHasErrors(['flow' => __('approval::validation.payroll_stays_on')]);
        $this->assertTrue($payroll->fresh()->is_active, '⛔ দফা ১৭: বেতনের নিয়ম সুইচে বন্ধ হয়ে গেছে।');

        $this->from(route('approval.flow.index'))->delete(route('approval.flow.destroy', $payroll->id))
            ->assertSessionHasErrors(['flow' => __('approval::validation.payroll_stays_on')]);
        $this->assertNotNull($payroll->fresh(), '⛔ দফা ১৭: বেতনের নিয়ম মুছে গেছে।');

        try {
            app(ApprovalFlowService::class)->update($payroll->fresh(), ['module' => 'hr', 'action' => 'payroll', 'is_active' => false],
                [['level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner->id]]);
            $this->fail('⛔ দফা ১৭: বেতনের নিয়ম ফর্মে বন্ধ হয়ে গেছে।');
        } catch (ValidationException $e) {
            $this->assertSame(__('approval::validation.payroll_stays_on'), $e->errors()['flow'][0] ?? null);
        }

        $this->from(route('approval.flow.index'))->patch(route('approval.flow.switch', $this->flow->id))->assertSessionHasNoErrors();
        $this->assertFalse($this->flow->fresh()->is_active, '⛔ দফা ১৭: সুইচে সাধারণ নিয়ম বন্ধ হয়নি।');
        $this->from(route('approval.flow.index'))->patch(route('approval.flow.switch', $this->flow->id))->assertSessionHasNoErrors();
        $this->assertTrue($this->flow->fresh()->is_active, '⛔ দফা ১৭: সুইচে নিয়ম আবার চালু হয়নি।');
    }

    public function test_dofa17d_the_row_shows_the_chain_and_the_form_saves_the_amount(): void
    {
        $html = $this->get(route('approval.flow.index', ['q' => $this->flow->code]))->assertOk()->getContent();

        $this->assertStringContainsString('হিসাবরক্ষক → ম্যানেজার → মালিক', $html, '⛔ দফা ১৭: সারিতে ধাপের রেখা নেই।');
        $this->assertStringContainsString(e(__('approval::message.from_or_more', ['amount' => \App\Core\Support\Money::format('500000')])), $html,
            '⛔ দফা ১৭: শেষ অনুমোদনের পাশে "কত টাকা থেকে" নেই।');

        $this->put(route('approval.flow.update', $this->flow->id), [
            'module' => 'accounts', 'action' => 'journal', 'document_type' => '', 'is_active' => '1',
            'steps' => [
                ['level' => 1, 'approver' => 'user|'.$this->clerk->id, 'from_amount' => ''],
                ['level' => 2, 'approver' => 'user|'.$this->manager->id, 'from_amount' => '75000'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame('75000.0000', (string) ApprovalFlowStep::query()->where('approval_flow_id', $this->flow->id)->where('level', 2)->value('from_amount'),
            '⛔ দফা ১৭: ফর্মের "কত টাকা থেকে" সারিতে বসেনি।');
        $this->assertNull(ApprovalFlowStep::query()->where('approval_flow_id', $this->flow->id)->where('level', 1)->value('from_amount'));
    }

    public function test_dofa17d_the_payroll_row_says_it_cannot_be_switched_off(): void
    {
        $payroll = ApprovalFlow::query()->where('module', 'hr')->where('action', 'payroll')->first()
            ?? ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'hr', 'action' => 'payroll', 'is_active' => true]);
        $payroll->update(['is_active' => true]);

        $html = $this->get(route('approval.flow.index', ['q' => $payroll->code]))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('approval::message.payroll_locked')), $html, '⛔ দফা ১৭: বেতনের সারিতে "বন্ধ করা যায় না" নেই।');
        $this->assertStringNotContainsString(route('approval.flow.switch', $payroll->id), $html, '⛔ দফা ১৭: বেতনের সারিতে চাপার মতো সুইচ রয়ে গেছে।');
    }

    public function test_dofa17e_a_branch_condition_is_picked_and_catches_only_its_branch(): void
    {
        $other = Branch::query()->create([
            'company_id' => $this->company->id, 'code' => 'P3B', 'name_en' => 'Third branch', 'name_bn' => 'তৃতীয় শাখা', 'is_active' => true,
        ]);

        $this->ask('1000');
        $fields = $this->get(route('approval.flow.edit', $this->flow->id))->assertOk()->viewData('fields');
        $this->assertArrayHasKey('branch_id', $fields['accounts|journal'] ?? [], '⛔ দফা ১৭: শর্তের তালিকায় শাখা নেই — ভাউচারের ঘরগুলো আসেনি।');

        ApprovalCondition::create(['approval_flow_id' => $this->flow->id, 'field' => 'branch_id', 'operator' => '=', 'value' => (string) $other->id]);
        ApprovalEngine::flowsChanged();

        $engine = app(ApprovalEngine::class);
        $this->assertTrue($engine->requires('accounts', 'journal', '1000', 'Voucher', ApprovalEngine::fieldsOf($this->draft('1000', $other->id))),
            '⛔ দফা ১৭: "শাখা = তৃতীয়" শর্ত তৃতীয় শাখার কাগজ ধরেনি — শাখার ঘর কাগজ থেকে আসে না।');
        $this->assertFalse($engine->requires('accounts', 'journal', '1000', 'Voucher', ApprovalEngine::fieldsOf($this->draft('1000'))),
            '⛔ দফা ১৭: "শাখা = তৃতীয়" শর্ত অন্য শাখার কাগজও ধরেছে।');
    }

    private function signer(string $name): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'bn', 'name' => $name]);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id])->save();
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), ['approval.view', 'approval.decide'])));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function draft(string $amount, ?int $branch = null): Voucher
    {
        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();

        return app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'ভাগ ৩-এর পরীক্ষা', 'branch_id' => $branch ?? CompanyContext::branchId()],
            [['account_id' => $a->id, 'debit' => $amount, 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => $amount]],
        );
    }

    private function ask(string $amount): Approval
    {
        $this->actingAs($this->owner);
        $asked = app(VoucherWriter::class)->post($this->draft($amount));
        $this->assertNotNull($asked, 'প্রস্তুতিটাই ভুল — অনুমোদন চাওয়া হয়নি।');

        return $asked->fresh();
    }
}
