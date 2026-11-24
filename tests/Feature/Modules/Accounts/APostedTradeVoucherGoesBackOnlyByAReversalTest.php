<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\NoteLine;
use App\Modules\Accounts\Models\Reversal;
use App\Modules\Accounts\Models\TradeVoucher;
use App\Modules\Accounts\Services\TradeVoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\WritesNotesAgainstBills;
use Tests\TestCase;

/**
 * ⭐ পোস্ট হওয়া SV/PUV কেবল উল্টো কাগজে ফেরে — ২৪ নভেম্বর ২০২৬ ([[\App\Modules\Accounts\Services\AccountsReversalService]])।
 *
 * দাবি: নিজের REV নম্বর আর আজকের তারিখ, খাতা শূন্যে, মূল কাগজ থাকে চিহ্নসহ; ছক থাকলে আগে অনুমোদন, পোস্টের পুরনো অনুমোদন চলে না;
 * কারণ ছাড়া, বন্ধ মাসে, দ্বিতীয়বার আর সরাসরি দরজায় থামে; খসড়া আগের মতো সরাসরি বাতিল।
 */
final class APostedTradeVoucherGoesBackOnlyByAReversalTest extends TestCase
{
    use RefreshDatabase;
    use WritesNotesAgainstBills;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTheNoteScene();
        $settings = app(SettingsService::class);
        $settings->set('accounts.stock_free_vouchers', true);
        $settings->flush();
        ApprovalFlow::query()->where('module', 'accounts')->whereIn('action', ['sales_voucher', 'purchase_voucher'])->delete();
        ApprovalEngine::flowsChanged();
    }

    public function test_it_gets_its_own_number_and_the_books_go_to_zero(): void
    {
        $voucher = $this->posted();

        $this->cancel($voucher, '')->assertSessionHasErrors('cancel_reason');
        $this->cancel($voucher, 'ভুল গ্রাহক')->assertSessionHasNoErrors()->assertSessionHas('saved');

        $paper = Reversal::of(Reversal::TRADE_VOUCHER, (int) $voucher->id);
        $this->assertNotNull($paper, '⛔ উল্টো কাগজ হয়নি।');
        $this->assertStringStartsWith('REV-', $paper->document_no);
        $this->assertSame(now()->toDateString(), $paper->trx_date->toDateString());
        $this->assertTrue($voucher->fresh()->isCancelled());

        $rows = LedgerEntry::query()->whereIn('source_type', ['sales_voucher', 'sales_voucher:reversal'])->where('source_id', $voucher->id)->get();
        $this->assertCount(4, $rows, 'প্রস্তুতিটাই ভুল — দুই সারি আর তাদের উল্টো নেই।');
        foreach ($rows->groupBy('account_id') as $of) {
            $this->assertMoney((string) $of->sum('debit'), (string) $of->sum('credit'), '⛔ খাতা শূন্যে ফেরেনি।');
        }
        $this->assertSame([$paper->document_no], $rows->where('source_type', 'sales_voucher:reversal')->pluck('document_no')->unique()->values()->all());

        $this->get(route('accounts.trade_voucher.show', ['kind' => 'sale', 'voucher' => $voucher->id]))->assertOk()
            ->assertSee($voucher->document_no)->assertSee($paper->document_no)->assertSee('ভুল গ্রাহক');

        $this->cancel($voucher, 'আবার');
        $this->assertSame(1, Reversal::query()->count(), '⛔ দ্বিতীয়বারে দ্বিতীয় কাগজ।');
    }

    public function test_a_flow_asks_first_and_the_posting_approval_is_not_enough(): void
    {
        $signer = User::factory()->create(['is_active' => true]);
        $signer->companies()->attach($this->company->id, ['is_active' => true]);
        $voucher = $this->draft();
        Approval::query()->create(['company_id' => $this->company->id, 'approvable_type' => TradeVoucher::class, 'approvable_id' => $voucher->id,
            'module' => 'accounts', 'action' => 'sales_voucher', 'amount' => $voucher->total, 'status' => Approval::APPROVED,
            'current_level' => 1, 'requested_by' => $this->owner->id, 'requested_at' => now(), 'decided_at' => now()]);
        app(TradeVoucherService::class)->confirm($voucher);

        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => 'accounts', 'action' => 'sales_voucher', 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id]);
        ApprovalEngine::flowsChanged();

        $this->cancel($voucher, 'ভুল দাম')->assertSessionHasNoErrors();
        $this->assertTrue($voucher->fresh()->isConfirmed(), '⛔ অনুমোদনের আগেই উল্টে গেছে।');
        $this->assertSame(0, Reversal::query()->count());

        $asked = Approval::query()->where('approvable_id', $voucher->id)->where('status', Approval::PENDING)->firstOrFail();
        $this->cancel($voucher, 'আবার চাপা');
        $this->assertSame(1, Approval::query()->where('approvable_id', $voucher->id)->where('status', Approval::PENDING)->count());

        app(ApprovalEngine::class)->approve($asked, $signer);

        $paper = Reversal::of(Reversal::TRADE_VOUCHER, (int) $voucher->id);
        $this->assertNotNull($paper, '⛔ অনুমোদনের পরেও উল্টো কাগজ নেই।');
        $this->assertSame('ভুল দাম', $paper->reason);
        $this->assertSame((int) $this->owner->id, (int) $paper->created_by, '⛔ যিনি চেয়েছিলেন তাঁর নামে নয়।');
        $this->assertTrue($voucher->fresh()->isCancelled());
    }

    public function test_a_closed_month_is_refused_and_asks_nobody(): void
    {
        $this->travelTo(now()->subMonthNoOverflow());
        $voucher = $this->posted();
        $this->travelBack();
        PeriodLock::query()->create(['company_id' => $this->company->id, 'year' => (int) $voucher->trx_date->year,
            'month' => (int) $voucher->trx_date->month, 'reason' => 'মাস বন্ধ', 'locked_by' => $this->owner->id, 'locked_at' => now()]);

        $this->cancel($voucher, 'চেষ্টা')->assertSessionHasErrors('cancel_reason');
        $this->assertTrue($voucher->fresh()->isConfirmed());
        $this->assertSame(0, Reversal::query()->count());
        $this->assertSame(0, Approval::query()->where('approvable_type', TradeVoucher::class)->where('approvable_id', $voucher->id)->count());
    }

    public function test_the_service_door_refuses_a_posted_one_even_from_a_stale_copy_and_a_draft_is_simply_cancelled(): void
    {
        $stale = $this->draft();
        app(TradeVoucherService::class)->confirm($stale->fresh());
        $this->assertTrue($stale->isDraft(), 'প্রস্তুতিটাই ভুল — হাতের কপি পুরনো নয়।');

        foreach ([$stale, $stale->fresh()] as $copy) {
            try {
                app(TradeVoucherService::class)->cancel($copy, 'x');
                $this->fail('⛔ উল্টো কাগজ ছাড়াই পোস্ট হওয়া ভাউচার বাতিল হলো।');
            } catch (ValidationException) {
                $this->assertTrue($stale->fresh()->isConfirmed());
            }
        }

        $draft = $this->draft();
        $this->cancel($draft, 'লাগবে না')->assertSessionHasNoErrors();
        $this->assertTrue($draft->fresh()->isCancelled());
        $this->assertNull(Reversal::of(Reversal::TRADE_VOUCHER, (int) $draft->id));
    }

    private function draft(): TradeVoucher
    {
        return app(TradeVoucherService::class)->create(TradeVoucher::SALE, [
            'party_id' => $this->customer->id, 'narration' => 'x', 'trx_date' => now()->toDateString(),
            'lines' => [['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId('4300'), 'amount' => '500']],
        ]);
    }

    private function posted(): TradeVoucher
    {
        $voucher = app(TradeVoucherService::class)->confirm($this->draft());
        $this->assertTrue($voucher->isConfirmed(), 'প্রস্তুতিটাই ভুল — পোস্ট হয়নি।');

        return $voucher;
    }

    private function cancel(TradeVoucher $voucher, string $reason): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('accounts.trade_voucher.cancel', ['kind' => $voucher->kind, 'voucher' => $voucher->id]), ['cancel_reason' => $reason]);
    }
}
