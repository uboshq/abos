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
 * ⭐ নিচের বারে সবসময় লাল নয় — পাতা সাজানোর পরিকল্পনা ধাপ ১, ১০ অক্টোবর ২০২৬ (মালিক: *"ebar porda gulo plan
 * onuzayi kaj suro koro"*)।
 *
 * ⛔ আগে প্রতিটা পাতার নিচে যন্ত্রের দুটো লাল বার্তা ঘুরত (ব্যাকআপ, খসড়া) — মানুষ লাল দেখাই বন্ধ করে দেয়।
 *
 * দাবি:
 *  - যন্ত্রের লাল সতর্কতা (ব্যাকআপ বাসি) নিচের বারে নেই; উপরের হলুদ ব্যানারে আছে, ঘণ্টাতেও আছে — হারায়নি।
 *  - মানুষের লেখা (মালিকের নোটিশ, চলন্ত বারে টিক দেওয়া) নিচের বারে থাকে — মালিকের "footer e notice cholbe"।
 *  - "সরান" চাপলে এই লগইনে ব্যানার আর আসে না, ঘণ্টায় থাকে; বাজে চাবি কিছু বন্ধ করে না।
 *  - ব্যাকআপ দেখার অধিকার নেই যাঁর, তাঁর পাতায় ব্যানার নেই।
 */
final class TheRedWarningsLeftTheFooterTest extends TestCase
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
        $this->empty = storage_path('framework/testing/banner-backup');
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

    public function test_the_machine_warning_moved_to_the_banner_and_stayed_in_the_bell(): void
    {
        $warning = __('core.notice.backup_stale');
        $this->actingAs($this->owner());

        // ⓘ যন্ত্রের লাল-নয় সতর্কতাও একটা — খসড়া ভাউচার পড়ে আছে (অপেক্ষার রঙ): ঘণ্টায় থাকে, ব্যানারে ওঠে না
        [$first, $second] = Account::query()->postable()->active()->whereNull('money_kind')->orderBy('code')->limit(2)->get()->all();
        app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'ব্যানার-পরীক্ষার খসড়া',
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
        $footer = $this->part($html, '<footer data-footer', '</footer>');
        $banner = $this->part($html, 'data-notice-banner', '</div>');

        $this->assertStringNotContainsString(e($warning), $footer, '⛔ যন্ত্রের লাল সতর্কতা এখনও নিচের বারে।');
        $this->assertStringNotContainsString(e($drafts), $footer, '⛔ খসড়ার সতর্কতা এখনও নিচের বারে।');
        $this->assertStringContainsString(e($warning), $banner, '⛔ উপরের ব্যানারে সতর্কতা নেই।');
        $this->assertStringNotContainsString(e($drafts), implode('', $this->banners($html)), '⛔ খসড়ার মতো রোজকার সতর্কতাও ব্যানারে উঠল — ব্যানার কেবল লালের।');
        $this->assertStringContainsString(e($warning), $this->bell($html), '⛔ সতর্কতা ঘণ্টা থেকেও হারাল।');
        $this->assertStringContainsString(e($drafts), $this->bell($html), '⛔ খসড়ার সতর্কতা ঘণ্টা থেকেও হারাল।');
    }

    /** @return list<string> পাতার প্রতিটা ব্যানার */
    private function banners(string $html): array
    {
        preg_match_all('/data-notice-banner.*?<\/form>/s', $html, $m);

        return $m[0];
    }

    public function test_a_notice_people_wrote_still_runs_in_the_footer(): void
    {
        // ⓘ মালিকের নোটিশ, "চলন্ত বারে" টিক দেওয়া — মানুষের লেখা ([[StatusNotices::ownNotices()]])
        Notice::create(['title' => 'রবিবার থেকে দাম বাড়বে', 'body' => 'রবিবার থেকে দাম বাড়বে', 'is_active' => true,
            'in_ticker' => true, 'created_by' => $this->owner()->id]);
        Cache::flush();

        $html = $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('রবিবার থেকে দাম বাড়বে', $this->part($html, '<footer data-footer', '</footer>'), '⛔ মালিকের নোটিশ নিচের বার থেকে হারাল।');
        $this->assertStringNotContainsString('রবিবার থেকে দাম বাড়বে', $this->part($html, 'data-notice-banner', '</div>', allowMissing: true),
            '⛔ মানুষের নোটিশ যন্ত্রের ব্যানারে উঠল।');
    }

    public function test_closing_the_banner_keeps_it_closed_for_this_login_and_keeps_the_bell(): void
    {
        $warning = __('core.notice.backup_stale');
        $owner = $this->owner();

        // ⓘ বাজে চাবি কিছু বন্ধ করে না
        $this->actingAs($owner)->from(route('dashboard'))->post(route('notifications.banner.close'), ['key' => 'not-a-key'])->assertRedirect();
        $this->assertStringContainsString('data-notice-banner', $this->actingAs($owner)->get(route('dashboard'))->getContent(), '⛔ বাজে চাবিতে ব্যানার বন্ধ হল।');

        $this->actingAs($owner)->from(route('dashboard'))->post(route('notifications.banner.close'), ['key' => sha1($warning)])->assertRedirect(route('dashboard'));
        $html = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-notice-banner', $html, '⛔ "সরান" চাপার পরও ব্যানার ফিরল।');
        $this->assertStringContainsString(e($warning), $this->bell($html), '⛔ ব্যানার বন্ধে সতর্কতাও ঘণ্টা থেকে গেল।');
    }

    public function test_someone_who_does_not_see_backups_gets_no_banner(): void
    {
        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->assertFalse($sales->can('backup.view'), '⛔ পরীক্ষার ভিত্তি নেই — বিক্রয়কর্মী ব্যাকআপ দেখেন।');

        $response = $this->actingAs($sales)->get(route('dashboard'));
        $this->assertStringNotContainsString('data-notice-banner', (string) $response->getContent());

        // ⓘ বন্ধ করার দরজাও তাঁর নয় — রুটের can:backup.view ([[EveryRouteIsGuardedTest]])
        $this->actingAs($sales)->post(route('notifications.banner.close'), ['key' => sha1('x')])->assertForbidden();
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    private function bell(string $html): string
    {
        // ⓘ ঘণ্টা টপবারে, ব্যানার <main>-এর ভেতরে — তাই <main> পর্যন্ত কাটলে ব্যানারের লেখা এখানে ঢোকে না
        return $this->part($html, 'data-notification-bell', '<main id="main"');
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
