<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AdjustingReversals;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * আসল সমন্বয় জাবেদা বাতিল করলে তার নিজে-বসা উল্টোটা খাতায় থেকে যেত — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (হিসাব, ঠিক ১)।
 *
 * ⛔ আগে: বকেয়া আয় ৩০০ বসল → তারিখে নিজে উল্টাল → আসলটা বাতিল। আসলটা ফিরল, উল্টোটা রয়ে গেল — দুই খাতে উল্টো দিকে ৩০০।
 * ⭐ এখন আসলটার বাতিলে উল্টোটাও বাতিল হয়; দুই খাতের মোট ফল শূন্য ([[VoucherService::cancelItsAutoReversal()]])।
 */
final class CancellingAnAdjustingJournalLeftItsReversalBehindTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_cancelling_the_original_also_cancels_its_posted_reversal_and_nets_to_zero(): void
    {
        [$receivable, $income] = [$this->leaf(StandardChart::RECEIVABLE), StandardChart::find(StandardChart::RENT_INCOME)];
        $before = [$this->balance($receivable), $this->balance($income)];

        $original = $this->adjusting($receivable, $income);
        $this->assertSame(1, app(AdjustingReversals::class)->run()['reversed']);
        $reversal = Voucher::query()->where('reversal_of_id', $original->id)->sole();
        $this->assertTrue($reversal->isPosted());

        app(VoucherService::class)->cancel($original->fresh(), 'ভুল অঙ্ক');

        $this->assertSame(DocumentStatus::CANCELLED, $original->fresh()->status);
        $this->assertSame(DocumentStatus::CANCELLED, $reversal->fresh()->status, '⛔ আসলটা বাতিল, কিন্তু নিজে-বসা উল্টোটা খাতায় রয়ে গেল।');
        $this->assertSame($before, [$this->balance($receivable), $this->balance($income)], '⛔ বাতিলের পরে দুই খাতের মোট ফল শূন্য নয়।');

        // ⓘ বাতিল আসলটা আর কখনো নিজে উল্টায় না
        $this->assertSame(0, app(AdjustingReversals::class)->run()['reversed']);
    }

    public function test_a_locked_month_refuses_both_together(): void
    {
        [$receivable, $income] = [$this->leaf(StandardChart::RECEIVABLE), StandardChart::find(StandardChart::RENT_INCOME)];
        $original = $this->adjusting($receivable, $income);
        app(AdjustingReversals::class)->run();
        $reversal = Voucher::query()->where('reversal_of_id', $original->id)->sole();

        PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => (int) now()->year, 'month' => (int) now()->month,
            'reason' => 'মাস বন্ধ', 'locked_by' => auth()->id(), 'locked_at' => now(),
        ]);

        try {
            app(VoucherService::class)->cancel($original->fresh(), 'ভুল অঙ্ক');
            $this->fail('⛔ বন্ধ মাসে বাতিল হয়ে গেল।');
        } catch (ValidationException) {
        }

        // ⓘ একসাথে, নয়তো কোনোটাই নয়
        $this->assertSame(DocumentStatus::CONFIRMED, $original->fresh()->status);
        $this->assertSame(DocumentStatus::CONFIRMED, $reversal->fresh()->status);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function adjusting(Account $dr, Account $cr): Voucher
    {
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->subDays(2)->toDateString(), 'reverse_on' => now()->subDay()->toDateString(),
                'narration' => 'মাসশেষের বকেয়া আয়', 'is_adjusting' => true],
            [['account_id' => $dr->id, 'debit' => '300', 'credit' => '0', 'party_type' => 'customer', 'party_id' => Customer::query()->orderBy('id')->value('id')],
                ['account_id' => $cr->id, 'debit' => '0', 'credit' => '300']],
        );
        app(VoucherService::class)->post($voucher);

        return $voucher->fresh(['lines']);
    }

    private function balance(Account $account): string
    {
        $row = LedgerEntry::query()->where('account_id', $account->id)
            ->selectRaw('COALESCE(SUM(debit), 0) AS dr, COALESCE(SUM(credit), 0) AS cr')->first();

        return bcsub((string) $row->dr, (string) $row->cr, 4);
    }

    private function leaf(string $code): Account
    {
        $root = StandardChart::find($code);

        return $root->is_group
            ? Account::query()->postable()->whereKey($root->selfAndDescendants()->pluck('id'))->orderBy('code')->firstOrFail()
            : $root;
    }
}
