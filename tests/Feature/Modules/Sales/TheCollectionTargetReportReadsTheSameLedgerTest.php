<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DealerScope;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Reports\CollectionTargetReports;
use App\Modules\Sales\Services\CustomerTargetService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⭐ আদায়ের লক্ষ্য বনাম অর্জন — বিক্রয় পরিকল্পনা সংস্করণ ২ §৯ (ঙ) ([[CollectionTargetReports]])।
 *
 * ⛔ অর্জন বিলের বাক্সের হুবহু ([[CustomerTargetService::reminderFor()]]): আদায় আর রসিদ, ফেরত মাল নয়, ফেরত চেক বাদ, পাশ না
 * হওয়া চেক বাদ; লক্ষ্যের বেশি হলে বাকি শূন্য; লক্ষ্যহীন ডিলার সারিতে নেই; সারাংশ মোট অর্জন ÷ মোট লক্ষ্য; বিক্রয়কর্মী শেষ তারিখে
 * বাঁধা জন; দেয়ালের ভিতরের বিক্রয়কর্মী কেবল নিজের ডিলার।
 */
final class TheCollectionTargetReportReadsTheSameLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-03 11:00:00'));

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        app(StandardChart::class)->install();
    }

    public function test_the_report_counts_exactly_what_the_bill_reminder_counts(): void
    {
        $short = $this->dealer('TGT-A', 'Short Dealer');
        $over = $this->dealer('TGT-B', 'Over Dealer');
        $none = $this->dealer('TGT-C', 'No Target Dealer');
        $bounce = $this->dealer('TGT-D', 'Bounced Dealer');

        // ⓘ A মাসের মাঝে হাতবদল: আগের জন ৯ অক্টোবর পর্যন্ত, তারপর ডেমোর বিক্রয়কর্মী — লক্ষ্যের শেষ দিনে (২৫) নতুন জন
        $before = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $this->bind($short, $before, '2026-01-01', '2026-10-09');
        $this->bind($short, $this->sales, '2026-10-10');
        $this->bind($over, $this->sales, '2026-01-01');

        app(CustomerTargetService::class)->setOne((int) $short->id, Carbon::parse('2026-10-01'), '300000', '2026-10-25');
        app(CustomerTargetService::class)->setOne((int) $over->id, Carbon::parse('2026-10-01'), '50000', '2026-10-20');
        app(CustomerTargetService::class)->setOne((int) $bounce->id, Carbon::parse('2026-10-01'), '10000', '2026-10-31');
        // ⓘ আগের মাসের লক্ষ্য — এই মাসের সারিতে আসবে না
        app(CustomerTargetService::class)->setOne((int) $short->id, Carbon::parse('2026-09-01'), '1', '2026-09-30');

        $this->credit($short, 'collection', '100000');
        $this->credit($short, 'receipt_voucher', '70000');
        $this->credit($short, 'sales_return', '50000');
        $bounced = $this->cheque($short, '20000', 'bounced');
        $this->credit($short, 'cheque', '20000', $bounced);
        $this->postLines('cheque:bounced', [['account_id' => $this->receivable(), 'debit' => '20000', 'party_type' => 'customer', 'party_id' => $short->id],
            ['account_id' => StandardChart::find(StandardChart::CHEQUES_IN_HAND)->id, 'credit' => '20000']], $bounced);
        $held = $this->cheque($short, '15000', 'pending');
        $this->credit($short, 'cheque', '15000', $held);

        $this->credit($over, 'collection', '60000');
        $this->credit($none, 'collection', '9000');

        // ⓘ D — কেবল ফেরত চেক, কোনো আসা টাকা নেই: অর্জন শূন্যের নিচে নয়
        $this->postLines('cheque:bounced', [['account_id' => $this->receivable(), 'debit' => '5000', 'party_type' => 'customer', 'party_id' => $bounce->id],
            ['account_id' => StandardChart::find(StandardChart::CHEQUES_IN_HAND)->id, 'credit' => '5000']], $this->cheque($bounce, '5000', 'bounced'));

        // ⓘ অন্য কোম্পানির খাতায় একই আইডির পক্ষের নামে টাকা — এই কোম্পানির অর্জনে নয়
        $other = Company::query()->whereKeyNot($this->company->id)->orderBy('id')->firstOrFail();
        CompanyContext::forCompany((int) $other->id, function () use ($short, $other): void {
            app(StandardChart::class)->install();
            $cash = DB::table('accounts')->where('company_id', $other->id)->where('money_kind', 'cash')->where('is_group', false)->orderBy('id')->value('id');
            app(PostingEngine::class)->post(sourceType: 'collection', sourceId: random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: [
                ['account_id' => $cash, 'debit' => '777000'],
                ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => '777000', 'party_type' => 'customer', 'party_id' => $short->id],
            ], branchId: $other->defaultBranch()?->id);
        });
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $rows = $this->rows();

        $this->assertSame(['TGT-A', 'TGT-B', 'TGT-D'], $rows->keys()->sort()->values()->all(), '⛔ লক্ষ্যহীন ডিলার সারিতে এল');
        $this->assertSame(3, $this->rows(raw: true), '⛔ অন্য মাসের লক্ষ্যও সারি হল');
        $this->assertMoney('0', $rows['TGT-D']['achieved'], '⛔ কেবল ফেরত চেকে অর্জন শূন্যের নিচে নামল');
        $this->assertMoney('10000', $rows['TGT-D']['remaining'], '⛔ ঋণাত্মক অর্জন বাকি বাড়াল');
        $this->assertMoney('170000', $rows['TGT-A']['achieved'], '⛔ ফেরত মাল, ফেরত চেক বা পাশ না হওয়া চেক গোনা হল');
        $this->assertMoney(app(CustomerTargetService::class)->reminderFor((int) $short->id)['achieved'], $rows['TGT-A']['achieved'], '⛔ রিপোর্ট আর বিলের বাক্স আলাদা অঙ্ক বলে');
        $this->assertMoney('130000', $rows['TGT-A']['remaining'], 'বাকি');
        $this->assertMoney('56.7', $rows['TGT-A']['percent'], '%');
        $this->assertSame($this->sales->name, $rows['TGT-A']['salesperson'], '⛔ শেষ তারিখে বাঁধা বিক্রয়কর্মী নেই');
        $this->assertMoney('0', $rows['TGT-B']['remaining'], '⛔ লক্ষ্যের বেশি আদায়ে বাকি ঋণাত্মক');
        $this->assertMoney('120', $rows['TGT-B']['percent'], 'লক্ষ্যের বেশি %');

        $result = app(ReportEngine::class)->run(CollectionTargetReports::KEY, ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $said = (app(ReportEngine::class)->get(CollectionTargetReports::KEY)->summary)($result->totals);
        $this->assertSame('63.8', $said['value'], '⛔ সারাংশ (১,৭০,০০০ + ৬০,০০০ + ০) ÷ ৩,৬০,০০০ নয়');

        $this->get(route('sales.report.show', ['slug' => 'collection-target', 'from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertOk()->assertSee('Short Dealer');

        // ⓘ দেয়ালের ভিতরের বিক্রয়কর্মী — কেবল নিজের বাঁধা ডিলার
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->sales->fresh());
        app(DealerScope::class)->forget();
        $this->assertTrue(app(DealerScope::class)->walled($this->sales->fresh()), 'দৃশ্যটাই বানানো যায়নি — ডেমোর বিক্রয়কর্মী দেয়ালে নেই');
        // ⓘ আজ (৩ অক্টোবর) A আগের জনের, B এঁর — দেয়াল আজকের বাঁধন ধরে
        $this->assertSame(['TGT-B'], $this->rows()->keys()->all(), '⛔ দেয়ালের ভিতরের বিক্রয়কর্মী অন্যের ডিলার দেখলেন');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function rows(bool $raw = false): Collection|int
    {
        $rows = collect(app(ReportEngine::class)->run(CollectionTargetReports::KEY, ['from' => '2026-10-01', 'to' => '2026-10-31'])->rows)
            ->map(fn ($r) => (array) $r);

        return $raw ? $rows->count() : $rows->keyBy('code');
    }

    private function dealer(string $code, string $name): Customer
    {
        return Customer::query()->create(['code' => $code, 'name_en' => $name, 'name_bn' => $name, 'is_active' => true]);
    }

    private function bind(Customer $dealer, User $user, string $starts, ?string $ends = null): void
    {
        DB::table('dealer_bindings')->insert([
            'public_id' => (string) Str::uuid7(), 'company_id' => $this->company->id,
            'user_id' => $user->id, 'customer_id' => $dealer->id, 'starts_on' => $starts, 'ends_on' => $ends,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function credit(Customer $dealer, string $source, string $amount, ?int $sourceId = null): void
    {
        $cash = DB::table('accounts')->where('company_id', $this->company->id)->where('money_kind', 'cash')->where('is_group', false)->orderBy('id')->value('id');

        $this->postLines($source, [['account_id' => $cash, 'debit' => $amount],
            ['account_id' => $this->receivable(), 'credit' => $amount, 'party_type' => 'customer', 'party_id' => $dealer->id]], $sourceId);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function postLines(string $source, array $lines, ?int $sourceId = null): void
    {
        app(PostingEngine::class)->post(
            sourceType: $source, sourceId: $sourceId ?? random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: $lines,
            branchId: $this->company->defaultBranch()?->id,
        );
    }

    private function cheque(Customer $dealer, string $amount, string $status): int
    {
        return (int) DB::table('acc_cheques')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id, 'direction' => 'received', 'cheque_date' => now()->toDateString(),
            'received_on' => now()->toDateString(), 'cheque_no' => 'TGT-'.random_int(1000, 9999), 'amount' => $amount,
            'party_type' => 'customer', 'party_id' => $dealer->id, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function receivable(): int
    {
        return (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
    }

    private function assertMoney(string $expected, mixed $actual, string $message): void
    {
        $this->assertSame(0, bccomp($expected, (string) ($actual ?? '0'), 2), "{$message}: চাই {$expected}, এল ".var_export($actual, true));
    }
}
