<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DayClose;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\DayChecklist;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⭐ দিন বন্ধের চেকলিস্ট আর মাঝখানের সতর্কতা — মালিক, ১৮ নভেম্বর ২০২৬:
 * *"পরের দিন সকালবেলায় … এই চেকলিস্টে সামনে চলে আসবে … কেটে দিতে পারবে … এক ঘন্টা পর পর আবার আসবে"*,
 * আর *"কাজ যাতে কোনোভাবে আটকে না থাকে"*।
 *
 * দাবিগুলো: (১) কাজ হওয়া অথচ বন্ধ না হওয়া কর্মদিবস পরের দিন পাতার মাঝখানে আসে, চেকলিস্ট আর লিংকসহ — পাতা নিজে চলে;
 * (২) ✕ নিয়ন্ত্রণ প্যানেলের সময় পর্যন্ত লুকায়, তারপর ফেরে; (৩) বন্ধ দিন আর সাপ্তাহিক ছুটি আসে না; (৪) দিন বন্ধের চাবি না থাকলে বা
 * সুইচ "দেখাবে না" হলে আসে না; (৫) কর্মীর হাতে টাকা নাম ধরে, মালিকের নিজের নগদ নয়; (৬) গত মাস বন্ধ না হলে বলে, তালা পড়লে চুপ;
 * (৭) দিন শেষের হিসাবের পাতায় বন্ধের বোতামের উপরে একই চেকলিস্ট।
 */
final class TheOpenDayComesBackAsAReminderTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-16'; // ⓘ সোমবার; ১৩ নভেম্বর শুক্রবার (সাপ্তাহিক ছুটি)

    private Company $company;

    private Branch $a;

    private User $owner;

    private Account $cash;

    private int $source = 9400;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Carbon::setTestNow('2026-11-17 10:00:00');

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        CompanyContext::set($this->company->id, null);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['locale' => 'en'])->save();
        $this->actingAs($this->owner->fresh());
        app()->setLocale('en');

        app(StandardChart::class)->install();
        $this->cash = app(AccountService::class)->create(['name_en' => 'Head office cash', 'parent_id' => StandardChart::find(StandardChart::CASH_IN_HAND)->id, 'held_by' => $this->owner->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_open_working_day_pops_up_next_morning_with_its_checklist_and_the_page_still_works(): void
    {
        $this->paySupplier(self::DAY);

        $page = $this->get(route('accounts.voucher.list'));

        $page->assertOk();
        $page->assertSee('data-day-popup', false);
        $page->assertSee('16/11/2026');
        $page->assertSee('data-check="not_counted"', false);
        $page->assertSee('data-check="supplier_paid"', false);
        $page->assertSee(route('executive.dayclose', ['date' => self::DAY]), false);
        $page->assertSee(__('accounts::dayclose.popup.never_blocks'));
    }

    public function test_the_cross_hides_it_for_the_set_time_and_then_it_comes_back(): void
    {
        app(SettingsService::class)->set('accounts.day_close.popup_every', '10');
        $this->paySupplier(self::DAY);

        $this->post(route('executive.dayclose.snooze'))->assertRedirect();
        $this->get(route('accounts.voucher.list'))->assertOk()->assertDontSee('data-day-popup', false);

        Carbon::setTestNow('2026-11-17 10:09:00');
        $this->get(route('accounts.voucher.list'))->assertDontSee('data-day-popup', false);

        Carbon::setTestNow('2026-11-17 10:11:00');
        $this->get(route('accounts.voucher.list'))->assertSee('data-day-popup', false);
    }

    public function test_a_closed_day_and_the_weekly_holiday_do_not_pop_up(): void
    {
        $this->paySupplier('2026-11-13'); // শুক্রবার
        $this->paySupplier(self::DAY);
        $this->assertSame([self::DAY], array_map(fn ($d) => $d->toDateString(), app(DayChecklist::class)->openDays(Carbon::today())),
            '⛔ সাপ্তাহিক ছুটির দিনকেও বন্ধ-না-হওয়া কর্মদিবস ধরা হলো');

        DayClose::query()->forceCreate(['company_id' => $this->company->id, 'branch_id' => $this->a->id, 'day' => self::DAY,
            'closed_by' => $this->owner->id, 'closed_at' => now()]);

        $this->assertSame([], app(DayChecklist::class)->openDays(Carbon::today()), '⛔ শাখা দিন বন্ধ করার পরেও দিনটা "বন্ধ হয়নি" দেখাচ্ছে');
        $this->get(route('accounts.voucher.list'))->assertOk()->assertDontSee('data-open-days', false);
    }

    public function test_without_the_close_key_or_with_the_switch_off_nothing_pops_up(): void
    {
        $this->paySupplier(self::DAY);

        $clerk = User::factory()->create(['is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['default_branch_id' => $this->a->id, 'is_active' => true]);
        $clerk->givePermissionTo(Permission::findOrCreate('accounts.report', 'web'), Permission::findOrCreate('accounts.day_close.view', 'web'));
        $this->actingAs($clerk->fresh());
        $this->get(route('accounts.voucher.list'))->assertDontSee('data-open-days', false);

        $this->actingAs($this->owner->fresh());
        app(SettingsService::class)->set('accounts.day_close.popup_every', '0');
        $this->get(route('accounts.voucher.list'))->assertOk()->assertDontSee('data-open-days', false);
    }

    public function test_cash_held_by_staff_is_named_and_the_owners_own_cash_is_not(): void
    {
        $this->paySupplier(self::DAY);
        $this->book([
            ['account_id' => $this->cash->id, 'debit' => '7000', 'branch_id' => $this->a->id],
            ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'credit' => '7000', 'branch_id' => $this->a->id],
        ], self::DAY, 'journal_voucher');

        $items = collect(app(DayChecklist::class)->for(Carbon::parse(self::DAY)))->keyBy('key');
        $this->assertFalse($items->has('staff_cash'), '⛔ মালিকের নিজের নগদকে কর্মীর হাতে টাকা ধরা হলো');

        $rafiq = User::factory()->create(['name' => 'Rafiq Cashier', 'is_active' => true]);
        $this->cash->forceFill(['held_by' => $rafiq->id])->save();

        $items = collect(app(DayChecklist::class)->for(Carbon::parse(self::DAY)))->keyBy('key');
        $this->assertTrue($items->has('staff_cash'), '⛔ কর্মীর হাতে টাকা চেকলিস্টে আসেনি');
        $this->assertSame(0, bccomp((string) $items['staff_cash']['amount'], '6000', 2), '⛔ কর্মীর হাতের টাকা দিনের শেষ জের নয় (৭,০০০ এল − ১,০০০ গেল)');
        $this->assertStringContainsString('Rafiq Cashier', implode(' ', $items['staff_cash']['names']), '⛔ কার হাতে টাকা, নাম নেই');
    }

    public function test_last_month_not_closed_is_said_and_the_lock_silences_it(): void
    {
        $this->paySupplier('2026-10-20');

        $this->assertSame('2026-10', app(DayChecklist::class)->openMonth(Carbon::today())?->format('Y-m'), '⛔ গত মাস বন্ধ হয়নি, বলা হলো না');
        $this->get(route('accounts.voucher.list'))->assertSee('data-open-month', false);

        PeriodLock::query()->forceCreate(['company_id' => $this->company->id, 'year' => 2026, 'month' => 10, 'locked_by' => $this->owner->id, 'locked_at' => now()]);
        $this->assertNull(app(DayChecklist::class)->openMonth(Carbon::today()), '⛔ মাস বন্ধের পরেও "বন্ধ হয়নি" বলছে');
    }

    public function test_the_day_closing_page_shows_the_same_checklist_above_the_close_button(): void
    {
        $this->paySupplier(self::DAY);

        $page = $this->get(route('executive.dayclose', ['date' => self::DAY]));

        $page->assertOk();
        $page->assertSee('data-day-check', false);
        $page->assertSee('data-check="not_counted"', false);
        $page->assertDontSee('data-day-popup', false); // ⓘ নিজের পাতায় পপআপ নয়
        $html = $page->getContent();
        $this->assertLessThan(strpos($html, 'data-day-close-form'), strpos($html, 'data-day-check'), '⛔ চেকলিস্ট বন্ধের বোতামের উপরে নেই');
    }

    /** ⭐ মালিক, ১৯ নভেম্বর ২০২৬: ফিতা পাতার উপরে নয় — খোলার সময়ের পপআপে; "পরে" চাপলে পুরো পপআপ লুকায়, সময় পেরোলে ফেরে */
    public function test_urgent_news_comes_in_the_popup_not_on_top_of_the_page_and_later_hides_it(): void
    {
        $page = $this->get(route('accounts.voucher.list'));
        $page->assertOk();
        $html = $page->getContent();

        $this->assertStringContainsString('data-popup-news', $html, '⛔ জরুরি খবর (ব্যাকআপ) পপআপে আসেনি');
        $news = strpos($html, 'data-popup-news');
        $this->assertSame(strpos($html, 'data-message-strips'), strpos($html, 'data-message-strips', $news),
            '⛔ ফিতাটা এখনো পপআপের বাইরে, পাতার উপরে আঁকা');

        $this->post(route('notifications.banner.close'), ['later' => '1'])->assertRedirect();
        $this->get(route('accounts.voucher.list'))->assertDontSee('data-day-popup', false);

        Carbon::setTestNow('2026-11-17 11:01:00');
        $this->get(route('accounts.voucher.list'))->assertSee('data-popup-news', false);
    }

    /** ⭐ অফিসের ম্যাকের রসিদ দ্বিতীয় কপি — টাটকা হলে "একই ডিস্কে" আর আসে না, দুই দিনের পুরনো হলে "কপি থেমে গেছে" */
    public function test_the_office_mac_receipt_counts_as_the_second_copy_until_it_goes_stale(): void
    {
        config(['abos.backup.mirror' => null]);
        $receipt = storage_path('app/backup-offsite.json');
        $words = fn () => array_column(app(\App\Core\Services\StatusNotices::class)->all(), 'text');

        try {
            @unlink($receipt);
            $this->assertContains(__('core.notice.backup_no_mirror'), $words(), '⛔ রসিদ না থাকলেও "একই ডিস্কে" বলছে না');

            file_put_contents($receipt, json_encode(['at' => now()->subHours(5)->toIso8601String(), 'where' => 'office Mac']));
            \App\Core\Services\StatusNotices::forget((int) $this->company->id, (int) $this->owner->id);
            $this->assertNotContains(__('core.notice.backup_no_mirror'), $words(), '⛔ ম্যাকের টাটকা কপির পরেও "একই ডিস্কে" বলছে');
            $this->assertNotContains(__('core.notice.backup_mirror_stale'), $words());

            file_put_contents($receipt, json_encode(['at' => now()->subDays(3)->toIso8601String(), 'where' => 'office Mac']));
            \App\Core\Services\StatusNotices::forget((int) $this->company->id, (int) $this->owner->id);
            $this->assertContains(__('core.notice.backup_mirror_stale'), $words(), '⛔ ম্যাকের কপি তিন দিন থেমে, অথচ চুপ');
        } finally {
            @unlink($receipt);
        }
    }

    public function test_the_staff_member_holding_cash_is_told_in_the_afternoon_once_and_the_owner_is_not(): void
    {
        Carbon::setTestNow(self::DAY.' 17:00:00');
        $this->book([
            ['account_id' => $this->cash->id, 'debit' => '7000', 'branch_id' => $this->a->id],
            ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'credit' => '7000', 'branch_id' => $this->a->id],
        ], self::DAY, 'journal_voucher');

        $this->artisan('abos:staff-cash-afternoon')->assertSuccessful();
        $this->assertSame(0, \App\Models\Notification::query()->where('type', 'accounts.staff_cash_held')->count(), '⛔ মালিকের নিজের নগদের খবর গেল');

        $rafiq = User::factory()->create(['name' => 'Rafiq Cashier', 'is_active' => true, 'locale' => 'en']);
        $rafiq->companies()->attach($this->company->id, ['default_branch_id' => $this->a->id, 'is_active' => true]);
        $this->cash->forceFill(['held_by' => $rafiq->id])->save();

        $this->artisan('abos:staff-cash-afternoon')->assertSuccessful();
        $this->artisan('abos:staff-cash-afternoon')->assertSuccessful();

        $sent = \App\Models\Notification::query()->where('type', 'accounts.staff_cash_held')->get();
        $this->assertCount(1, $sent, '⛔ কর্মীর হাতে টাকার খবর দিনে একবারের বেশি গেল, বা যায়নি');
        $this->assertSame($rafiq->id, (int) $sent[0]->user_id, '⛔ খবর জিম্মাদার কর্মীর কাছে যায়নি');
        $this->assertStringContainsString('7,000', (string) $sent[0]->title, '⛔ খবরে টাকার অঙ্ক নেই');
    }

    private function paySupplier(string $date): void
    {
        $supplier = Supplier::query()->onlySuppliers()->orderBy('id')->firstOrFail();
        $this->book([
            ['account_id' => StandardChart::find(StandardChart::PAYABLE)->id, 'debit' => '1000', 'party_type' => 'supplier', 'party_id' => $supplier->id, 'branch_id' => $this->a->id],
            ['account_id' => $this->cash->id, 'credit' => '1000', 'branch_id' => $this->a->id],
        ], $date);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function book(array $lines, string $date, string $type = 'payment_voucher'): void
    {
        $id = ++$this->source;
        app(PostingEngine::class)->post($type, $id, $date, $lines, 'T-'.$id, $this->a->id);
    }
}
