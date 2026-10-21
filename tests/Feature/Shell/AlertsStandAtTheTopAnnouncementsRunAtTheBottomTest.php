<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

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
                // ⓘ অগ্রাধিকার "গুরুত্বপূর্ণ" — নইলে নোটিশ কোনো বারেই ওঠে না ([[NoticeLifecycle::draft()]])
                'priority' => 'important',
            ])
            ->assertRedirect();

        $notice = Notice::query()->where('title', 'গুদামে পানি উঠেছে')->firstOrFail();
        $this->assertFalse((bool) $notice->is_urgent, '⛔ টিক ছাড়াই জরুরি হয়ে জন্মাল।');
        Cache::flush();

        $html = $this->dashboard();
        $this->assertStringContainsString('গুদামে পানি উঠেছে', $this->part($html, '<div data-ticker', '</footer>'),
            '⛔ পরীক্ষার ভিত্তি নেই — টিক ছাড়া ঘোষণাটা চলন্ত বারে নেই।');

        // ⭐ "জরুরি" টিক — আসল সম্পাদনার ফর্ম দিয়ে
        $this->actingAs($this->owner())
            ->put(route('system_admin.notice.update', $notice->id), [
                'title' => 'গুদামে পানি উঠেছে',
                'body' => 'আজ কেউ নিচতলায় যাবেন না',
                'is_active' => '1',
                'priority' => 'important',
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

        // ⓘ টিক তুলে দিলে (ফাঁকা টিকবক্স কিছু পাঠায় না) চলন্ত বারে ফেরে — আর সেটাও নিরীক্ষায়
        $this->actingAs($this->owner())
            ->put(route('system_admin.notice.update', $notice->id), [
                'title' => 'গুদামে পানি উঠেছে',
                'body' => 'আজ কেউ নিচতলায় যাবেন না',
                'is_active' => '1',
                'priority' => 'important',
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

    /** ⓘ প্রকাশিত একটা ঘোষণা, বারে টিক দেওয়া (`Notice::create` — অবস্থা কলামের ডিফল্টে প্রকাশিত) */
    private function announce(string $title, ?\DateTimeInterface $ends = null, bool $urgent = false): Notice
    {
        $notice = Notice::create(['title' => $title, 'body' => $title, 'is_active' => true, 'in_ticker' => true,
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
