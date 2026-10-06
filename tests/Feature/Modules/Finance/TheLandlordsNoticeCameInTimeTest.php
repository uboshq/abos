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
use App\Modules\Finance\Models\RentalAdjustment;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Services\DueNotices;
use App\Modules\Finance\Services\RentalDues;
use App\Modules\Finance\Services\RentalNotices;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ ঘ — ভাড়ার সতর্কতা (অর্থ-মডিউলের পরিকল্পনা, অংশ ৫ঘ, ৬ অক্টোবর ২০২৬, [[RentalDues]], [[RentalNotices]])।
 *
 * ⛔ চুক্তি শেষের ৬০ দিন আগে একবার, ৩০ দিন আগে (আর মেয়াদ পেরোলে) সপ্তাহে সপ্তাহে; বকেয়া ভাড়া সপ্তাহে সপ্তাহে। বিপজ্জনক
 * ইনপুট: অনেক দূরের শেষ, আগেই শেষ করা চুক্তি, অন্য কোম্পানি, অধিকারহীন মানুষ; বকেয়ায় — সই-পড়া, সইয়ের অপেক্ষার, বাতিল
 * ভাউচারের, মুছে-ফেলা আর ভাউচার ছাড়া পুরনো মাস, আর ভাড়ার দিন না-আসা মাস।
 */
final class TheLandlordsNoticeCameInTimeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $mymensingh;

    private User $owner;

    private Carbon $today;

    private RentalContract $in45;

    private RentalContract $in20;

    private RentalContract $ended;

    private RentalContract $owing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->mymensingh = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        // ⓘ আজ = মাসের ১০ তারিখ, যাতে ভাড়ার দিন (৫) এই মাসেও পেরিয়েছে
        $this->today = now()->startOfMonth()->addDays(9);
        Carbon::setTestNow($this->today);

        $this->in45 = $this->contract('Forty Five', endsIn: 45);
        $this->in20 = $this->contract('Twenty', endsIn: 20);
        $this->ended = $this->contract('Ended Ten Days Ago', endsIn: -10);
        // ⓘ ৬০-এর বাইরে, ৯০-এর ভিতরে — পুরনো ৯০ দিনের বাক্স একে দেখাত
        $this->contract('Seventy Five', endsIn: 75);
        // ⛔ আগেই শেষ করা — কোনো মাস দেওয়া হয়নি, তবু শেষ চুক্তির খবর নয়
        $this->contract('Closed Early', endsIn: 10, status: RentalContract::CLOSED, paidUp: false);
        CompanyContext::forCompany((int) Company::query()->where('code', 'FMART')->value('id'), fn () => $this->contract('Other Company', endsIn: 20));

        // ⓘ বকেয়ার চুক্তি — তিন মাস আগে শুরু, অনেক দূরে শেষ
        $this->owing = $this->contract('Owing Landlord', endsIn: 400, starts: $this->month(-3), paidUp: false);
        $this->adjust($this->owing, $this->month(-3), DocumentStatus::CONFIRMED);
        $this->adjust($this->owing, $this->month(-2), DocumentStatus::DRAFT);
        $this->adjust($this->owing, $this->month(-1), DocumentStatus::CANCELLED);
        $this->adjust($this->owing, $this->month(0), DocumentStatus::CONFIRMED, deleted: true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_overdue_months_are_the_unpaid_ones_whose_rent_day_has_passed(): void
    {
        $months = array_map(fn (Carbon $m) => $m->toDateString(), app(RentalDues::class)->overdueMonths($this->owing));

        $this->assertSame([$this->month(-1)->toDateString(), $this->month(0)->toDateString()], $months,
            'সই-পড়া আর সইয়ের অপেক্ষার মাস বকেয়া নয়; বাতিল ভাউচারের আর মুছে-ফেলা মাস বকেয়া');

        // ⓘ ভাড়ার দিন এখনো আসেনি (মাসের ৪ তারিখ) — এই মাস বকেয়া নয়
        $early = array_map(fn (Carbon $m) => $m->toDateString(), app(RentalDues::class)->overdueMonths($this->owing, $this->month(0)->addDays(3)));
        $this->assertSame([$this->month(-1)->toDateString()], $early, '⛔ ভাড়ার দিনের আগেই বকেয়া বলল');

        // ⓘ ভাউচার ছাড়া পুরনো মাসের সারি — করা মাস
        $this->adjust($this->owing, $this->month(-1), null);
        $this->assertSame([$this->month(0)->toDateString()],
            array_map(fn (Carbon $m) => $m->toDateString(), app(RentalDues::class)->overdueMonths($this->owing->fresh())));

        $row = collect(app(RentalDues::class)->overdue())->firstWhere(fn ($r) => $r['contract']->is($this->owing));
        $this->assertSame(0, bccomp('10000', $row['amount'], 4), 'এক মাস × ১০০০০');
    }

    /** ⓘ মেয়াদ পেরোনো চুক্তির মেয়াদের পরের মাস বকেয়া নয় */
    public function test_months_after_the_term_are_never_overdue(): void
    {
        // ⓘ চল্লিশ দিন আগে মেয়াদ শেষ, কেউ শেষ করেননি, কোনো মাস দেওয়া হয়নি
        $expired = $this->contract('Expired Unpaid', endsIn: -40, paidUp: false);
        $months = app(RentalDues::class)->overdueMonths($expired);

        $this->assertCount(12, $months, 'বারো মাসের মেয়াদ — বারো মাসই বকেয়া, তার বেশি নয়');
        $this->assertTrue(collect($months)->every(fn (Carbon $m) => $m->lte($expired->ends_on)), '⛔ মেয়াদের পরের মাস বকেয়া বলল');
    }

    public function test_the_bell_rings_at_sixty_once_and_at_thirty_every_week(): void
    {
        $sent = $this->morning();

        $this->assertSame(['Ended Ten Days Ago', 'Forty Five', 'Twenty'], $this->toldAbout(RentalNotices::ENDING));
        $this->assertSame(['Owing Landlord'], $this->toldAbout(RentalNotices::OVERDUE));
        $this->assertSame(1, Notification::query()->where('user_id', $this->owner->id)->where('type', RentalNotices::ENDING.'60')->count(), '৬০ দিনের ধাপ — কেবল ৪৫');
        $this->assertGreaterThan(0, $sent['ending']);

        // ⓘ একই দিনে আবার — কিছুই নয়
        $this->assertSame(['ending' => 0, 'overdue' => 0], $this->morning());

        // ⓘ আট দিন পরে — ৩০-এর ধাপ আর বকেয়া আবার; ৬০-এর ধাপ (৩৭ দিন বাকি) আর নয়
        Carbon::setTestNow($this->today->copy()->addDays(8));
        $this->morning();

        $this->assertSame(1, Notification::query()->where('user_id', $this->owner->id)->where('type', RentalNotices::ENDING.'60')->count(), '⛔ ৬০ দিনের ধাপ আবার বাজল');
        $this->assertSame(4, Notification::query()->where('user_id', $this->owner->id)->where('type', RentalNotices::ENDING.'30')->count(), '৩০-এর ধাপ — দুই চুক্তি, দুই সপ্তাহ');
        $this->assertSame(2, Notification::query()->where('user_id', $this->owner->id)->where('type', RentalNotices::OVERDUE)->count(), 'বকেয়া — দুই সপ্তাহ');
    }

    public function test_nobody_without_the_rental_page_is_told(): void
    {
        // ⓘ হিসাবের অধিকার আছে, ভাড়ার পাতার নেই — খবর নয়; কেবল ভাড়ার পাতার অধিকার — খবর
        $clerk = $this->member('accounts.view');
        $renter = $this->member('finance.rental.view');

        $this->morning();

        $this->assertSame(0, Notification::query()->where('user_id', $clerk->id)->count(), '⛔ অধিকারহীন মানুষ ভাড়ার খবর পেলেন');
        $this->assertGreaterThan(0, Notification::query()->where('user_id', $renter->id)->count(), 'ভাড়ার পাতার মানুষ খবর পাননি');
    }

    /** ⓘ রোজ সকালের তাগাদার সাথে চলে */
    public function test_the_morning_round_carries_the_rent(): void
    {
        $this->assertArrayHasKey('rentals', $counts = $this->morning(due: true));
        $this->assertGreaterThan(0, $counts['rentals']);
    }

    /** ⓘ ভাড়ার পাতার বাক্স আর ড্যাশবোর্ড একই দিন ধরে */
    public function test_the_page_and_the_dashboard_say_the_same(): void
    {
        $this->get(route('finance.rental.index'))->assertOk()
            ->assertViewHas('endingSoon', fn ($soon) => $soon->pluck('counterparty')->sort()->values()->all() === ['Ended Ten Days Ago', 'Forty Five', 'Twenty']);

        config(['abos.dashboards_v2' => true]);
        $this->get('/dashboard/finance')->assertOk()->assertSee(__('finance::rental_report.dash_label'))
            ->assertSee(__('finance::rental_report.dash_hint', ['ending' => 3, 'overdue' => 1]));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * রোজ সকালের তাগাদা — কেউ লগইন না থাকা অবস্থায়, যেমন সময়সূচিতে চলে। ⓘ লগইন থাকলে যিনি চালাচ্ছেন তাঁকে নিজের খবর পাঠানো
     * হয় না ([[NotificationService::send()]]), আর মালিকের ঘণ্টি চুপ থাকত।
     *
     * @return array<string, int>
     */
    private function morning(bool $due = false): array
    {
        $this->app['auth']->forgetGuards();

        return $due ? app(DueNotices::class)->sendAll() : app(RentalNotices::class)->sendAll();
    }

    /** @return list<string> কাদের নিয়ে খবর গেল — মালিকের কাছে, এই ধরনের */
    private function toldAbout(string $type): array
    {
        $urls = Notification::query()->where('user_id', $this->owner->id)->where('type', 'like', $type.'%')->pluck('url')->all();

        return RentalContract::query()->get()
            ->filter(fn (RentalContract $c) => in_array(route('finance.rental.show', $c->id), $urls, true))
            ->pluck('counterparty')->sort()->values()->all();
    }

    private function member(string $permission): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web')));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function month(int $offset): Carbon
    {
        return $this->today->copy()->startOfMonth()->addMonths($offset);
    }

    /** ⓘ `$paidUp` — আজ পর্যন্ত প্রতিটা মাসের সারি (ভাউচার ছাড়া পুরনো, করা মাস), যাতে কেবল শেষের খবরটাই মাপা হয় */
    private function contract(string $who, int $endsIn, string $status = RentalContract::ACTIVE, ?Carbon $starts = null, bool $paidUp = true): RentalContract
    {
        $ends = $this->today->copy()->addDays($endsIn);
        $starts ??= $ends->copy()->subMonths(12)->addDay();

        $contract = RentalContract::query()->create([
            'company_id' => CompanyContext::id(), 'branch_id' => $this->mymensingh->id, 'document_no' => 'RNT-'.random_int(1, 999999),
            'counterparty' => $who, 'subject' => $who.' place',
            'account_id' => StandardChart::find(StandardChart::SECURITY_DEPOSIT)->id,
            'expense_account_id' => StandardChart::find(StandardChart::RENT)->id,
            'deposit_amount' => '0', 'monthly_rent' => '10000', 'monthly_adjustment' => '0', 'rent_day' => 5,
            'starts_on' => $starts->toDateString(), 'term_months' => 12, 'ends_on' => $ends->toDateString(),
            'status' => $status, 'closed_on' => $status === RentalContract::CLOSED ? $this->today->toDateString() : null,
        ]);

        for ($m = $starts->copy()->startOfMonth(); $paidUp && $m->lte($this->today); $m->addMonth()) {
            RentalAdjustment::query()->create([
                'company_id' => $contract->company_id, 'branch_id' => $contract->branch_id, 'rental_contract_id' => $contract->id,
                'for_month' => $m->toDateString(), 'rent' => '10000', 'paid_cash' => '10000', 'from_deposit' => '0',
            ]);
        }

        return $contract;
    }

    private function adjust(RentalContract $contract, Carbon $month, ?string $voucherStatus, bool $deleted = false): void
    {
        $voucher = $voucherStatus === null ? null : Voucher::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $contract->branch_id,
            'financial_year_id' => FinancialYear::query()->value('id'), 'type' => Voucher::PAYMENT,
            'document_no' => 'PV-RNT-'.random_int(1, 999999), 'trx_date' => $month->toDateString(), 'amount' => '10000', 'status' => $voucherStatus,
        ]);

        $row = RentalAdjustment::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $contract->branch_id, 'rental_contract_id' => $contract->id,
            'for_month' => $month->toDateString(), 'rent' => '10000', 'paid_cash' => '10000', 'from_deposit' => '0', 'voucher_id' => $voucher?->id,
        ]);

        if ($deleted) {
            $row->delete();
        }
    }
}
