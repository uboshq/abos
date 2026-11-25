<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\CreditControl\Services\ReceivableCreditLoss;
use App\Modules\Customer\Models\Customer;
use App\Modules\Executive\Services\MonthClose\MonthClosePack;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ সম্ভাব্য ক্ষতির হার মালিকের পরিকল্পনা মতো (মালিক, ২১ অক্টোবর ২০২৬: "plan onujayi koro").
 *
 * দাবি:
 *  - কোম্পানি কিছু না বসালে হার 1/3/10/50 — চারটা ঘোষিত সেটিং `credit_control.ecl_rate_*`, আর মাস শেষের হিসাব একই হার পড়ে।
 *  - কোম্পানি নিজের হার বসালে সেটাই খাটে, অন্য কোম্পানির হার বদলায় না।
 *  - ক্ষতির খরচ 5228 — 5223 WPPF-এর; মাস শেষের "সম্ভাব্য ক্ষতি" WPPF-এর খরচ গোনে না।
 *  - প্রতিটা হারের পাশে ইতিহাসের হার: এক বছর আগের বকেয়ার কত অংশ পরের বছরে বাদ গেল, সবচেয়ে পুরনো ভাগ থেকে কেটে।
 *  - বকেয়া না থাকলে ইতিহাসের হার "—" (null), শূন্য নয়।
 *  - সংরক্ষণের জাবেদা জাবেদার ছকে অনুমোদনে যায়, খাতায় বসে না।
 */
