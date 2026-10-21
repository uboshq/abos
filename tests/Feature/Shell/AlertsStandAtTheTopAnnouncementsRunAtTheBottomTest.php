<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Services\NoticeBoard;
use App\Core\Services\SettingsService;
use App\Core\Services\StatusNotices;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Branch;
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
 * ⭐ জরুরি সতর্কতা উপরে স্থির, ঘোষণা নিচে চলন্ত — মালিক, ২১ অক্টোবর ২০২৬ (fe-র মাধ্যমে)।
 *
 * ⓘ ২০ অক্টোবর (ওয়েবের মান ধাপ ২) সব নোটিশ উপরের স্থির ফিতায় উঠেছিল আর ফুটারে কেবল সংস্করণ · © · নাম
 * ([[NoticesStandInTheTopStripTest]])। মালিকের নতুন ভাগ: যন্ত্রের সতর্কতা আর কাজের নোটিশ উপরে স্থির থাকে, মানুষের লেখা
 * ঘোষণা (নোটিশ বোর্ড) নিচের ফুটারে চলে; লেখক "জরুরি" টিক দিলে ঘোষণাটা উপরে, চলন্ত বারে নয়।
 *
 * দাবি, আসল পাতার দরজা দিয়ে (ড্যাশবোর্ড, নোটিশের আসল ফর্ম):
 *  - যন্ত্রের সতর্কতা (ব্যাকআপ বাসি, খসড়া পড়ে আছে) কখনো চলন্ত বারে নয় — উপরের ফিতায়।
 *  - জরুরি নয় এমন ঘোষণা ফিতায় নয় — চলন্ত বারে, আর ক্লিক করলে পুরো নোটিশ খোলে।
 *  - ফর্মে "জরুরি" টিক দিলে ঘোষণা ফিতায় ওঠে, চলন্ত বার থেকে নামে; টিক তুললে ফেরে — দুইবারই নিরীক্ষায় আগে/পরে।
 *  - কোনো ঘোষণা নেই → চলন্ত বারের কোনো মার্কআপই নেই, ফুটারে কেবল সংস্করণ · © · নাম।
 *  - ⏸ বোতামের নাম আছে (aria-label)।
 *  - মেয়াদ পেরোনো ঘোষণা কোথাও নেই — শেষ তারিখ পেরোলে বা মেয়াদের ঘড়ি পেরোলে।
 *  - (fe, ২১ অক্টোবর ২০২৬) ফর্মে "নিচের চলন্ত বারেও দেখান" টিক নেই — কিছুই না করা বোতাম; সাধারণ অগ্রাধিকারের ঘোষণাও
 *    চলন্ত বারে; ক্রম অগ্রাধিকার, তারপর নতুনটা; চলন্ত সারিতে কোনো ✕ নেই (উপরের ফিতার নিজের ✕ থাকে); ⏸/▶ আঁকা চিহ্ন।
 */
