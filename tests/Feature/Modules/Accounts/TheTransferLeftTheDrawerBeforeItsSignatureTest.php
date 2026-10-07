<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\MoneyTransferService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Approval\Services\ApprovalFlowService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ম৬ (ক) — স্থানান্তরের সই হস্তান্তরের আগে (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬; ৬৩-এর সিদ্ধান্ত)।
 *
 * ⛔ আগে পাঠানোর পা সাথে সাথে খাতায় বসত, আর সই চাওয়া হত গ্রহণে — টাকা হাতবদল হয়ে যেত, সই পরে। ⓘ এখন ছক চালু থাকলে
 * কাগজ সইয়ের অপেক্ষায়, খাতায় কিছু নেই; শেষ সইয়ে পাঠানোর পা বসে। আর অপেক্ষার টাকা "পাওয়া যায়" থেকে বাদ — নইলে একই
 * টাকা দুবার পাঠানোর ফাঁক ফিরত।
 */
final class TheTransferLeftTheDrawerBeforeItsSignatureTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private User $owner;

    private User $signer;

    private CashTill $from;

    private CashTill $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->signer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->from = app(CashTillService::class)->create(['name_en' => 'M6 counter', 'holder_id' => $this->owner->id]);
        $this->to = app(CashTillService::class)->create(['name_en' => 'M6 safe', 'holder_id' => $this->owner->id]);
        $this->putMoneyIn($this->from->account, '1000');
    }

    /** ⓘ ছক বন্ধে আজকের মতোই — পাঠানোর পা এখনই, কাগজ গ্রহণের অপেক্ষায় */
    public function test_without_a_flow_the_money_goes_on_its_way_at_once_as_today(): void
    {
        $transfer = $this->send('600');

        $this->assertSame(DocumentStatus::DRAFT, $transfer->status);
        $this->assertSame(0, bccomp($this->from->fresh()->balance(), '400', 4));
        $this->assertGreaterThan(0, $this->legRows($transfer));
    }

    public function test_with_a_flow_nothing_moves_until_the_last_signature(): void
    {
        $this->flow();
        $transfer = $this->send('600');

        $this->assertTrue($transfer->isAwaiting(), '⛔ ছক চালু, তবু স্থানান্তর সইয়ের অপেক্ষা করল না।');
        $this->assertSame(0, $this->legRows($transfer), '⛔ সইয়ের আগেই পাঠানোর পা খাতায় বসল।');
        $this->assertSame(0, bccomp($this->from->fresh()->balance(), '1000', 4));

        $this->assertRefused(fn () => app(MoneyTransferService::class)->confirm($transfer->fresh()), 'status',
            '⛔ সইয়ের আগেই গ্রহণ হলো — টাকা হাতবদলই হয়নি।');

        app(ApprovalEngine::class)->approve($this->approvalOf($transfer), $this->signer);

        $transfer->refresh();
        $this->assertSame(DocumentStatus::DRAFT, $transfer->status, '⛔ শেষ সইয়ের পরে টাকা পথে ওঠেনি।');
        $this->assertSame(0, bccomp($this->from->fresh()->balance(), '400', 4));

        app(MoneyTransferService::class)->confirm($transfer);
        $this->assertSame(0, bccomp($this->to->fresh()->balance(), '600', 4), 'সইয়ের পরে গ্রহণ চলেনি।');
    }

    public function test_money_waiting_for_a_signature_cannot_be_sent_twice(): void
    {
        $this->flow();
        $this->send('600');

        $this->assertRefused(fn () => $this->send('600'), 'amount',
            '⛔ সইয়ের অপেক্ষার ৬০০ গোনা হলো না — একই টাকা দ্বিতীয়বার পাঠানো গেল।');

        // ⓘ বাকি ৪০০ পাঠানো যায়
        $this->assertTrue($this->send('400')->isAwaiting());
        $this->assertRefused(fn () => $this->send('1'), 'amount', '⛔ টিলের সব টাকাই অপেক্ষায়, তবু আরও পাঠানো গেল।');
    }

    public function test_a_signature_after_the_money_was_spent_does_not_take_the_till_below_zero(): void
    {
        $this->flow();
        $transfer = $this->send('600');
        $this->spend('500');

        try {
            app(ApprovalEngine::class)->approve($this->approvalOf($transfer), $this->signer);
        } catch (ValidationException) {
            // ⓘ থামাটাই ঠিক — নিচে মাপা হয় যে কিছুই নড়েনি
        }

        $this->assertTrue($transfer->fresh()->isAwaiting(), '⛔ টাকা খরচের পরেও সইয়ে টাকা পথে উঠল।');
        $this->assertSame(0, $this->legRows($transfer));
        $this->assertSame(0, bccomp($this->from->fresh()->balance(), '500', 4));
    }

    public function test_a_transfer_waiting_for_its_signature_is_cancelled_without_any_reversal(): void
    {
        $this->flow();
        $transfer = $this->send('600');

        app(MoneyTransferService::class)->cancel($transfer->fresh(), 'ভুল বাক্স');

        $this->assertSame(DocumentStatus::CANCELLED, $transfer->fresh()->status);
        $this->assertSame(0, $this->legRows($transfer));
        $this->assertSame(0, bccomp($this->from->fresh()->balance(), '1000', 4));

        // ⓘ বাতিলের পরে টাকাটা আবার পাঠানো যায়
        $this->assertTrue($this->send('1000')->isAwaiting());
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function flow(): void
    {
        app(ApprovalFlowService::class)->create(
            ['module' => 'accounts', 'action' => 'transfer', 'is_active' => true],
            [['level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->signer->id, 'requires_all' => false]],
        );
    }

    private function send(string $amount): MoneyTransfer
    {
        return app(MoneyTransferService::class)->initiate([
            'from_till_id' => $this->from->id, 'to_till_id' => $this->to->id,
            'amount' => $amount, 'trx_date' => now()->toDateString(),
        ]);
    }

    private function spend(string $amount): void
    {
        app(PostingEngine::class)->post(
            sourceType: 'test:spend',
            sourceId: random_int(1, PHP_INT_MAX),
            trxDate: now()->toDateString(),
            lines: [
                ['account_id' => StandardChart::find(StandardChart::ENTERTAINMENT)->id, 'debit' => $amount, 'narration' => 'খরচ'],
                ['account_id' => $this->from->account_id, 'credit' => $amount, 'narration' => 'খরচ'],
            ],
        );
    }

    private function approvalOf(MoneyTransfer $transfer): Approval
    {
        return Approval::query()->where('approvable_type', $transfer->getMorphClass())->where('approvable_id', $transfer->id)
            ->latest('id')->firstOrFail();
    }

    private function legRows(MoneyTransfer $transfer): int
    {
        return LedgerEntry::query()->where('document_no', $transfer->document_no)->count();
    }

    private function assertRefused(\Closure $act, string $field, string $why): void
    {
        try {
            $act();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), 'আটকেছে, কিন্তু অন্য কারণে: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->fail($why);
    }
}
