<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Services\StatusNotices;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notice;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ⭐ নোটিশ বারের নিচে স্থির ফিতায় — SAP Fiori Message Strip (ওয়েবের মান ধাপ ২, মালিকের আদেশ "100% int. standard" আর
 * অনুমোদিত নমুনা, ২০ অক্টোবর ২০২৬: E:\ABOS\মালিকের-ফাইল\পুরো-পাতার-নমুনা-ওয়েব-ও-অ্যাপ-২০-অক্টোবর.pdf, পাতা ১, ভুল ৮)।
 *
 * ⓘ আগে (১১ অক্টোবর থেকে) সব নোটিশ ফুটারের চলন্ত বারে ঘুরত (EveryNoticeRunsInTheFooterTest, TheNoticeRunsTest — এই
 * ফাইল ওই দুইটার জায়গা নিল)। নমুনায় ফুটারে কেবল সংস্করণ · © · নাম, আর সতর্কতা উপরে, স্থির, "বিস্তারিত" আর ✕ সহ।
 *
 * দাবি, আসল পাতার দরজা দিয়ে:
 *  - যন্ত্রের সতর্কতা (ব্যাকআপ বাসি, খসড়া পড়ে আছে) ফিতায়, ফুটারে নয়, ঘণ্টাতেও থাকে; ঘোষণা না থাকলে চলন্ত বারের চিহ্নও পাতায় নেই।
 *  - মানুষের লেখা নোটিশ, "জরুরি" টিক দেওয়া, ফিতায় — যন্ত্রের সতর্কতার আগে (২১ অক্টোবর ২০২৬ থেকে জরুরি নয় এমন ঘোষণা নিচের
 *    চলন্ত বারে: [[AlertsStandAtTheTopAnnouncementsRunAtTheBottomTest]])।
 *  - ✕ চাপলে এই লগইনে ফিতা থেকে সরে, ঘণ্টায় থাকে।
 *  - তিনটার বেশি হলে তিনটা ফিতা আর "আরও Nটা ঘণ্টায়"।
 *  - কিছু না থাকলে ফিতাই নেই।
 */
final class NoticesStandInTheTopStripTest extends TestCase
{
    use RefreshDatabase;

