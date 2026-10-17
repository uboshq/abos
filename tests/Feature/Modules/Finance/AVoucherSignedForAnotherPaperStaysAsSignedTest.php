<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\SignedPaperFinisher;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherWriter;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ অর্থের কাগজের সই যে ভাউচার দেখে দেওয়া, খাতায় ঠিক সেটাই — হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⛔ আগে: হাতধার ১,০০০ টাকা সইয়ের অপেক্ষায় (`finance` ছক, সই ভাউচারের উপরে)। সাধারণ ভাউচারের পাতা কেবল "খসড়া কি না" দেখত —
 * সেখান থেকে অঙ্ক বা খাত বদলানো যেত, আর শেষ সই পোস্ট করত বদলানোটা ([[FinishTheFinancePaperOnTheLastSignature]] কিছু
 * মেলাত না); বা ভাউচারের পাতা থেকেই পাকা করে হাতধারের সইটাই এড়ানো যেত।
 *
 * দাবি:
 *  - সইয়ের অপেক্ষায় ভাউচারের পাতা থেকে বদলানো বা পাকা করা যায় না — কারণসহ;
 *  - তবু কোনো পথে ভাউচারটা বদলে গেলে শেষ সই সেটা পোস্ট করে না, লেখক কারণসহ খবর পান;
 *  - বদলহীন ভাউচার শেষ সইয়ে আগের মতোই পাকা।
 */
final class AVoucherSignedForAnotherPaperStaysAsSignedTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
        $this->putMoneyIn($this->cash(), '1000000', now()->subDays(5)->toDateString());

        $this->signer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $flow = ApprovalFlow::query()->create(['module' => 'finance', 'action' => 'hand_loan', 'is_active' => true]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->signer->id,
        ]);
        $this->app->forgetInstance(ApprovalEngine::class);
    }

    public function test_the_voucher_page_cannot_change_or_post_it_while_it_waits(): void
    {
        [$voucher] = $this->waiting();

        $lines = $voucher->lines->map(fn ($l) => ['account_id' => $l->account_id,
            'debit' => bccomp((string) $l->debit, '0', 4) > 0 ? '9000' : '0', 'credit' => bccomp((string) $l->credit, '0', 4) > 0 ? '9000' : '0'])->all();

        $this->assertRefused(fn () => app(VoucherWriter::class)->update($voucher, ['amount' => '9000'], $lines, asDraft: true), 'বদলানো', $voucher);
        $this->assertSame(0, bccomp('1000', (string) $voucher->fresh()->amount, 4), '⛔ থামার পরেও ভাউচারের অঙ্ক বদলেছে।');

        $this->assertRefused(fn () => app(VoucherWriter::class)->post($voucher->fresh()), 'পাকা করা', $voucher);
        $this->assertSame(DocumentStatus::DRAFT, $voucher->fresh()->status, '⛔ হাতধারের সই ছাড়াই ভাউচারের পাতা থেকে পাকা হলো।');
    }

    public function test_a_voucher_changed_anyway_is_not_posted_on_the_last_signature(): void
    {
        [$voucher, $approval] = $this->waiting();

        // ⓘ কোনো পথে (আজ বন্ধ, আগের বদল, বা কাল নতুন কোনো দরজা) সারির অঙ্ক বদলে গেছে
        DB::table('voucher_lines')->where('voucher_id', $voucher->id)->where('debit', '>', 0)->update(['debit' => '9000']);
        DB::table('voucher_lines')->where('voucher_id', $voucher->id)->where('credit', '>', 0)->update(['credit' => '9000']);

        $this->actingAs($this->signer);
        app(ApprovalEngine::class)->approve($approval->fresh(), $this->signer);

        $this->assertSame(DocumentStatus::DRAFT, Voucher::acrossBranches()->findOrFail($voucher->id)->status,
            '⛔ ১,০০০ টাকার হাতধারের সই ৯,০০০ টাকার ভাউচার খাতায় তুলল।');
        $this->assertTrue(DB::table('notifications')->where('user_id', $this->owner->id)->where('type', SignedPaperFinisher::NOTICE)->exists(),
            '⛔ সই-হওয়া কাগজ পোস্ট হলো না, অথচ লেখক কোনো খবর পেলেন না।');
    }

    public function test_an_unchanged_voucher_posts_on_its_signature(): void
    {
        [$voucher, $approval] = $this->waiting();

        $this->actingAs($this->signer);
        app(ApprovalEngine::class)->approve($approval->fresh(), $this->signer);

        $this->assertSame(DocumentStatus::CONFIRMED, Voucher::acrossBranches()->findOrFail($voucher->id)->status,
            '⛔ বদলহীন ভাউচার শেষ সইয়েও পাকা হলো না।');
    }

    /** @return array{0: Voucher, 1: Approval} হাতধার ১,০০০ — সইয়ের অপেক্ষায় */
    private function waiting(): array
    {
        $person = Person::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'name_en' => 'Signed Karim'],
            ['code' => 'P-SK1', 'name_bn' => 'Signed Karim', 'is_active' => true],
        );
        $account = app(HandLoanService::class)->open(['person_id' => $person->id]);
        $movement = app(HandLoanService::class)->move($account->fresh(), [
            'direction' => 'out', 'amount' => '1000', 'moved_on' => now()->toDateString(), 'money_account_id' => $this->cash()->id,
        ]);

        $voucher = Voucher::acrossBranches()->with('lines')->findOrFail($movement->voucher_id);
        $this->assertSame(DocumentStatus::DRAFT, $voucher->status, 'প্রস্তুতি — হাতধার সইয়ের অপেক্ষায় নেই');

        $approval = Approval::query()->where('approvable_type', Voucher::class)->where('approvable_id', $voucher->id)
            ->where('status', Approval::PENDING)->firstOrFail();

        return [$voucher, $approval];
    }

    private function assertRefused(callable $what, string $doing, Voucher $voucher): void
    {
        try {
            $what();
            $this->fail("⛔ অন্য কাগজের সইয়ের অপেক্ষার ভাউচার ভাউচারের পাতা থেকে {$doing} গেল।");
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::validation.signed_elsewhere', ['no' => $voucher->document_no]), $e->errors()['status'][0] ?? null,
                "{$doing}: অন্য কারণে থামল — ".json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();
    }
}
