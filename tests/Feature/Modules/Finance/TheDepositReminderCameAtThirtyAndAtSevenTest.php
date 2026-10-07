<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\DepositMovement;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DueNotices;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ গ — মেয়াদপূর্তির খবর দুই ধাপে আর DPS-এর বকেয়া কিস্তি (অর্থ-মডিউলের পরিকল্পনা ৪.৩–৪.৪, ৬ অক্টোবর ২০২৬, [[DueNotices]])।
 *
 * ⛔ ৩০ দিনের ধাপে (৮–৩০ দিন) একবারই; শেষ সাত দিনে আর মেয়াদ পেরিয়েও খোলা থাকলে সপ্তাহে সপ্তাহে; DPS-এর বকেয়া সপ্তাহে
 * সপ্তাহে। বিপজ্জনক ইনপুট: অনেক দূরের মেয়াদ, বন্ধ জমা, দেওয়া কিস্তি, সইয়ের অপেক্ষার কিস্তি।
 */
final class TheDepositReminderCameAtThirtyAndAtSevenTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private User $owner;

    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->branch = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        app(DepositKindInstaller::class)->install();

        // ⓘ আজ = মাসের ১০ তারিখ — DPS-এর কিস্তির দিন (৫) এই মাসেও পেরিয়েছে
        $this->today = now()->startOfMonth()->addDays(9);
        Carbon::setTestNow($this->today);

        $this->deposit('FDR-20', 'FDR', 20);
        $this->deposit('FDR-5', 'FDR', 5);
        $this->deposit('FDR-PAST', 'FDR', -3);
        $this->deposit('FDR-60', 'FDR', 60);
        $this->deposit('FDR-SHUT', 'FDR', 4, status: Deposit::CLOSED);

        // ⓘ DPS — গত মাসে খোলা, খোলার কিস্তি দেওয়া; এই মাসের কিস্তি সইয়ের অপেক্ষায় — বকেয়া এক মাস
        $owing = $this->deposit('DPS-OWE', 'DPS', 700, opened: $this->today->copy()->subMonth()->startOfMonth()->addDays(4));
        $this->move($owing, DepositMovement::OPENED, $owing->opened_on, DocumentStatus::CONFIRMED);
        $this->move($owing, DepositMovement::INSTALMENT, $this->today->copy()->startOfMonth()->addDays(5), DocumentStatus::DRAFT);

        // ⓘ DPS — সব মাস দেওয়া — খবর নয়
        $paid = $this->deposit('DPS-OK', 'DPS', 700, opened: $this->today->copy()->subMonth()->startOfMonth()->addDays(4));
        $this->move($paid, DepositMovement::OPENED, $paid->opened_on, DocumentStatus::CONFIRMED);
        $this->move($paid, DepositMovement::INSTALMENT, $this->today->copy()->startOfMonth()->addDays(4), null);

        // ⓘ DPS — কিস্তির দিন ২০ তারিখ: এই মাসের কিস্তি বাকি, কিন্তু দিন আসেনি — বকেয়া নয়, খবর নয়
        $early = $this->deposit('DPS-EARLY', 'DPS', 700, opened: $this->today->copy()->subMonth()->startOfMonth()->addDays(19), day: 20);
        $this->move($early, DepositMovement::OPENED, $early->opened_on, DocumentStatus::CONFIRMED);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_thirty_days_rings_once_and_the_last_week_rings_every_week(): void
    {
        $this->morning();

        $this->assertSame(['FDR-20'], $this->told(DueNotices::MATURING), '৩০ দিনের ধাপ — কেবল ৮–৩০ দিন');
        $this->assertSame(['FDR-5', 'FDR-PAST'], $this->told(DueNotices::MATURING_SOON), 'শেষ সাত দিন আর পেরোনো খোলা জমা');
        $this->assertSame(['DPS-OWE'], $this->told(DueNotices::DPS_DUE), 'বকেয়া কিস্তি — সইয়ের অপেক্ষারটা দেওয়া নয়');

        // ⓘ একই দিনে আবার — কিছুই নয়
        $this->morning();
        $this->assertSame(3, $this->bellCount(DueNotices::MATURING_SOON) + $this->bellCount(DueNotices::DPS_DUE));

        // ⓘ আট দিন পরে — ৩০-এর ধাপ আর নয় (FDR-20 এখন ১২ দিন দূরে), শেষ সপ্তাহের আর বকেয়া আবার
        Carbon::setTestNow($this->today->copy()->addDays(8));
        $this->morning();

        $this->assertSame(1, $this->bellCount(DueNotices::MATURING), '⛔ ৩০ দিনের ধাপ আবার বাজল');
        $this->assertSame(4, $this->bellCount(DueNotices::MATURING_SOON), 'শেষ সপ্তাহের ধাপ — দুই জমা, দুই সপ্তাহ');
        $this->assertSame(2, $this->bellCount(DueNotices::DPS_DUE), 'বকেয়া কিস্তি — দুই সপ্তাহ');
    }

    public function test_the_morning_counts_carry_the_new_steps(): void
    {
        $counts = $this->morning();

        $this->assertGreaterThanOrEqual(3, $counts['maturing'], '৩০ আর ৭ দিনের দুই ধাপ একসাথে গোনা');
        $this->assertGreaterThan(0, $counts['dps']);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return array<string, int> */
    private function morning(): array
    {
        // ⓘ কেউ লগইন না থাকা অবস্থায়, যেমন সময়সূচিতে — যিনি চালান তাঁকে নিজের খবর যায় না
        $this->app['auth']->forgetGuards();

        return app(DueNotices::class)->sendAll();
    }

    /** @return list<string> */
    private function told(string $type): array
    {
        $urls = Notification::query()->where('user_id', $this->owner->id)->where('type', $type)->pluck('url')->all();

        return Deposit::query()->with('kind')->get()
            ->filter(fn (Deposit $d) => in_array(route('finance.deposit.show', ['issuer' => $d->kind->issuer, 'deposit' => $d->id]), $urls, true))
            ->pluck('document_no')->sort()->values()->all();
    }

    private function bellCount(string $type): int
    {
        return Notification::query()->where('user_id', $this->owner->id)->where('type', $type)->count();
    }

    private function deposit(string $no, string $kind, int $maturesIn, string $status = Deposit::ACTIVE, ?Carbon $opened = null, int $day = 5): Deposit
    {
        return Deposit::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'document_no' => $no, 'kind_id' => DepositKind::query()->where('code', $kind)->value('id'),
            'institution' => 'সোনালী ব্যাংক', 'held_by' => Deposit::BUSINESS, 'principal' => '100000',
            'profit_rate' => '8', 'tax_rate' => '10', 'return_word' => 'interest',
            'opened_on' => ($opened ?? $this->today->copy()->subYear())->toDateString(),
            'matures_on' => $this->today->copy()->addDays($maturesIn)->toDateString(),
            'instalment_amount' => $kind === 'DPS' ? '5000' : null, 'instalment_day' => $kind === 'DPS' ? $day : null,
            'account_id' => StandardChart::find(StandardChart::DEPOSITS_AND_INVESTMENTS)->id,
            'status' => $status, 'closed_on' => $status === Deposit::CLOSED ? $this->today->toDateString() : null,
        ]);
    }

    private function move(Deposit $deposit, string $kind, Carbon $on, ?string $voucherStatus): void
    {
        $voucher = $voucherStatus === null ? null : Voucher::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $deposit->branch_id,
            'financial_year_id' => FinancialYear::query()->value('id'), 'type' => Voucher::PAYMENT,
            'document_no' => 'PV-DPS-'.random_int(1, 999999), 'trx_date' => $on->toDateString(), 'amount' => '5000', 'status' => $voucherStatus,
        ]);

        DepositMovement::query()->create([
            'company_id' => $this->company->id, 'deposit_id' => $deposit->id, 'kind' => $kind, 'amount' => '5000',
            'moved_on' => $on->toDateString(), 'voucher_id' => $voucher?->id,
        ]);
    }
}
