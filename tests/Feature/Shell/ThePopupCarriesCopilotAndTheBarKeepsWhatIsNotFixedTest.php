<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Services\StatusNotices;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⭐ মালিক, ১৮ নভেম্বর ২০২৬ (রাত): পাতার উপরে কোনো ফিতা নয়; জরুরি খবর খোলার পপআপে, Copilot-এর সারাংশও পপআপে; পপআপ কাটার পরেও যা ঠিক
 * হয়নি তা নিচের চলন্ত বারে চলতেই থাকে (জরুরি লাল); আর একটা নোটিশের ✕ কেবল পরের ঘণ্টার পপআপ পর্যন্ত।
 *
 * দাবি: (১) Copilot-এর লাইন পপআপের ভেতরে, `assistant.use` থাকলে — না থাকলে লাইনটাই নেই, আর পাতার উপরে কোথাও নয়;
 * (২) বন্ধ-না-হওয়া দিন নিচের বারে লাল, পপআপ "পরে" দিয়ে কাটার পরেও; দিন বন্ধের চাবি না থাকলে বারে নেই;
 * (৩) একটা নোটিশের ✕ নিয়ন্ত্রণ প্যানেলের সময় পর্যন্ত লুকায়, তারপর ফেরে — গোটা লগইন নয়।
 */
final class ThePopupCarriesCopilotAndTheBarKeepsWhatIsNotFixedTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-16';

    private Company $company;

    private Branch $a;

    private User $owner;

    private Account $cash;

    private int $source = 9700;

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
        $this->paySupplier(self::DAY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_copilot_line_sits_inside_the_popup_and_nowhere_on_top_of_the_page(): void
    {
        $this->assertTrue($this->owner->fresh()->can('assistant.use'), '⛔ পরীক্ষার ভিত্তি নেই — মালিকের assistant.use নেই');

        $html = $this->get(route('accounts.voucher.list'))->assertOk()->getContent();

        $popup = strpos($html, 'data-day-popup');
        $line = strpos($html, 'data-popup-copilot');
        $this->assertNotFalse($popup, '⛔ পপআপই আসেনি');
        $this->assertNotFalse($line, '⛔ Copilot-এর লাইন পপআপে নেই');
        $this->assertGreaterThan($popup, $line, '⛔ Copilot-এর লাইন পপআপের বাইরে, পাতায়');
        $this->assertSame(1, substr_count($html, 'data-popup-copilot'), '⛔ Copilot-এর লাইন একবারের বেশি');
        $this->assertStringContainsString('data-copilot-ask="'.e(__('home.copilot_q_owed')).'"', $html, '⛔ প্রশ্নের চিপ নেই');
    }

    public function test_without_assistant_use_there_is_no_copilot_line_but_the_popup_still_comes(): void
    {
        $clerk = $this->closer();
        $this->assertFalse($clerk->can('assistant.use'));
        $this->actingAs($clerk);

        $html = $this->get(route('accounts.voucher.list'))->assertOk()->getContent();
        $this->assertStringContainsString('data-day-popup', $html, '⛔ দিন বন্ধের চাবিওয়ালা পপআপ পেলেন না');
        $this->assertStringNotContainsString('data-popup-copilot', $html, '⛔ জিজ্ঞাসার দরজা যার বন্ধ, তাকেও Copilot-এর প্রশ্ন দেখানো হলো');
    }

    public function test_an_open_day_keeps_running_red_in_the_bottom_bar_after_the_popup_is_put_off(): void
    {
        $this->post(route('notifications.banner.close'), ['later' => '1'])->assertRedirect();
        Cache::flush();

        $html = $this->get(route('accounts.voucher.list'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-day-popup', $html);

        $footer = substr($html, (int) strpos($html, '<footer data-footer'));
        $this->assertStringContainsString('16/11/2026', $footer, '⛔ পপআপ কাটার পরে বন্ধ-না-হওয়া দিনের খবর নিচের বারে নেই');
        $this->assertMatchesRegularExpression('/data-ticker-tone="danger">\s*<a[^>]*>\s*<span[^>]*><\/span>\s*[^<]*16\/11\/2026/u', $footer,
            '⛔ দিন বন্ধ হয়নি — নিচের বারে লাল নয়');
        $this->assertStringContainsString(e(route('executive.dayclose', ['date' => self::DAY])), $footer, '⛔ বারের খবরে দিন শেষের পাতার লিংক নেই');
    }

    public function test_without_the_close_key_the_bar_says_nothing_about_open_days(): void
    {
        $viewer = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $viewer->companies()->attach($this->company->id, ['default_branch_id' => $this->a->id, 'is_active' => true]);
        $viewer->givePermissionTo(Permission::findOrCreate('accounts.voucher.view', 'web'));
        $this->actingAs($viewer->fresh());

        $this->assertSame([], array_values(array_filter(app(StatusNotices::class)->forTicker(), fn (array $n) => str_contains($n['text'], '16/11/2026'))),
            '⛔ দিন বন্ধের চাবি ছাড়া মানুষের বারে দিন বন্ধের খবর');
    }

    public function test_a_single_notices_cross_hides_it_only_until_the_next_popup(): void
    {
        app(SettingsService::class)->set('accounts.day_close.popup_every', '10');
        $key = sha1('Some urgent words');

        $this->post(route('notifications.banner.close'), ['key' => $key])->assertRedirect();
        $this->assertContains($key, StatusNotices::closedKeys(), '⛔ ✕ দেওয়া নোটিশ লুকাল না');

        Carbon::setTestNow('2026-11-17 10:09:00');
        $this->assertContains($key, StatusNotices::closedKeys());

        Carbon::setTestNow('2026-11-17 10:11:00');
        $this->assertNotContains($key, StatusNotices::closedKeys(), '⛔ ✕ গোটা লগইনের জন্য লুকায় — পরের পপআপে ফিরল না');
    }

    public function test_with_the_popup_switched_off_a_cross_still_comes_back_within_the_hour(): void
    {
        app(SettingsService::class)->set('accounts.day_close.popup_every', 'off');
        $this->assertSame(60, StatusNotices::snoozeMinutes(), '⛔ "দেখাবে না" হলে জরুরি খবর চিরকাল চুপ');

        // ⓘ আগের ধরনের জমা (সময় ছাড়া চাবির তালিকা) — পেরোনো ধরা হয়, ভাঙে না
        session()->put(StatusNotices::CLOSED, [sha1('old')]);
        $this->assertSame([], StatusNotices::closedKeys());
    }

    private function closer(): User
    {
        $clerk = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $clerk->companies()->attach($this->company->id, ['default_branch_id' => $this->a->id, 'is_active' => true]);
        $clerk->givePermissionTo(
            Permission::findOrCreate('accounts.report', 'web'),
            Permission::findOrCreate('accounts.voucher.view', 'web'),
            Permission::findOrCreate('accounts.day_close.view', 'web'),
            Permission::findOrCreate('accounts.day_close.close', 'web'),
        );

        return $clerk->fresh();
    }

    private function paySupplier(string $date): void
    {
        $supplier = Supplier::query()->onlySuppliers()->orderBy('id')->firstOrFail();
        $id = ++$this->source;
        app(PostingEngine::class)->post('payment_voucher', $id, $date, [
            ['account_id' => StandardChart::find(StandardChart::PAYABLE)->id, 'debit' => '1000', 'party_type' => 'supplier', 'party_id' => $supplier->id, 'branch_id' => $this->a->id],
            ['account_id' => $this->cash->id, 'credit' => '1000', 'branch_id' => $this->a->id],
        ], 'T-'.$id, $this->a->id);
    }
}
