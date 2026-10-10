<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ⛔ যেকোনো মাস তালাবন্ধ করা যেত — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️৯)।
 *
 * ⓘ [[PeriodLockController::close()]] কেবল চলতি মাস আটকাত। আগামী মাস বন্ধ হলে সেই মাস এলে প্রথম বিলেই সব থামত; আর খসড়া বা
 * সইয়ের অপেক্ষার কাগজ থাকা মাস বন্ধ হলে সেগুলো আর কখনো খাতায় উঠতে পারত না। এখন দুইটাই থামে, মাস-শেষের তালিকা মেপে।
 */
final class AMonthLocksOnlyWhenItIsDoneTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_a_month_that_has_not_come_cannot_be_locked(): void
    {
        $next = now()->addMonthNoOverflow();

        $this->lock($next->year, $next->month)->assertSessionHasErrors(['month' => __('accounts::validation.cannot_close_future_month')]);
        $this->assertSame(0, PeriodLock::query()->where('year', $next->year)->where('month', $next->month)->count(), '⛔ আগামী মাস তালাবন্ধ হল');
    }

    public function test_a_month_with_a_draft_or_an_unsigned_voucher_waits(): void
    {
        $last = now()->subMonthNoOverflow()->startOfMonth();
        $this->clearMonth($last);
        $this->lockEarlierMonths($last);

        $draft = $this->voucher($last->copy()->addDays(3)->toDateString());

        $this->lock($last->year, $last->month)->assertSessionHasErrors(['month' => __('accounts::validation.month_has_open_papers', ['drafts' => 1, 'awaiting' => 0])]);
        $this->assertSame(0, PeriodLock::query()->where('year', $last->year)->where('month', $last->month)->count(), '⛔ খসড়াসহ মাস তালাবন্ধ হল');

        // ⓘ খসড়া নয়, কিন্তু সইয়ের অপেক্ষায়
        $draft->forceFill(['status' => DocumentStatus::CONFIRMED])->save();
        Approval::query()->create([
            'company_id' => $this->company->id, 'approvable_type' => Voucher::class, 'approvable_id' => $draft->id,
            'module' => 'accounts', 'action' => 'journal', 'amount' => '250', 'status' => Approval::PENDING,
            'requested_by' => $this->owner->id, 'requested_at' => now(),
        ]);

        $this->lock($last->year, $last->month)->assertSessionHasErrors(['month' => __('accounts::validation.month_has_open_papers', ['drafts' => 0, 'awaiting' => 1])]);

        // ⓘ কাজ শেষ — তালা বসে
        Approval::query()->update(['status' => Approval::APPROVED]);
        $this->lock($last->year, $last->month)->assertSessionHasNoErrors();
        $this->assertSame(1, PeriodLock::query()->where('year', $last->year)->where('month', $last->month)->count(), 'কাজ শেষ মাস তালাবন্ধ হওয়ার কথা');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function lock(int $year, int $month): TestResponse
    {
        return $this->from(route('accounts.period.index'))->post(route('accounts.period.close'), ['year' => $year, 'month' => $month]);
    }

    /** ⓘ বছরের আগের মাসগুলো আগে বন্ধ — ক্রম মেনে ([[AnEarlierMonthStayedOpenTest]], পুনঃঅডিট ৯ অক্টোবর ২০২৬) */
    private function lockEarlierMonths(Carbon $month): void
    {
        $cursor = Carbon::parse(FinancialYear::forDate($month)->starts_on)->startOfMonth();

        for (; $cursor->lt($month); $cursor->addMonthNoOverflow()) {
            PeriodLock::query()->create([
                'company_id' => $this->company->id, 'year' => (int) $cursor->year, 'month' => (int) $cursor->month,
                'locked_by' => $this->owner->id, 'locked_at' => now(),
            ]);
        }
    }

    /** ⓘ ডেমোর আগের খসড়া বা অপেক্ষা থাকলে সরানো — দৃশ্যটা কেবল এই পরীক্ষার কাগজ */
    private function clearMonth(Carbon $month): void
    {
        Voucher::acrossBranches()->where('status', DocumentStatus::DRAFT)
            ->whereBetween('trx_date', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->update(['status' => DocumentStatus::CANCELLED]);
        Approval::query()->where('approvable_type', Voucher::class)->where('status', Approval::PENDING)->update(['status' => Approval::REJECTED]);
    }

    private function voucher(string $on): Voucher
    {
        return app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $on, 'narration' => 'খসড়া'],
            [
                ['account_id' => Account::query()->where('code', '5202')->value('id'), 'debit' => '250'],
                ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'credit' => '250'],
            ],
        );
    }
}