final class AlertsStandAtTheTopAnnouncementsRunAtTheBottomTest extends TestCase
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
        $this->empty = storage_path('framework/testing/ticker-backup');
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

    public function test_machine_warnings_never_ride_the_ticker(): void
    {
        $warning = __('core.notice.backup_stale');
        $this->actingAs($this->owner());

        [$first, $second] = Account::query()->postable()->active()->whereNull('money_kind')->orderBy('code')->limit(2)->get()->all();
        app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'চলন্ত-বার পরীক্ষার খসড়া',
                'branch_id' => Company::query()->where('code', 'TDEPOT')->firstOrFail()->defaultBranch()->id],
            [['account_id' => $first->id, 'debit' => '1000', 'credit' => '0'], ['account_id' => $second->id, 'debit' => '0', 'credit' => '1000']],
        );
        $this->announce('কাল অফিস বন্ধ থাকবে');

        $drafts = collect(app(StatusNotices::class)->all())->firstWhere('tone', 'pending')['text'] ?? null;
        $this->assertNotNull($drafts, '⛔ পরীক্ষার ভিত্তি নেই — খসড়ার সতর্কতা আসেনি।');
        Cache::flush();

        $html = $this->dashboard();
        $ticker = $this->part($html, '<div data-ticker', '</footer>');
        $strip = $this->part($html, '<div data-message-strips', '<aside');

        $this->assertStringContainsString('কাল অফিস বন্ধ থাকবে', $ticker, '⛔ পরীক্ষার ভিত্তি নেই — ঘোষণাটাই চলন্ত বারে নেই।');
        $this->assertStringNotContainsString(e($warning), $ticker, '⛔ যন্ত্রের সতর্কতা (ব্যাকআপ) চলন্ত বারে।');
        $this->assertStringNotContainsString(e($drafts), $ticker, '⛔ কাজের নোটিশ (খসড়া) চলন্ত বারে।');
        $this->assertStringContainsString(e($warning), $strip, '⛔ যন্ত্রের সতর্কতা উপরের ফিতা থেকে হারাল।');
        $this->assertStringContainsString(e($drafts), $strip, '⛔ খসড়ার নোটিশ উপরের ফিতা থেকে হারাল।');

        // ⓘ উৎসেও — পাতা যা-ই আঁকুক, চলন্ত বারের তালিকায় কেবল মানুষের লেখা
        Cache::flush();
        $this->assertSame(['own'], array_values(array_unique(array_column(app(StatusNotices::class)->forTicker(), 'kind'))),
            '⛔ চলন্ত বারের তালিকায় যন্ত্রের কথা ঢুকেছে।');
    }

    public function test_an_announcement_runs_at_the_bottom_and_not_in_the_strip_unless_urgent(): void
    {
        $notice = $this->announce('রবিবার থেকে নতুন দাম');

        $html = $this->dashboard();
        $ticker = $this->part($html, '<div data-ticker', '</footer>');

        $this->assertStringContainsString('রবিবার থেকে নতুন দাম', $ticker, '⛔ ঘোষণাটা নিচের চলন্ত বারে নেই।');
        $this->assertStringNotContainsString('রবিবার থেকে নতুন দাম',
            $this->part($html, '<div data-message-strips', '<aside', allowMissing: true),
            '⛔ জরুরি নয় এমন ঘোষণা উপরের ফিতায়।');

        // ⓘ ক্লিক করলে পুরো নোটিশ — নোটিশের নিজের পাতা
        $this->assertStringContainsString('href="'.e(route('system_admin.notice.show', $notice->id)).'"', $ticker,
            '⛔ ঘোষণায় চাপলে পুরো লেখাটা খোলে না।');
    }

    public function test_ticking_urgent_on_the_real_form_moves_it_to_the_strip_and_the_audit_keeps_before_and_after(): void
    {
        $this->actingAs($this->owner())
            ->post(route('system_admin.notice.store'), [
                'title' => 'গুদামে পানি উঠেছে',
                'body' => 'আজ কেউ নিচতলায় যাবেন না',
                'is_active' => '1',
                // ⭐ সাধারণ অগ্রাধিকার — ২১ অক্টোবর ২০২৬ থেকে এটাও চলন্ত বারে ওঠে (আগে "গুরুত্বপূর্ণ" না হলে কোথাও না)
                'priority' => 'normal',
            ])
            ->assertRedirect();

        $notice = Notice::query()->where('title', 'গুদামে পানি উঠেছে')->firstOrFail();
        $this->assertFalse((bool) $notice->is_urgent, '⛔ টিক ছাড়াই জরুরি হয়ে জন্মাল।');
        Cache::flush();

        $html = $this->dashboard();
        $this->assertStringContainsString('গুদামে পানি উঠেছে', $this->part($html, '<div data-ticker', '</footer>'),
            '⛔ সাধারণ অগ্রাধিকারের ঘোষণা, টিক ছাড়া — চলন্ত বারে নেই।');

        // ⭐ "জরুরি" টিক — আসল সম্পাদনার ফর্ম দিয়ে
        $this->actingAs($this->owner())
            ->put(route('system_admin.notice.update', $notice->id), [
                'title' => 'গুদামে পানি উঠেছে',
                'body' => 'আজ কেউ নিচতলায় যাবেন না',
                'is_active' => '1',
                'priority' => 'normal',
                'is_urgent' => '1',
            ])
            ->assertRedirect();
        Cache::flush();

        $html = $this->dashboard();
        $this->assertStringContainsString('গুদামে পানি উঠেছে', $this->part($html, '<div data-message-strips', '<aside'),
            '⛔ জরুরি টিকের পরেও ঘোষণাটা উপরের ফিতায় ওঠেনি।');
        $this->assertStringNotContainsString('গুদামে পানি উঠেছে', $this->part($html, '<footer data-footer', '</footer>'),
            '⛔ একই নোটিশ দুই জায়গায় — জরুরিটা চলন্ত বারেও ঘুরছে।');
        $this->assertSame(['0', '1'], $this->urgentChange($notice, 1), '⛔ জরুরি টিকের বদল নিরীক্ষায় আগে/পরে সহ বসেনি।');

        // ⓘ উৎসেও একবারই — ফোনের বার দুই তালিকা জোড়ে ([[NoticeBoard::onTheBars()]]), আর চলন্ত বারের জায়গা সীমিত
        $board = app(NoticeBoard::class);
        $this->assertTrue($board->forStrip($this->owner())->contains('id', $notice->id), '⛔ জরুরিটা ফিতার তালিকায় নেই।');
        $this->assertFalse($board->forTicker($this->owner())->contains('id', $notice->id),
            '⛔ জরুরিটা চলন্ত বারের তালিকাতেও — ফোনে দুইবার আসে, আর চলন্ত বারের জায়গা খায়।');
        $this->assertSame(1, $board->onTheBars($this->owner())->where('id', $notice->id)->count(), '⛔ ফোনের বারে জরুরিটা একবারের বেশি।');

        // ⓘ টিক তুলে দিলে (ফাঁকা টিকবক্স কিছু পাঠায় না) চলন্ত বারে ফেরে — আর সেটাও নিরীক্ষায়
        $this->actingAs($this->owner())
            ->put(route('system_admin.notice.update', $notice->id), [
                'title' => 'গুদামে পানি উঠেছে',
                'body' => 'আজ কেউ নিচতলায় যাবেন না',
                'is_active' => '1',
                'priority' => 'normal',
            ])
            ->assertRedirect();
        Cache::flush();

        $html = $this->dashboard();
        $this->assertStringContainsString('গুদামে পানি উঠেছে', $this->part($html, '<div data-ticker', '</footer>'),
            '⛔ টিক তোলার পরেও ঘোষণাটা চলন্ত বারে ফেরেনি।');
        $this->assertStringNotContainsString('গুদামে পানি উঠেছে',
            $this->part($html, '<div data-message-strips', '<aside', allowMissing: true),
            '⛔ টিক তোলার পরেও ঘোষণাটা উপরের ফিতায়।');
        $this->assertSame(['1', '0'], $this->urgentChange($notice, 2), '⛔ টিক তোলার বদল নিরীক্ষায় বসেনি।');
    }

    public function test_no_announcements_draw_no_ticker_only_version_copyright_and_name(): void
    {
        $company = (int) $this->owner()->current_company_id;

        foreach ([null, ...Branch::query()->withoutGlobalScopes()->where('company_id', $company)->pluck('id')->all()] as $branch) {
            CompanyContext::set($company, $branch === null ? null : (int) $branch);
            Cache::put(StatusNotices::cacheKey($company, (int) $this->owner()->id), [], 60);
        }

        $html = $this->dashboard();
        $footer = $this->part($html, '<footer data-footer', '</footer>');

        $this->assertStringNotContainsString('data-ticker', $html, '⛔ ঘোষণা নেই, তবু চলন্ত বার আঁকা হয়েছে।');
        $this->assertStringNotContainsString('abos-ticker', $html, '⛔ ঘোষণা নেই, তবু চলার নিয়ম পাতায়।');
        $this->assertStringNotContainsString('data-has-ticker', $footer, '⛔ ঘোষণা নেই, তবু মোবাইলে ফুটার উঠে বসেছে।');
        $this->assertStringContainsString('v'.config('app.version', '0.1.0'), $footer, '⛔ ফুটার থেকে সংস্করণ হারাল।');
        $this->assertStringContainsString('© '.now()->year, $footer, '⛔ ফুটার থেকে © হারাল।');
        $this->assertStringContainsString(e($this->owner()->name), $footer, '⛔ ফুটার থেকে নাম হারাল।');
    }

    public function test_the_pause_button_has_a_name(): void
    {
        $this->announce('সোমবার মিটিং দশটায়');

        $ticker = $this->part($this->dashboard(), '<div data-ticker', '</footer>');

        $this->assertMatchesRegularExpression(
            '/<button type="button" data-ticker-toggle[^>]*aria-label="'.preg_quote(e(__('core.notice.ticker_pause')), '/').'"/u',
            $ticker,
            '⛔ ⏸ বোতাম নেই, বা তার নাম নেই — পর্দা-পাঠক কেবল "বোতাম" শোনে।',
        );
        $this->assertStringContainsString('@click="toggle"', $ticker, '⛔ ⏸ বোতাম কিছুই করে না।');
        $this->assertStringContainsString('group-data-[state=paused]:[animation-play-state:paused]', $ticker,
            '⛔ ⏸ চাপলেও লেখা চলতে থাকে।');

        // ⭐ ⏸/▶ আঁকা চিহ্ন (x-ui.icon), লেখা নয় — fe, ২১ অক্টোবর ২০২৬: লেখার ⏸ বাংলা ফন্টে "॥" দেখাত
        $button = $this->part($ticker, 'data-ticker-toggle', '</button>');
        $this->assertMatchesRegularExpression('/<svg[^>]*viewBox="0 0 24 24"[^>]*data-ticker-icon="pause"[^>]*x-show="running"/s', $button,
            '⛔ ⏸ আঁকা চিহ্ন নয়, বা চলার সময় দেখায় না।');
        $this->assertMatchesRegularExpression('/<svg[^>]*viewBox="0 0 24 24"[^>]*data-ticker-icon="play"[^>]*x-show="paused"/s', $button,
            '⛔ ▶ আঁকা চিহ্ন নয়, বা থামলে দেখায় না।');
        // ⓘ ভরাট Material চিহ্ন, ১৮px — রেখার দুই সরু দাগ ১৪px-এ আবার "॥" পড়াত (fe, ২১ অক্টোবর ২০২৬)
        $this->assertMatchesRegularExpression('/<svg width="18" height="18" viewBox="0 0 24 24"\s+fill="currentColor"[^>]*data-ticker-icon="pause"/s', $button,
            '⛔ ⏸ ভরাট ১৮px চিহ্ন নয়।');
        $this->assertMatchesRegularExpression('/<svg width="18" height="18" viewBox="0 0 24 24"\s+fill="currentColor"[^>]*data-ticker-icon="play"/s', $button,
            '⛔ ▶ ভরাট ১৮px চিহ্ন নয়।');
        $this->assertMatchesRegularExpression('/<button type="button" data-ticker-toggle[^>]*class="[^"]*\bsize-6\b/s', $ticker,
            '⛔ বোতামটা ২৪px-এর ছোট — আঙুলে ধরা যায় না।');
        foreach (["\u{23F8}", "\u{25B6}", '&#x23F8;', '&#x25B6;', '॥'] as $glyph) {
            $this->assertStringNotContainsString($glyph, $button, "⛔ বোতামে লেখার চিহ্ন: {$glyph}");
        }
    }

    public function test_the_form_has_no_dead_ticker_tick_only_the_urgent_one(): void
    {
        $notice = $this->announce('ফর্ম পরীক্ষা');

        foreach ([route('system_admin.notice.create'), route('system_admin.notice.edit', $notice->id)] as $url) {
            $form = $this->actingAs($this->owner())->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('name="in_ticker"', $form, "⛔ কিছুই না করা টিক ফিরে এসেছে: {$url}");
            $this->assertStringNotContainsString('নিচের চলন্ত বারেও দেখান', $form, "⛔ পুরনো টিকের লেখা পাতায়: {$url}");
            $this->assertStringContainsString('name="is_urgent"', $form, "⛔ জরুরি টিকটাই নেই: {$url}");
        }
    }

    public function test_the_ticker_runs_by_priority_then_newest(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('notice.bar_max', 10);
        $settings->flush();

        // ⚠️ লেখার ক্রম ইচ্ছাকৃত এলোমেলো — বেশি জরুরিটা সবার আগে লেখা (সবচেয়ে পুরনো), তাই কেবল "নতুনটা আগে" সাজালে লাল হয়
        $this->announce('বেশি-জরুরি ঘোষণা', priority: 'high');
        $this->announce('কম-জরুরি ঘোষণা', priority: 'low');
        $this->announce('পুরনো সাধারণ ঘোষণা', priority: 'normal');
        $this->announce('নতুন সাধারণ ঘোষণা', priority: 'normal');

        // ⓘ প্রথম কপি শেষ হয় দ্বিতীয় কপির শুরুতে (`min-w-1/2`) — প্রতিটা ঘোষণার বিন্দুও `aria-hidden="true"`, তাই ওটা ধরে কাটলে প্রথম শিরোনামের আগেই কাটত
        $first = $this->part($this->dashboard(), 'data-ticker-copy', 'min-w-1/2');
        $at = fn (string $title): int|false => strpos($first, $title);

        foreach (['বেশি-জরুরি ঘোষণা', 'নতুন সাধারণ ঘোষণা', 'পুরনো সাধারণ ঘোষণা', 'কম-জরুরি ঘোষণা'] as $title) {
            $this->assertNotFalse($at($title), "⛔ ঘোষণাটা চলন্ত বারে নেই: {$title}");
        }

        $this->assertLessThan($at('নতুন সাধারণ ঘোষণা'), $at('বেশি-জরুরি ঘোষণা'), '⛔ বেশি জরুরিটা আগে নেই।');
        $this->assertLessThan($at('পুরনো সাধারণ ঘোষণা'), $at('নতুন সাধারণ ঘোষণা'), '⛔ সমান অগ্রাধিকারে নতুনটা আগে নেই।');
        $this->assertLessThan($at('কম-জরুরি ঘোষণা'), $at('পুরনো সাধারণ ঘোষণা'), '⛔ কম জরুরিটা পিছনে নেই।');
    }

    public function test_no_close_button_runs_in_the_ticker_but_the_strip_keeps_its_own(): void
    {
        $this->announce('চলন্ত ঘোষণা');
        $this->announce('উপরের জরুরি', urgent: true);

        $html = $this->dashboard();
        $ticker = $this->part($html, '<div data-ticker', '</footer>');

        // ⛔ চলন্ত ✕ চাপা যায় না, আর কী করে তাও অস্পষ্ট (fe, ২১ অক্টোবর ২০২৬) — থামাতে ⏸, সরাতে নোটিশের পাতা
        $this->assertStringNotContainsString('<form', $ticker, '⛔ চলন্ত সারিতে একটা ফর্ম (✕) ফিরে এসেছে।');
        $this->assertStringNotContainsString('/dismiss', $ticker, '⛔ চলন্ত সারিতে সরানোর পথ।');
        $this->assertStringNotContainsString('&times;', $ticker, '⛔ চলন্ত সারিতে ✕।');
        $this->assertStringNotContainsString(e(__('core.notice.dismiss')), $ticker, '⛔ চলন্ত সারিতে "সরান" বোতাম।');

        // ⓘ উপরের ফিতার নিজের ✕ অক্ষত
        $this->assertStringContainsString('data-strip-close', $this->part($html, '<div data-message-strips', '<aside'),
            '⛔ উপরের ফিতার ✕ হারাল — ওটা ছোঁয়ার কথা ছিল না।');
    }

    public function test_an_expired_announcement_shows_nowhere(): void
    {
        // ⓘ শেষ তারিখ (ফর্মের "কবে পর্যন্ত") গতকাল
        $this->announce('গত সপ্তাহের ছুটির খবর', ends: now()->subDay());
        $this->announce('গতকালের জরুরি খবর', ends: now()->subDay(), urgent: true);

        // ⓘ মেয়াদের ঘড়ি (`expires_at`) এক ঘণ্টা আগে পেরিয়েছে — অবস্থা এখনো প্রকাশিত, সময়ের কাজ চলেনি
        $this->announce('সকালের ঘোষণা')->forceFill(['expires_at' => now()->subHour()])->save();
        $this->announce('সকালের জরুরি', urgent: true)->forceFill(['expires_at' => now()->subHour()])->save();

        // ⓘ ভিত্তি: একটা চালু ঘোষণা আছে, তাই চলন্ত বারটা আঁকা হয়
        $this->announce('আজকের ঘোষণা');
        Cache::flush();

        $html = $this->dashboard();

        $this->assertStringContainsString('আজকের ঘোষণা', $this->part($html, '<div data-ticker', '</footer>'),
            '⛔ পরীক্ষার ভিত্তি নেই — চালু ঘোষণাটাই বারে নেই।');

        foreach (['গত সপ্তাহের ছুটির খবর', 'গতকালের জরুরি খবর', 'সকালের ঘোষণা', 'সকালের জরুরি'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, "⛔ মেয়াদ পেরোনো ঘোষণা পাতায়: {$gone}");
        }
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────────────

    private function owner(): User
    {
        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    private function dashboard(): string
    {
        return $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();
    }

    /** ⓘ প্রকাশিত একটা ঘোষণা (`Notice::create` — অবস্থা কলামের ডিফল্টে প্রকাশিত); জরুরি টিক না দিলে চলন্ত বারে */
    private function announce(string $title, ?\DateTimeInterface $ends = null, bool $urgent = false, string $priority = 'normal'): Notice
    {
        $notice = Notice::create(['title' => $title, 'body' => $title, 'is_active' => true, 'priority' => $priority,
            'is_urgent' => $urgent, 'ends_on' => $ends, 'created_by' => $this->owner()->id]);
        Cache::flush();

        return $notice;
    }

    /**
     * ⓘ এই নোটিশের n-তম "জরুরি" বদল — নিরীক্ষার [আগে, পরে]
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function urgentChange(Notice $notice, int $nth): array
    {
        $changes = AuditTrail::query()->forRecord(Notice::class, (int) $notice->id)
            ->where('action', AuditTrail::UPDATED)
            ->with('changes')
            ->orderBy('id')
            ->get()
            ->flatMap(fn (AuditTrail $trail) => $trail->changes->where('field', 'is_urgent')->values())
            ->values();

        $this->assertGreaterThanOrEqual($nth, $changes->count(), "⛔ নিরীক্ষায় জরুরি টিকের {$nth} নম্বর বদল নেই।");
        $change = $changes[$nth - 1];

        return [$change->old_value, $change->new_value];
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
