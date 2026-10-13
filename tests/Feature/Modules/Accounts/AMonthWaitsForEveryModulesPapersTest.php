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
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⛔ মাস বন্ধ হয় না যতক্ষণ কোনো মডিউলের কাগজ মাঝপথে — Accounts লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⓘ মাস-শেষের তালিকা কেবল ভাউচার গুনত। ২৮ আগস্টের ক্রয় বিল সইয়ের অপেক্ষায় থাকতে আগস্ট বন্ধ হত, আর শেষ সইয়ে পোস্ট "মাস বন্ধ"
 * বলে থামত — কাগজ চিরকাল আটকে। নিশ্চিত হয়েও খাতায় না-ওঠা কাগজেরও একই দশা। বছর বন্ধ এই দুটো আগেই গোনে।
 */
final class AMonthWaitsForEveryModulesPapersTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Carbon $month;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->month = now()->subMonthNoOverflow()->startOfMonth();

        // ⓘ আগের মাসগুলো বন্ধ, আর ডেমোর খসড়া বা অপেক্ষা সরানো — দৃশ্যটা কেবল এই পরীক্ষার কাগজ
        for ($c = Carbon::parse(FinancialYear::forDate($this->month)->starts_on)->startOfMonth(); $c->lt($this->month); $c->addMonthNoOverflow()) {
            PeriodLock::query()->create(['company_id' => $this->company->id, 'year' => (int) $c->year, 'month' => (int) $c->month,
                'locked_by' => $this->owner->id, 'locked_at' => now()]);
        }
        Voucher::acrossBranches()->where('status', DocumentStatus::DRAFT)->update(['status' => DocumentStatus::CANCELLED]);
        Approval::query()->where('status', Approval::PENDING)->update(['status' => Approval::REJECTED]);
    }

    public function test_a_purchase_bill_awaiting_its_signature_keeps_the_month_open(): void
    {
        $bill = $this->bill('PB-MON-WAIT', DocumentStatus::DRAFT);
        Approval::query()->create([
            'company_id' => $this->company->id, 'approvable_type' => PurchaseBill::class, 'approvable_id' => $bill->id,
            'module' => 'purchase', 'action' => 'bill', 'amount' => '1500', 'status' => Approval::PENDING,
            'requested_by' => $this->owner->id, 'requested_at' => now(),
        ]);

        $this->close()->assertSessionHasErrors(['month' => __('accounts::validation.month_has_open_papers', ['drafts' => 0, 'awaiting' => 1])]);
        $this->assertNull($this->lock(), '⛔ সইয়ের অপেক্ষার ক্রয় বিল রেখে মাস বন্ধ হল');
    }

    public function test_a_confirmed_paper_outside_the_books_keeps_the_month_open(): void
    {
        $this->bill('PB-MON-UNPOSTED', DocumentStatus::CONFIRMED);

        $this->close()->assertSessionHasErrors(['month' => __('accounts::validation.month_has_unposted', ['count' => 1])]);
        $this->assertNull($this->lock(), '⛔ খাতায় না-ওঠা নিশ্চিত বিল রেখে মাস বন্ধ হল');
    }

    private function bill(string $no, string $status): PurchaseBill
    {
        return PurchaseBill::query()->create(['company_id' => $this->company->id, 'document_no' => $no,
            'supplier_id' => Supplier::query()->firstOrFail()->id, 'trx_date' => $this->month->copy()->addDays(5)->toDateString(),
            'status' => $status, 'total' => '1500']);
    }

    private function close(): \Illuminate\Testing\TestResponse
    {
        return $this->from(route('accounts.period.index'))
            ->post(route('accounts.period.close'), ['year' => $this->month->year, 'month' => $this->month->month]);
    }

    private function lock(): ?PeriodLock
    {
        return PeriodLock::query()->where('year', $this->month->year)->where('month', $this->month->month)->first();
    }
}