    private string $empty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        // ⓘ ব্যাকআপের ফোল্ডার খালি — "ব্যাকআপ পুরনো" লাল সতর্কতা আসে (মালিকের, যিনি ব্যাকআপ দেখেন)
        $this->empty = storage_path('framework/testing/strip-backup');
        File::ensureDirectoryExists($this->empty);
        File::cleanDirectory($this->empty);
        config()->set('abos.backup.path', $this->empty);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->empty);
        parent::tearDown();
    }

    public function test_machine_warnings_stand_in_the_strip_not_the_footer_and_stay_in_the_bell(): void
    {
        $warning = __('core.notice.backup_stale');
        $this->actingAs($this->owner());

        [$first, $second] = Account::query()->postable()->active()->whereNull('money_kind')->orderBy('code')->limit(2)->get()->all();
        app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'ফিতা-পরীক্ষার খসড়া',
                'branch_id' => Company::query()->where('code', 'TDEPOT')->firstOrFail()->defaultBranch()->id],
            [['account_id' => $first->id, 'debit' => '1000', 'credit' => '0'], ['account_id' => $second->id, 'debit' => '0', 'credit' => '1000']],
        );
        Cache::flush();
        $all = app(StatusNotices::class)->all();
        $this->assertContains($warning, array_column($all, 'text'), '⛔ পরীক্ষার ভিত্তি নেই — ব্যাকআপের সতর্কতাই আসেনি।');
        $drafts = collect($all)->firstWhere('tone', 'pending')['text'] ?? null;
        $this->assertNotNull($drafts, '⛔ পরীক্ষার ভিত্তি নেই — খসড়ার সতর্কতা আসেনি।');
        Cache::flush();

        $html = $this->get(route('dashboard'))->assertOk()->getContent();
        $strip = $this->part($html, '<div data-message-strips', '<aside');
        $footer = $this->part($html, '<footer data-footer', '</footer>');

        $this->assertStringContainsString(e($warning), $strip, '⛔ যন্ত্রের সতর্কতা উপরের ফিতায় নেই।');
        $this->assertStringContainsString(e($drafts), $strip, '⛔ খসড়ার সতর্কতা উপরের ফিতায় নেই।');
        $this->assertStringNotContainsString(e($warning), $footer, '⛔ সতর্কতা আবার ফুটারে।');
        $this->assertStringNotContainsString('abos-ticker', $html, '⛔ চলন্ত বার ফিরে এসেছে।');
        $this->assertStringContainsString(e($warning), $this->bell($html), '⛔ সতর্কতা ঘণ্টা থেকে হারাল।');
        $this->assertStringContainsString(e($drafts), $this->bell($html), '⛔ খসড়ার সতর্কতা ঘণ্টা থেকে হারাল।');

        // ⓘ প্রতিটা ফিতায় নিজের ✕, নাম সহ; যার পাতা আছে তার "বিস্তারিত"
        $this->assertStringContainsString('aria-label="'.e(__('core.notice.close')).': '.e($drafts).'"', $strip, '⛔ ✕-এর নাম নেই।');
        $this->assertStringContainsString(e(__('core.notice.details')), $strip, '⛔ খসড়ার ফিতায় "বিস্তারিত" নেই।');
    }

    public function test_a_notice_people_wrote_stands_first(): void
    {
        // ⓘ "জরুরি" টিক — ২১ অক্টোবর ২০২৬ থেকে জরুরি নয় এমন ঘোষণা ফিতায় নয়, নিচের চলন্ত বারে
        // ([[AlertsStandAtTheTopAnnouncementsRunAtTheBottomTest]])
        Notice::create(['title' => 'রবিবার থেকে দাম বাড়বে', 'body' => 'রবিবার থেকে দাম বাড়বে', 'is_active' => true,
            'in_ticker' => true, 'is_urgent' => true, 'created_by' => $this->owner()->id]);
        Cache::flush();

        $html = $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();
        $strip = $this->part($html, '<div data-message-strips', '<aside');

        $own = strpos($strip, 'রবিবার থেকে দাম বাড়বে');
        $machine = strpos($strip, e(__('core.notice.backup_stale')));
        $this->assertNotFalse($own, '⛔ মালিকের নোটিশ ফিতায় নেই।');
        $this->assertNotFalse($machine, '⛔ পরীক্ষার ভিত্তি নেই — যন্ত্রের সতর্কতা ফিতায় নেই।');
        $this->assertLessThan($machine, $own, '⛔ যন্ত্রের সতর্কতা মালিকের নোটিশের আগে।');
    }

    public function test_closing_a_strip_hides_it_for_this_login_but_the_bell_keeps_it(): void
    {
        $warning = __('core.notice.backup_stale');
        $this->actingAs($this->owner());

        $this->post(route('notifications.banner.close'), ['key' => sha1($warning)])->assertRedirect();
        Cache::flush();

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString(e($warning), $this->part($html, '<div data-message-strips', '<aside', allowMissing: true),
            '⛔ ✕ চাপার পরেও ফিতা ফিরে এল।');
        $this->assertStringContainsString(e($warning), $this->bell($html), '⛔ ✕ সতর্কতাটাই মুছে দিল — ঘণ্টায় নেই।');
    }

    public function test_more_than_three_show_three_and_point_to_the_bell(): void
    {
        foreach (['এক', 'দুই', 'তিন', 'চার'] as $word) {
            Notice::create(['title' => "নোটিশ {$word}", 'body' => "নোটিশ {$word}", 'is_active' => true,
                'in_ticker' => true, 'is_urgent' => true, 'created_by' => $this->owner()->id]);
        }
        Cache::flush();

        $html = $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();
        $strip = $this->part($html, '<div data-message-strips', '<aside');

        $this->assertSame(3, substr_count($strip, 'data-message-strip='), '⛔ তিনটার বেশি ফিতা — পাতাটাই ঢাকা পড়ে।');
        $this->assertStringContainsString('data-strip-more', $strip, '⛔ বাকিগুলো কোথায় তা বলা নেই।');
    }

    public function test_nothing_to_say_draws_no_strip(): void
    {
        Cache::flush();
        $company = (int) $this->owner()->current_company_id;

        foreach ([null, ...\App\Models\Branch::query()->withoutGlobalScopes()->where('company_id', $company)->pluck('id')->all()] as $branch) {
            CompanyContext::set($company, $branch === null ? null : (int) $branch);
            Cache::put(StatusNotices::cacheKey($company, (int) $this->owner()->id), [], 60);
        }

        $html = $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-message-strips', $html, '⛔ কিছু নেই, তবু ফিতা আঁকা হয়েছে।');
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    private function bell(string $html): string
    {
        // ⓘ ঘণ্টা বারের ভেতরে, ফিতার আগে — বারের শেষেই থামা, নইলে ফিতার লেখাও "ঘণ্টায়" গোনা হত
        return $this->part($html, 'data-notification-bell', '</header>');
    }

    /** পাতার একটা টুকরো — শুরুর চিহ্ন থেকে শেষের চিহ্ন পর্যন্ত */
    private function part(string $html, string $from, string $to, bool $allowMissing = false): string
    {
        $start = strpos($html, $from);

        if ($start === false) {
            $this->assertTrue($allowMissing, "⛔ পাতায় {$from} নেই।");

            return '';
        }

        $end = strpos($html, $to, $start + strlen($from));

        return substr($html, $start, $end === false ? null : $end - $start + strlen($to));
    }
}
