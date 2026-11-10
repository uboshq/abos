<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Services\WorkingCapital;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ চলতি মূলধন খাতা পড়ে, যেকোনো তারিখে (মালিক, ৯ নভেম্বর ২০২৬: অর্থের ড্যাশবোর্ডের সবার নিচে, মালিকের ছবির হুবহু; তারিখের ঘর;
 * "মাসিক বিক্রয় ১০০, invest ৩০ — working capital ৩০%", গড় শেষ ৬ বা ১২ মাসের)। [[WorkingCapital]]
 *
 * ⓘ ডেমোর আগের জের যা-ই থাকুক, দাবিটা পার্থক্যে: আগে মাপা, জাবেদা বসানো, আবার মাপা — বাড়ার অঙ্ক হাতে গোনা।
 */
final class WorkingCapitalReadsTheBooksOnAnyDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00'));
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_each_part_moves_by_what_the_books_say_and_only_up_to_the_date(): void
    {
        $wc = app(WorkingCapital::class);
        $nov = Carbon::parse('2026-11-20');
        $oct = Carbon::parse('2026-10-31');
        $beforeNov = $wc->on($nov);
        $beforeOct = $wc->on($oct);

        $till = Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
        $this->journal('2026-10-10', [[$till->id, '50000', null], [StandardChart::find(StandardChart::OWNER_CAPITAL)->id, null, '50000']]);
        $this->journal('2026-11-05', [[$till->id, '120000', null], [StandardChart::find(StandardChart::SALES)->id, null, '120000']]);
        $this->journal('2026-11-06', [[$till->id, '7000', null], [StandardChart::find(StandardChart::VAT_PAYABLE)->id, null, '7000']]);
        // ⓘ সরবরাহকারীর দেনা ৩০,০০০ — "দেনা"-য় ওঠে, "অন্যান্য"-তে দ্বিতীয়বার নয় (Mac মিউট্যান্ট W2 বেঁচেছিল, ১০ নভেম্বর ২০২৬)
        $payableAccount = StandardChart::find('2111'); // ⓘ মালের সরবরাহকারী — দেনার দলের (২১১০) প্রথম খাত
        $this->journal('2026-11-07', [[StandardChart::find(StandardChart::RENT)->id, '30000', null],
            [$payableAccount->id, null, '30000', ['party_type' => 'supplier', 'party_id' => Supplier::query()->firstOrFail()->id]]]);

        $nowNov = $wc->on($nov);
        $nowOct = $wc->on($oct);

        $this->assertSame('177000.0000', bcsub($nowNov['money'], $beforeNov['money'], 4),
            '⛔ হাতে টাকা খাতার মতো বাড়েনি — আগে '.$beforeNov['money'].', পরে '.$nowNov['money'].', খাত '.$till->code.' '.$till->name().', দাখিলা '.json_encode($this->entries($till->id)));
        $this->assertSame('30000.0000', bcsub($nowNov['payable'], $beforeNov['payable'], 4), '⛔ সরবরাহকারীর দেনা "দেনা"-য় ওঠেনি।');
        $this->assertSame('7000.0000', bcsub($nowNov['other'], $beforeNov['other'], 4), '⛔ অন্যান্য চলতি দায়ে কেবল মূসক নয় — দেনাও দ্বিতীয়বার গোনা হলো।');
        $this->assertSame('140000.0000', bcsub($nowNov['capital'], $beforeNov['capital'], 4), '⛔ চলতি মূলধন = যোগ − বাদ মেলেনি।');

        // ⓘ ৩১ অক্টোবরের হিসাবে কেবল অক্টোবরের ৫০,০০০ — নভেম্বরের টাকা পেছনের তারিখে ঢোকে না
        $this->assertSame('50000.0000', bcsub($nowOct['money'], $beforeOct['money'], 4), '⛔ তারিখের পরের দাখিলাও গোনা হলো।');

        // ⓘ আগের মাসের শেষের সাথে তুলনা — নভেম্বরের হিসাবের "আগের" তারিখটা ৩১ অক্টোবর
        $this->assertSame('2026-10-31', $nowNov['previous']['date']);
        $this->assertSame($nowOct['capital'], $nowNov['previous']['capital']);
    }

    public function test_the_share_of_monthly_sales_uses_the_six_or_twelve_month_average(): void
    {
        // ⓘ ডেমোর অর্থবছর ১ জুলাই ২০২৬ – ৩০ জুন ২০২৭; হিসাবের দিন বছরের শেষে, যাতে ১২ মাসের জানালা পুরোটাই বছরের ভিতরে
        Carbon::setTestNow(Carbon::parse('2027-06-30 12:00:00'));
        $wc = app(WorkingCapital::class);
        $date = Carbon::parse('2027-06-30');
        $six = $wc->on($date, 6);
        $twelve = $wc->on($date, 12);

        $till = Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
        $this->journal('2027-06-05', [[$till->id, '600000', null], [StandardChart::find(StandardChart::SALES)->id, null, '600000']]);
        // ⓘ ছয় মাসের আগের বিক্রি — ১২ মাসের গড়ে পড়ে, ৬ মাসেরটায় নয়
        $this->journal('2026-08-10', [[$till->id, '1200000', null], [StandardChart::find(StandardChart::SALES)->id, null, '1200000']]);
        // ⓘ ডিসেম্বরের বিক্রি — ৬ মাসের জানালা জানুয়ারি থেকে, তাই ঠিক বাইরে; জানালা এক মাস লম্বা হলে ঢুকে পড়ত (Mac মিউট্যান্ট W3 বেঁচেছিল)
        $this->journal('2026-12-15', [[$till->id, '300000', null], [StandardChart::find(StandardChart::SALES)->id, null, '300000']]);

        $sixAfter = $wc->on($date, 6);
        $twelveAfter = $wc->on($date, 12);

        $this->assertSame('100000.0000', bcsub($sixAfter['avg_sales'], $six['avg_sales'], 4), '⛔ ৬ মাসের গড়ে ৬,০০,০০০ ÷ ৬ নয় — জানালা জানুয়ারির আগে থেকে?');
        $this->assertSame('175000.0000', bcsub($twelveAfter['avg_sales'], $twelve['avg_sales'], 4), '⛔ ১২ মাসের গড়ে (৬,০০,০০০ + ৩,০০,০০০ + ১২,০০,০০০) ÷ ১২ নয়।');

        $expected = bcmul(bcdiv($sixAfter['capital'], $sixAfter['avg_sales'], 6), '100', 0);
        $this->assertSame($expected, $sixAfter['sales_pct'], '⛔ শতাংশ = চলতি মূলধন ÷ গড় মাসিক বিক্রি × ১০০ নয়।');
        $this->assertSame(12, $twelveAfter['span']);
        $this->assertSame(6, $wc->on($date, 7)['span'], '⛔ অজানা মাসের সংখ্যা ৬-এ ফেরেনি।');
    }

    public function test_the_dashboard_draws_it_last_for_the_asked_date_and_falls_back_to_today(): void
    {
        $html = (string) $this->get(route('module.dashboard', ['module' => 'finance', 'wc_date' => '2026-10-31', 'wc_span' => 12]))->assertOk()->getContent();

        $this->assertStringContainsString('data-working-capital', $html, '⛔ অর্থের ড্যাশবোর্ডে চলতি মূলধন নেই।');
        $this->assertStringContainsString('abosDate(&quot;2026-10-31&quot;', $html, '⛔ বাছা তারিখটা ঘরে বসেনি।');
        $this->assertMatchesRegularExpression('/data-wc-span="12"[^>]*aria-current="true"/', $html, '⛔ ১২ মাসের গড় বাছা দেখায় না।');
        $this->assertGreaterThan(strrpos($html, 'data-listing') ?: 0, strpos($html, 'data-working-capital'), '⛔ চলতি মূলধন সবার নিচে নয়।');

        foreach (['not-a-date', '2099-01-01'] as $bad) {
            $page = (string) $this->get(route('module.dashboard', ['module' => 'finance', 'wc_date' => $bad]))->assertOk()->getContent();
            $this->assertStringContainsString('abosDate(&quot;2026-11-20&quot;', $page, "⛔ '{$bad}' তারিখে আজকের হিসাবে ফেরেনি।");
        }
    }

    /**
     * ⭐ ফোনের হোমে একই অঙ্ক — `GET /api/v1/dashboard/today`-এর `workingCapital` (মালিক, ৯ নভেম্বর ২০২৬: "sudu working capital er
     * card ti bosabe"); তারিখ `wcDate`, গড় `wcSpan`। ⛔ অর্থের চাবি না থাকলে ঘরটাই নেই (চুক্তির নিয়ম ক)।
     */
    public function test_the_phone_home_gets_the_same_figures_and_none_without_the_key(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $web = app(WorkingCapital::class)->on(Carbon::parse('2026-10-31'), 12);

        app('auth')->forgetGuards();
        \Laravel\Sanctum\Sanctum::actingAs($owner->fresh(), [\App\Http\Controllers\Api\AuthController::APP]);
        $body = $this->getJson('/api/v1/dashboard/today?wcDate=2026-10-31&wcSpan=12')->assertOk()->json();

        $this->assertArrayHasKey('workingCapital', $body, '⛔ ফোনের উত্তরে চলতি মূলধন নেই।');
        $phone = $body['workingCapital'];
        $this->assertSame('2026-10-31', $phone['date']);
        $this->assertSame(12, $phone['span']);
        $this->assertSame(bcadd($web['capital'], '0', 4), $phone['capital'], '⛔ ফোনের চলতি মূলধন ওয়েবের থেকে আলাদা।');
        $this->assertSame($web['sales_pct'], $phone['salesPct']);

        $clerk = User::factory()->create(['current_company_id' => $owner->current_company_id, 'is_active' => true]);
        $clerk->companies()->attach($owner->current_company_id, ['is_active' => true]);
        app('auth')->forgetGuards();
        \Laravel\Sanctum\Sanctum::actingAs($clerk->fresh(), [\App\Http\Controllers\Api\AuthController::APP]);
        $none = $this->getJson('/api/v1/dashboard/today')->assertOk()->json();
        $this->assertArrayNotHasKey('workingCapital', $none, '⛔ অর্থের চাবি ছাড়াই ফোনে চলতি মূলধন গেল।');
    }

    /** @return list<array<string, mixed>> খাতের দাখিলা — দাবির বার্তায় দেখানোর জন্য */
    private function entries(int $accountId): array
    {
        return \App\Models\LedgerEntry::query()->where('account_id', $accountId)->latest('id')->limit(6)
            ->get(['trx_date', 'branch_id', 'debit', 'credit'])->toArray();
    }

    /** @param  list<array{0: int, 1: ?string, 2: ?string, 3?: array<string, mixed>}>  $lines  ⓘ চতুর্থটা পক্ষ — দেনার খাতে লাগে */
    private function journal(string $date, array $lines): void
    {
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $date, 'narration' => 'চলতি মূলধনের দাবি'],
            array_map(fn (array $l) => array_filter(['account_id' => $l[0], 'debit' => $l[1], 'credit' => $l[2]], fn ($v) => $v !== null) + ($l[3] ?? []), $lines),
        );
        app(VoucherService::class)->post($voucher);
        // ⓘ জের-ভান্ডার এক অনুরোধের জন্য (scoped) — আসল পর্দায় পরের দেখা নতুন অনুরোধ; এখানে হাতে ভুলিয়ে সেটাই
        app(\App\Core\Services\LedgerBalances::class)->forget();
    }
}