final class TheLossRatesFollowTheOwnersPlanTest extends TestCase
{
    use \Tests\Concerns\OpensTheYears;
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        $this->customer = Customer::query()->orderBy('id')->firstOrFail();
    }

    public function test_without_a_company_setting_the_rates_are_one_three_ten_fifty(): void
    {
        DB::table('settings')->where('key', 'like', 'credit_control.ecl_rate_%')->delete();
        app(SettingsService::class)->flush();

        $loss = app(ReceivableCreditLoss::class);
        $this->assertSame(['bucket_current' => '1', 'bucket_30' => '3', 'bucket_60' => '10', 'bucket_90' => '50'], ReceivableCreditLoss::DEFAULTS);

        foreach (ReceivableCreditLoss::DEFAULTS as $bucket => $rate) {
            $this->assertSame(0, bccomp($loss->rate($bucket), $rate, 4), "⛔ {$bucket}-এর ডিফল্ট হার মালিকের {$rate}% নয়");
            $this->assertSame(MonthClosePack::ECL_RATES[$bucket], [ReceivableCreditLoss::RATES[$bucket], $rate], '⛔ মাস শেষের হিসাব অন্য হার পড়ে');
        }

        // ⛔ চারটা হার ঘোষিত সেটিং — কোম্পানির সেটিংয়ের পাতায় বসানো যায়, ডিফল্ট একই
        $declared = collect(require base_path('app/Modules/CreditControl/module.php'))->get('settings');
        foreach (ReceivableCreditLoss::RATES as $bucket => $key) {
            $row = collect($declared)->firstWhere('key', $key);
            $this->assertNotNull($row, "⛔ {$key} ঘোষিত নয়");
            $this->assertSame(0, bccomp((string) $row['default'], ReceivableCreditLoss::DEFAULTS[$bucket], 4), "⛔ {$key}-এর ঘোষিত ডিফল্ট ভুল");
        }
    }

    public function test_a_company_rate_wins_for_that_company_only(): void
    {
        app(SettingsService::class)->set('credit_control.ecl_rate_90', '75');
        $this->assertSame(0, bccomp(app(ReceivableCreditLoss::class)->rate('bucket_90'), '75', 4), '⛔ কোম্পানির হার খাটেনি');

        $other = Company::query()->whereKeyNot($this->company->id)->orderBy('id')->first();
        if ($other !== null) {
            CompanyContext::forCompany($other->id, function (): void {
                app(SettingsService::class)->flush();
                $this->assertSame(0, bccomp(app(ReceivableCreditLoss::class)->rate('bucket_90'), '50', 4), '⛔ এক কোম্পানির হার অন্য কোম্পানিতে গেল');
            });
        }
    }

    public function test_the_loss_expense_is_not_the_wppf_account(): void
    {
        $this->assertSame('5228', StandardChart::IMPAIRMENT_LOSS);
        $this->assertNotSame(StandardChart::WPPF_EXPENSE, StandardChart::IMPAIRMENT_LOSS, '⛔ ECL আর WPPF এক খাতে');
        $this->assertSame(StandardChart::IMPAIRMENT_LOSS, MonthClosePack::IMPAIRMENT_LOSS, '⛔ মাস শেষের হিসাব অন্য খাতকে "সম্ভাব্য ক্ষতি" বলে');
        $this->assertContains(StandardChart::LOSS_ALLOWANCE, StandardChart::SYSTEM_CODES);
        $this->assertContains(StandardChart::IMPAIRMENT_LOSS, StandardChart::SYSTEM_CODES);
        $this->assertNotNull(StandardChart::find(StandardChart::IMPAIRMENT_LOSS));
        $this->assertNotNull(StandardChart::find(StandardChart::LOSS_ALLOWANCE));
    }

    public function test_the_historical_rate_is_what_was_written_off_of_last_years_dues_oldest_first(): void
    {
        $loss = app(ReceivableCreditLoss::class);
        $today = now()->toDateString();
        $before = $loss->history($today);

        // ⓘ এক বছর আগে: 0–30-এ 10,000 আর 90+-এ 2,000 (যে দিনের হিসাব, তার 10 আর 120 দিন আগে)
        $yearAgo = now()->subMonthsNoOverflow(ReceivableCreditLoss::HISTORY_MONTHS);
        // ⓘ গত বছরের পাওনা — দাবি নিজের অর্থবছর নিজে খোলে ([[OpensTheYears]])
        $this->openTheYearsOf($yearAgo->copy()->subDays(10)->toDateString(), $yearAgo->copy()->subDays(120)->toDateString());
        $this->owe($yearAgo->copy()->subDays(10)->toDateString(), '10000');
        $this->owe($yearAgo->copy()->subDays(120)->toDateString(), '2000');

        // পরের বছরে 3,000 বাদ — আগে 90+-এর 2,000, তারপর 0–30-এর 1,000
        $this->writeOff(now()->subDays(5)->toDateString(), '3000');

        $after = $loss->history($today);

        if ($before['bucket_90'] === null && $before['bucket_current'] === null) {
            $this->assertSame(0, bccomp((string) $after['bucket_90'], '100', 2), '⛔ 90+-এর পুরোটা বাদ গেল, ইতিহাস 100% বলল না');
            $this->assertSame(0, bccomp((string) $after['bucket_current'], '10', 2), '⛔ 0–30-এর 10,000-এ 1,000 বাদ = 10%');
            $this->assertNull($after['bucket_30'], '⛔ বকেয়াই ছিল না, অথচ ইতিহাস একটা হার বলল');
        } else {
            // ⓘ ডেমোর নিজের পুরনো বকেয়া থাকলে কেবল দিক — বাদ যোগ হলে 90+-এর হার বাড়ে
            $this->assertGreaterThan((float) ($before['bucket_90'] ?? 0), (float) $after['bucket_90'], '⛔ বাদ যোগ হলো, ইতিহাসের হার বাড়ল না');
        }

        // ⭐ পর্দায় হারের পাশে
        $this->get(route('credit_control.loss.show'))->assertOk()->assertSee('data-history', false);
    }

    public function test_the_provision_journal_goes_for_approval_and_is_not_posted(): void
    {
        $approver = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $approver->companies()->attach($this->company->id, ['is_active' => true]);
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => VoucherApproval::MODULE, 'action' => Voucher::JOURNAL, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $approver->id]);

        $this->owe(now()->subDays(100)->toDateString(), '5000');
        $this->post(route('credit_control.loss.provision'), ['as_of' => now()->toDateString()])->assertSessionHasNoErrors()->assertRedirect();

        $voucher = Voucher::query()->latest('id')->firstOrFail();
        $this->assertSame(Voucher::JOURNAL, $voucher->type);
        $this->assertSame(DocumentStatus::DRAFT, $voucher->status, '⛔ সংরক্ষণ অনুমোদনের আগেই খাতায় বসল');
        $this->assertTrue(Approval::query()->where('approvable_type', Voucher::class)->where('approvable_id', $voucher->id)
            ->where('status', Approval::PENDING)->exists(), '⛔ সংরক্ষণের জাবেদা অনুমোদনে যায়নি');
    }

    private function owe(string $date, string $amount): void
    {
        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: $date, lines: [
            ['account_id' => (int) StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => $amount,
                'party_type' => Customer::drillSourceType(), 'party_id' => $this->customer->id],
            ['account_id' => (int) StandardChart::find(StandardChart::SALES)->id, 'credit' => $amount],
        ]);
    }

    private function writeOff(string $date, string $amount): void
    {
        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: $date, lines: [
            ['account_id' => (int) StandardChart::find(StandardChart::IMPAIRMENT_LOSS)->id, 'debit' => $amount],
            ['account_id' => (int) StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => $amount,
                'party_type' => Customer::drillSourceType(), 'party_id' => $this->customer->id],
        ]);
    }
}
