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
 * ⭐ নোটিশ নিচের চলন্ত বারে — মালিক, ১১ অক্টোবর ২০২৬: *"Web er notice board kaj korteche na footer e na dekhiye upore
 * dekhay notice"*।
 *
 * ⓘ ১০ অক্টোবর (পাতা সাজানো ধাপ ১) যন্ত্রের লাল সতর্কতা উপরের হলুদ ব্যানারে গিয়েছিল, নিচে কেবল মানুষের লেখা — মালিক সেটা
 * ফিরিয়ে দিলেন।
 *
 * দাবি:
 *  - যন্ত্রের সতর্কতা (ব্যাকআপ বাসি, খসড়া পড়ে আছে) নিচের বারে চলে, ঘণ্টাতেও থাকে; উপরের ব্যানার পাতায় নেই।
 *  - মানুষের লেখা নোটিশ নিচের বারে চলে, আর যন্ত্রের সতর্কতার আগে।
 */
final class EveryNoticeRunsInTheFooterTest extends TestCase
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

    public function test_the_machine_warnings_run_in_the_footer_and_stay_in_the_bell(): void
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

        $this->assertStringContainsString(e($warning), $footer, '⛔ যন্ত্রের সতর্কতা নিচের বারে নেই।');
        $this->assertStringContainsString(e($drafts), $footer, '⛔ খসড়ার সতর্কতা নিচের বারে নেই।');
        $this->assertStringNotContainsString('data-notice-banner', $html, '⛔ উপরের হলুদ ব্যানার আবার পাতায় বসেছে।');
        $this->assertStringContainsString(e($warning), $this->bell($html), '⛔ সতর্কতা ঘণ্টা থেকে হারাল।');
        $this->assertStringContainsString(e($drafts), $this->bell($html), '⛔ খসড়ার সতর্কতা ঘণ্টা থেকে হারাল।');
    }

    public function test_a_notice_people_wrote_still_runs_in_the_footer(): void
    {
        // ⓘ মালিকের নোটিশ, "চলন্ত বারে" টিক দেওয়া — মানুষের লেখা ([[StatusNotices::ownNotices()]])
        Notice::create(['title' => 'রবিবার থেকে দাম বাড়বে', 'body' => 'রবিবার থেকে দাম বাড়বে', 'is_active' => true,
            'in_ticker' => true, 'created_by' => $this->owner()->id]);
        Cache::flush();

        $html = $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();

        $footer = $this->part($html, '<footer data-footer', '</footer>');
        $this->assertStringContainsString('রবিবার থেকে দাম বাড়বে', $footer, '⛔ মালিকের নোটিশ নিচের বার থেকে হারাল।');

        // ⓘ মানুষের লেখা আগে, যন্ত্রের সতর্কতা পরে
        $own = strpos($footer, 'রবিবার থেকে দাম বাড়বে');
        $machine = strpos($footer, e(__('core.notice.backup_stale')));
        $this->assertNotFalse($machine, '⛔ পরীক্ষার ভিত্তি নেই — যন্ত্রের সতর্কতা বারে নেই।');
        $this->assertLessThan($machine, $own, '⛔ যন্ত্রের সতর্কতা মালিকের নোটিশের আগে চলছে।');
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    private function bell(string $html): string
    {
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
