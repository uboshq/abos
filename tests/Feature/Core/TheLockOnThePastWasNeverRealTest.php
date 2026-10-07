<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * পেছনের তারিখের তালাটা কোনোদিন সত্যি ছিল না।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * Control Panel-এ ঘরটা ছিল — *"কত দিন পেছনের তারিখে এন্ট্রি নেওয়া
 * যাবে"*, ডিফল্ট ৭। সংখ্যাটা জমা হত, ফিরেও আসত, আর মালিক পর্দায় দেখে
 * ধরে নিতেন তালাটা কাজ করছে।
 *
 * **`accounts.backdate_days` কোথাও পড়াই হত না।** চারটা টেস্ট ছিল, আর
 * চারটাই কেবল দেখত সংখ্যাটা সংরক্ষিত হয় কি না — একটাও পুরনো তারিখের
 * এন্ট্রি বসিয়ে দেখেনি যে আটকায় কি না। এটাই সেই শ্রেণির ভুল যেটা এই
 * প্রকল্প বারবার খুঁজে বেড়ায়: **পাহারাটা সত্যি ছিল কেবল যতক্ষণ
 * জিনিসটা অনুপস্থিত ছিল।**
 *
 * আর মাস বন্ধ করার কোনো উপায়ই ছিল না — অর্থবছর বন্ধ করা যেত, তার নিচে
 * কিছু নয়। ফলে জুনের রিপোর্ট সবাইকে পাঠানোর পরেও জুনে ভাউচার বসত, আর
 * পরদিন একই রিপোর্ট অন্য সংখ্যা দেখাত।
 */
class TheLockOnThePastWasNeverRealTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'accounts@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
    }

    /**
     * খতিয়ানে কয়টা সারি — ডেমোর নিজেরগুলো বাদ দিয়ে।
     *
     * ডেমো সিডার নিজেই খতিয়ানে সারি বসায় (খোলা মজুদ, নমুনা লেনদেন)।
     * মোট সংখ্যা গুনলে পরীক্ষাটা সিডারের সাথে বাঁধা পড়ত, আর ডেমোতে
     * একটা সারি যোগ হলেই লাল হত — অথচ কোডে কিছুই বদলায়নি।
     */
    private function ledgerCount(): int
    {
        return LedgerEntry::query()->where('source_type', 'journal_voucher')->count();
    }

    /** একটা সাধারণ জাবেদা, দেওয়া তারিখে। */
    private function postOn(Carbon|string $date): Voucher
    {
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $date instanceof Carbon ? $date->toDateString() : $date],
            [
                ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => '500'],
                ['account_id' => StandardChart::find(StandardChart::PAYABLE)->id, 'credit' => '500'],
            ],
        );

        return app(VoucherService::class)->post($voucher);
    }

    private function closeMonth(Carbon $month, string $reason = 'রিপোর্ট পাঠানো হয়ে গেছে'): PeriodLock
    {
        return PeriodLock::query()->create([
            'company_id' => $this->company->id,
            'year' => (int) $month->year,
            'month' => (int) $month->month,
            'reason' => $reason,
            'locked_by' => auth()->id(),
            'locked_at' => now(),
        ]);
    }

    // ── পেছনের জানালা ───────────────────────────────────────────────

    /** আজকের এন্ট্রি চলে — পাহারাটা রোজকার কাজ থামায় না। */
    public function test_todays_entry_passes(): void
    {
        $this->postOn(Carbon::today());

        $this->assertSame(2, $this->ledgerCount());
    }

    /** জানালার ভেতরের তারিখও চলে। */
    public function test_a_date_inside_the_window_passes(): void
    {
        app(SettingsService::class)->set('accounts.backdate_days', 7);

        $this->postOn(Carbon::today()->subDays(3));

        $this->assertSame(2, $this->ledgerCount());
    }

    /**
     * জানালার বাইরের তারিখ আটকায় — আর এটাই আগে হত না।
     */
    public function test_a_date_beyond_the_window_is_refused(): void
    {
        app(SettingsService::class)->set('accounts.backdate_days', 7);

        try {
            $this->postOn(Carbon::today()->subDays(40));

            $this->fail('৪০ দিন আগের তারিখে এন্ট্রি বসে গেছে — জানালার সীমা কিছুই আটকায়নি।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('trx_date', $e->errors());
        }

        $this->assertSame(0, $this->ledgerCount(),
            'আটকানোর পরেও খতিয়ানে সারি বসেছে।');
    }

    /**
     * সেটিংটা সত্যিই পড়া হয় — সংখ্যাটা বদলালে আচরণও বদলায়।
     *
     * ── কেন এই পরীক্ষাটা আলাদা করে ──────────────────────────────────
     * আগের চারটা টেস্ট কেবল দেখত সংখ্যাটা জমা হয় কি না। জমা হওয়া আর
     * **কাজে লাগা** এক জিনিস নয়, আর ঠিক ওই ফাঁকেই ভুলটা এক বছর টিকে
     * ছিল।
     */
    public function test_the_setting_actually_changes_what_happens(): void
    {
        app(SettingsService::class)->set('accounts.backdate_days', 60);

        $this->postOn(Carbon::today()->subDays(40));

        $this->assertSame(2, $this->ledgerCount(),
            '৬০ দিনের জানালাতেও ৪০ দিন আগের এন্ট্রি আটকে গেছে।');
    }

    /** অনুমতি থাকলে জানালাটা উঠে যায় — পুরনো বিল বসানোর দায়িত্ব যাঁর। */
    public function test_the_override_permission_opens_the_window(): void
    {
        app(SettingsService::class)->set('accounts.backdate_days', 7);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->postOn(Carbon::today()->subDays(40));

        $this->assertSame(2, $this->ledgerCount());
    }

    // ── মাসের তালা ──────────────────────────────────────────────────

    /**
     * বন্ধ মাসে কিছুই বসে না — অনুমতি থাকলেও নয়।
     *
     * ── কেন অনুমতি এখানে খাটে না ────────────────────────────────────
     * জানালাটা ভুল ঠেকায়, তালাটা ছাপা হয়ে যাওয়া হিসাব রক্ষা করে।
     * অনুমতি দিয়ে তালা ডিঙানো গেলে ওটা তালাই নয় — এক মুহূর্তে ঢুকে
     * এন্ট্রি বসিয়ে বেরিয়ে আসা যেত, আর কেউ জানত না।
     */
    public function test_a_closed_month_refuses_even_the_owner(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $lastMonth = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        $this->closeMonth($lastMonth);

        try {
            $this->postOn($lastMonth->copy()->addDays(5));

            $this->fail('বন্ধ মাসে এন্ট্রি বসে গেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('trx_date', $e->errors());
        }

        $this->assertSame(0, $this->ledgerCount());
    }

    /** বন্ধ মাসের বার্তায় মাসটার নাম ও কারণ দুইটাই থাকে। */
    public function test_the_message_names_the_month_and_the_reason(): void
    {
        $lastMonth = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        $lock = $this->closeMonth($lastMonth, 'ভ্যাট দাখিল হয়ে গেছে');

        try {
            $this->postOn($lastMonth->copy()->addDays(5));
            $this->fail('বন্ধ মাসে এন্ট্রি বসে গেছে।');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->errors()['trx_date']);

            $this->assertStringContainsString($lock->label(), $message);
            $this->assertStringContainsString('ভ্যাট দাখিল হয়ে গেছে', $message);
        }
    }

    /** পাশের খোলা মাস অক্ষত — তালাটা কেবল নিজের মাসেই। */
    public function test_the_month_beside_it_stays_open(): void
    {
        $this->closeMonth(Carbon::today()->subMonthNoOverflow()->startOfMonth());

        $this->postOn(Carbon::today());

        $this->assertSame(2, $this->ledgerCount());
    }

    // ── পর্দা ───────────────────────────────────────────────────────

    /** মাসের তালিকাটা পর্দায় আছে, আর বন্ধ মাসটা বন্ধ দেখায়। */
    public function test_the_screen_lists_the_months(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $lock = $this->closeMonth(Carbon::today()->subMonthNoOverflow()->startOfMonth());

        $this->get(route('accounts.period.index'))
            ->assertOk()
            ->assertSee($lock->label())
            ->assertSee(__('accounts::field.closed'));
    }

    /** চলতি মাস বন্ধ করা যায় না — আজকের বিক্রিই থেমে যেত। */
    public function test_this_month_cannot_be_closed(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->post(route('accounts.period.close'), [
            'year' => (int) now()->year,
            'month' => (int) now()->month,
        ])->assertSessionHasErrors('month');

        $this->assertDatabaseCount('period_locks', 0);
    }

    /**
     * খোলার সময় কারণ লাগে — নাহলে ছয় মাস পরে প্রশ্নের উত্তর থাকে না।
     */
    public function test_reopening_needs_a_reason(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $lock = $this->closeMonth(Carbon::today()->subMonthNoOverflow()->startOfMonth());

        $this->post(route('accounts.period.reopen', $lock))
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('period_locks', 1);
    }

    /** খোলা হলে কারণটা অডিটে থেকে যায়, যদিও সারিটা মুছে যায়। */
    public function test_reopening_leaves_its_reason_in_the_audit(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $lock = $this->closeMonth(Carbon::today()->subMonthNoOverflow()->startOfMonth());

        $this->post(route('accounts.period.reopen', $lock), [
            'reason' => 'সরবরাহকারীর একটা বিল দেরিতে এসেছে',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('period_locks', 0);

        $this->assertDatabaseHas('audit_trails', [
            'auditable_type' => PeriodLock::class,
            'auditable_id' => $lock->id,
            'action' => 'reopened',
            'reason' => 'সরবরাহকারীর একটা বিল দেরিতে এসেছে',
        ]);
    }

    /** খোলার পর ওই মাসে আবার এন্ট্রি বসে। */
    public function test_after_reopening_the_month_takes_entries_again(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $lastMonth = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        $lock = $this->closeMonth($lastMonth);

        $this->post(route('accounts.period.reopen', $lock), ['reason' => 'দেরিতে আসা বিল']);

        $this->postOn($lastMonth->copy()->addDays(5));

        $this->assertSame(2, $this->ledgerCount());
    }
}
