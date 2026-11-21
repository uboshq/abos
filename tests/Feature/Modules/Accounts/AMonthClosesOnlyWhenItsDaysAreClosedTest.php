<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\OpenPeriod;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DayClose;
use App\Models\FinancialYear;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\MonthEndChecklist;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⭐ মাস বন্ধ যাচাই — মালিকের ২০ নভেম্বর ২০২৬-এর পরিকল্পনার শেষ দফা (১৫)।
 *
 * মালিক, ১৭ নভেম্বর ২০২৬ (মাস শেষের পরিকল্পনা, "Ok" আর "Sob ok"): *"দিন-শেষগুলোর যোগ মাসের অঙ্কের সমান না হলে মাস বন্ধ হবে না"*।
 * মালিক, ১৮ নভেম্বর ২০২৬: মাস শেষ হয়ে বন্ধ না হলে দিনের মতোই পপআপ আর চেকলিস্ট; *"সব পরিষ্কার হলে, নয়তো কারণ লিখে"*; আর
 * *"কাজ যাতে কোনোভাবে আটকে না থাকে"*। মালিক, ৩ অক্টোবর ২০২৬: বন্ধ মাসে কিছু নয় — দরকার হলে মাসটা খুলে।
 *
 * দাবিগুলো:
 *   (১) কাজ হওয়া কোনো কর্মদিবস দিন শেষের হিসাবে বন্ধ না হলে মাস বন্ধ হয় না, আর বার্তা দিনটা নাম ধরে বলে; সাপ্তাহিক ছুটি গোনা হয় না;
 *       শাখার দিন বন্ধও দিন বন্ধ;
 *   (২) দিনগুলো বন্ধ, কিন্তু বাকি কাজ (যেমন বন্ধ দিনে পরে যোগ) থাকলে কারণ ছাড়া মাস বন্ধ হয় না; কারণ লিখলে হয়, আর কী বাকি ছিল তা
 *       তালায় লেখা থাকে — মাস বন্ধের পাতায় আর মাস শেষের হিসাবে দেখায়;
 *   (৩) বন্ধ মাসে মালিকও কিছু বসাতে পারেন না, পেছনের তারিখের অনুমতিতেও নয়; আবার খুললে (কারণসহ) তবেই;
 *   (৪) পপআপ: মাস বন্ধের চাবিওয়ালাকে (দিন বন্ধের চাবি না থাকলেও) মাসের বাকি কাজ লিংকসহ দেখায়, পাতা চলতেই থাকে; কোনো চাবি না থাকলে
 *       দেখায় না; "পরে মনে করাবেন" নিয়ন্ত্রণ প্যানেলের সময় পর্যন্ত লুকায়;
 *   (৫) দিন বন্ধ চালু হওয়ার আগের দিন গোনা হয় না — নইলে পুরনো মাস কখনো বন্ধ হত না।
 *
 * ⓘ সময় আগে বসে, তারপর ডেমো — ডেমোর খাতার সারিগুলোর `created_at` দিন বন্ধের সময়ের পরে না পড়ে (নইলে সবই "বন্ধের পরে যোগ")।
 */
final class AMonthClosesOnlyWhenItsDaysAreClosedTest extends TestCase
{
    use RefreshDatabase;

    /** ⓘ ২৮ অক্টোবর ২০২৬ বুধবার — প্রথম দিন বন্ধ; ২৯ বৃহস্পতি, ৩০ শুক্র (সাপ্তাহিক ছুটি), ৩১ শনি */
    private const FIRST = '2026-10-28';

    private const OPEN_DAY = '2026-10-29';

    private Company $company;

    private Branch $a;

    private User $owner;

    private Account $cash;

    private int $source = 9700;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-17 10:00:00');
        $this->seed(DemoSeeder::class);
        Cache::flush();

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        CompanyContext::set($this->company->id, null);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['locale' => 'en'])->save();
        $this->actingAs($this->owner->fresh());
        app()->setLocale('en');

        app(StandardChart::class)->install();
        $this->cash = app(AccountService::class)->create(['name_en' => 'Month close cash', 'parent_id' => StandardChart::find(StandardChart::CASH_IN_HAND)->id, 'held_by' => $this->owner->id]);

        $this->clearOctober();
        $this->lockMonthsBeforeOctober();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_working_day_left_open_keeps_the_month_open_and_is_named(): void
    {
        app(SettingsService::class)->set('accounts.day_close.weekly_holiday', 'friday');
        $this->closeEveryDayOfTheWindowExcept([self::OPEN_DAY, '2026-10-30']);
        $this->book(self::OPEN_DAY);
        $this->book('2026-10-30'); // ⓘ শুক্রবার — সাপ্তাহিক ছুটি, বন্ধ না হলেও গোনা হয় না

        $row = $this->row('days_closed');
        $this->assertSame(MonthEndChecklist::PENDING, $row['state'], '⛔ বন্ধ না হওয়া কর্মদিবস চেকলিস্টে আসেনি');
        $this->assertSame([self::OPEN_DAY], $row['dates'], '⛔ সাপ্তাহিক ছুটির দিনও বন্ধ-না-হওয়া ধরা হলো, বা খোলা দিনটা নেই');

        $this->close()->assertSessionHasErrors(['month' => __('accounts::validation.month_days_open', ['count' => 1, 'days' => '29-10'])]);
        $this->assertNull($this->lock(), '⛔ খোলা দিনসহ মাস বন্ধ হলো — দিন-শেষগুলোর যোগ আর মাস মেলে না');

        // ⓘ কারণ লিখলেও নয় — এটা কারণের সারি নয়, মালিকের "মাস বন্ধ হবে না"
        $this->close('a reason does not close an open day')->assertSessionHasErrors('month');
        $this->assertNull($this->lock());

        // ⓘ শাখার দিন বন্ধও দিন বন্ধ — সেদিন কেবল ওই শাখায় লেখা হয়েছে
        DayClose::query()->forceCreate(['company_id' => $this->company->id, 'branch_id' => $this->a->id, 'day' => self::OPEN_DAY,
            'closed_by' => $this->owner->id, 'closed_at' => now()]);
        Cache::flush();

        $this->assertSame(MonthEndChecklist::OK, $this->row('days_closed')['state'], '⛔ শাখা দিন বন্ধ করার পরেও দিনটা খোলা ধরা হলো');
    }

    public function test_work_left_needs_a_reason_and_the_lock_keeps_what_was_left(): void
    {
        $this->closeEveryDayOfTheWindowExcept([]);

        // ⓘ বন্ধ দিনে, বন্ধের পরে — ডিফল্টে লেখা চলে (কাজ আটকায় না), কিন্তু মাস বন্ধে কারণ চায়
        Carbon::setTestNow('2026-11-17 10:05:00');
        $this->book(self::FIRST);

        $row = $this->row('days_changed');
        $this->assertSame(MonthEndChecklist::PENDING, $row['state'], '⛔ বন্ধ দিনে পরে যোগ চেকলিস্টে আসেনি');
        $this->assertContains(self::FIRST, $row['dates']);

        $this->close()->assertSessionHasErrors('reason');
        $this->assertNull($this->lock(), '⛔ বাকি কাজসহ মাস কারণ ছাড়াই বন্ধ হলো');

        $this->close('Late bill of 28 Oct checked with the owner')->assertSessionHasNoErrors();

        $lock = $this->lock();
        $this->assertNotNull($lock, '⛔ কারণ লিখেও মাস বন্ধ হলো না');
        $this->assertSame('Late bill of 28 Oct checked with the owner', $lock->reason);
        $this->assertArrayHasKey('days_changed', (array) $lock->open_items, '⛔ কী বাকি রেখে বন্ধ হলো, তালায় লেখা নেই');
        $this->assertSame(1, (int) $lock->open_items['days_changed']);

        $this->get(route('accounts.period.index'))->assertOk()->assertSee('data-open-items', false)
            ->assertSee(__('accounts::board.close.step.days_changed'));

        $this->get(route('executive.month_close', ['month' => '2026-10']))->assertOk()->assertSee('data-lock-left', false)
            ->assertSee('Late bill of 28 Oct checked with the owner');
    }

    public function test_a_clean_month_closes_without_a_reason_and_keeps_no_open_items(): void
    {
        $this->closeEveryDayOfTheWindowExcept([]);
        $left = collect(app(MonthEndChecklist::class)->run(\Carbon\CarbonImmutable::parse('2026-10-01')))
            ->whereIn('key', MonthEndChecklist::NEEDS_REASON)->where('state', MonthEndChecklist::PENDING);

        if ($left->isNotEmpty()) {
            // ⓘ ডেমোর টিল/ব্যাংক/সম্পদে বাকি থাকলে এই দৃশ্য সাজানো যায় না — দাবিটা তখন উল্টো দিক থেকে: কারণ চাওয়া হয়
            $this->close()->assertSessionHasErrors('reason');

            return;
        }

        $this->close()->assertSessionHasNoErrors();
        $this->assertNull($this->lock()?->open_items, '⛔ সব পরিষ্কার, অথচ তালায় "বাকি" লেখা');
    }

    public function test_a_closed_month_takes_nothing_even_from_the_owner_until_it_is_reopened_with_a_reason(): void
    {
        $this->closeEveryDayOfTheWindowExcept([]);
        $this->close('closing for the test')->assertSessionHasNoErrors();
        $lock = $this->lock();
        $this->assertNotNull($lock);

        $this->assertTrue($this->owner->fresh()->can('accounts.backdate.override'), 'মালিকের পেছনের তারিখের অনুমতি থাকার কথা');
        $this->assertThrows(fn () => (new OpenPeriod(app(SettingsService::class)))->assertOpen(self::OPEN_DAY), ValidationException::class);

        // ⓘ খোলার কারণ বাধ্যতামূলক
        $this->from(route('accounts.period.index'))->post(route('accounts.period.reopen', $lock), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertNotNull($this->lock(), '⛔ কারণ ছাড়াই মাস খুলে গেল');

        $this->from(route('accounts.period.index'))->post(route('accounts.period.reopen', $lock), ['reason' => 'Supplier bill of 29 Oct came late'])
            ->assertSessionHasNoErrors();
        $this->assertNull($this->lock(), 'কারণসহ খোলার পরেও মাস বন্ধ');

        (new OpenPeriod(app(SettingsService::class)))->assertOpen(self::OPEN_DAY);
    }

    public function test_the_popup_shows_the_month_checklist_to_whoever_closes_months_and_never_stops_the_page(): void
    {
        $this->book(self::OPEN_DAY);

        $closer = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $closer->companies()->attach($this->company->id, ['default_branch_id' => $this->a->id, 'is_active' => true]);
        $closer->givePermissionTo(Permission::findOrCreate('accounts.view', 'web'), Permission::findOrCreate('accounts.period.close', 'web'));
        $this->actingAs($closer->fresh());

        $page = $this->get(route('accounts.period.index'));
        $page->assertOk();
        $page->assertSee('data-day-popup', false);
        $page->assertSee('data-open-month', false);
        $page->assertSee('data-month-left="2026-10"', false);
        $page->assertSee('data-popup-close-month', false);
        $page->assertSee(__('accounts::dayclose.popup.title_month'));
        $page->assertDontSee('data-open-days', false); // ⓘ দিন বন্ধের চাবি নেই — দিনের ভাগ তাঁর নয়
        $page->assertSee(__('accounts::dayclose.popup.never_blocks'));

        // ⓘ "পরে মনে করাবেন" — নিয়ন্ত্রণ প্যানেলের সময় (১০ মিনিট) পর্যন্ত লুকায়, তারপর ফেরে
        app(SettingsService::class)->set('accounts.day_close.popup_every', '10');
        $this->post(route('notifications.banner.close'), ['later' => '1'])->assertRedirect();
        $this->get(route('accounts.period.index'))->assertOk()->assertDontSee('data-day-popup', false);
        Carbon::setTestNow('2026-11-17 10:11:00');
        $this->get(route('accounts.period.index'))->assertOk()->assertSee('data-open-month', false);

        // ⓘ কোনো বন্ধের চাবি নেই — মাসের খবর তাঁর নয়
        $reader = User::factory()->create(['is_active' => true]);
        $reader->companies()->attach($this->company->id, ['default_branch_id' => $this->a->id, 'is_active' => true]);
        $reader->givePermissionTo(Permission::findOrCreate('accounts.view', 'web'), Permission::findOrCreate('accounts.report', 'web'));
        $this->actingAs($reader->fresh());
        $this->get(route('accounts.control.month_end', ['month' => '2026-10']))->assertOk()->assertDontSee('data-open-month', false);
    }

    public function test_days_before_the_first_day_close_are_not_counted(): void
    {
        // ⓘ কোনো দিন বন্ধই নেই — দিন বন্ধ চালু হয়নি; দুই সারিই "প্রযোজ্য নয়"
        $this->book(self::OPEN_DAY);

        $this->assertSame(MonthEndChecklist::NOT_APPLICABLE, $this->row('days_closed')['state']);
        $this->assertSame(MonthEndChecklist::NOT_APPLICABLE, $this->row('days_changed')['state']);

        // ⓘ প্রথম দিন বন্ধ ৩১ অক্টোবর — তার আগের ২৯ তারিখ গোনা হয় না
        DayClose::query()->forceCreate(['company_id' => $this->company->id, 'branch_id' => null, 'day' => '2026-10-31',
            'closed_by' => $this->owner->id, 'closed_at' => now()]);

        $this->assertNotContains(self::OPEN_DAY, $this->row('days_closed')['dates'], '⛔ দিন বন্ধ চালুর আগের দিনও খোলা ধরা হলো');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function row(string $key): array
    {
        return collect(app(MonthEndChecklist::class)->run(\Carbon\CarbonImmutable::parse('2026-10-01')))->keyBy('key')[$key];
    }

    private function close(?string $reason = null): TestResponse
    {
        return $this->from(route('accounts.period.index'))->post(route('accounts.period.close'),
            array_filter(['year' => 2026, 'month' => 10, 'reason' => $reason], fn ($v) => $v !== null));
    }

    private function lock(): ?PeriodLock
    {
        return PeriodLock::query()->where('year', 2026)->where('month', 10)->first();
    }

    /**
     * প্রথম দিন বন্ধ থেকে মাস শেষ পর্যন্ত প্রতিটা দিন গোটা কোম্পানির জন্য বন্ধ — কেবল `$except` বাদে।
     *
     * @param  list<string>  $except
     */
    private function closeEveryDayOfTheWindowExcept(array $except): void
    {
        for ($d = Carbon::parse(self::FIRST); $d->lte(Carbon::parse('2026-10-31')); $d->addDay()) {
            if (in_array($d->toDateString(), $except, true)) {
                continue;
            }

            DayClose::query()->forceCreate(['company_id' => $this->company->id, 'branch_id' => null, 'day' => $d->toDateString(),
                'closed_by' => $this->owner->id, 'closed_at' => now()]);
        }
    }

    /** ⓘ বছরের অক্টোবরের আগের মাসগুলো বন্ধ — ক্রম মেনে ([[AnEarlierMonthStayedOpenTest]]) */
    private function lockMonthsBeforeOctober(): void
    {
        $year = FinancialYear::forDate('2026-10-01');
        $this->assertNotNull($year, 'ডেমোতে অক্টোবর ২০২৬ ধরা অর্থবছর লাগে');

        for ($cursor = Carbon::parse($year->starts_on)->startOfMonth(); $cursor->lt(Carbon::parse('2026-10-01')); $cursor->addMonthNoOverflow()) {
            PeriodLock::query()->forceCreate([
                'company_id' => $this->company->id, 'year' => (int) $cursor->year, 'month' => (int) $cursor->month,
                'locked_by' => $this->owner->id, 'locked_at' => now(),
            ]);
        }
    }

    /** ⓘ ডেমোর অক্টোবরের খসড়া আর অপেক্ষা সরানো — এখানে প্রশ্ন কেবল দিন বন্ধ আর বাকি কাজ ([[AMonthLocksOnlyWhenItIsDoneTest]]) */
    private function clearOctober(): void
    {
        Voucher::acrossBranches()->where('status', DocumentStatus::DRAFT)
            ->whereBetween('trx_date', ['2026-10-01', '2026-10-31'])
            ->update(['status' => DocumentStatus::CANCELLED]);
        Approval::query()->where('status', Approval::PENDING)->update(['status' => Approval::REJECTED]);
    }

    private function book(string $date): void
    {
        $id = ++$this->source;
        app(PostingEngine::class)->post('journal_voucher', $id, $date, [
            ['account_id' => $this->cash->id, 'debit' => '500', 'branch_id' => $this->a->id],
            ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'credit' => '500', 'branch_id' => $this->a->id],
        ], 'MC-'.$id, $this->a->id);
        Cache::flush();
    }
}
