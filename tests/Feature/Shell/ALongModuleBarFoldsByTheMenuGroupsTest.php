<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ মজুদ আর ডকুমেন্টের বার এক লাইনে — মালিক, ৯ নভেম্বর ২০২৬: দুই মডিউলের দুই লাইনের বারের ছবি, "ek line newar ki bebosta?";
 * প্রস্তাব "বাঁয়ের মেনুর ভাগগুলোই উপরে ড্রপডাউন" → "ok … nomuna lagbe na direct kaj koro"।
 *
 * ⓘ ঘর হয় বাঁয়ের মেনুর দল ধরে ([[MenuLook]]-এর `kind`): এক দলে একটাই পাতা হলে সোজা ঘর, বেশি হলে দলের নামে ড্রপডাউন।
 * ⛔ আর কেবল যেখানে আগের ঘর দশের বেশি — যে বার আগে থেকেই এক লাইনে (হিসাব, সিস্টেম, বিক্রয়) সেখানে মালিকের আগের সিদ্ধান্ত অক্ষত।
 */
final class ALongModuleBarFoldsByTheMenuGroupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_the_inventory_bar_is_one_line_of_menu_groups(): void
    {
        $bar = $this->bar(route('module.dashboard', 'inventory'));

        $this->assertLessThanOrEqual(10, $this->cells($bar), '⛔ মজুদের বারে দশের বেশি ঘর — আবার দুই লাইন।');
        $this->assertStringContainsString('data-modulebar-fold="stock"', $bar, '⛔ "মজুদ" দলের ড্রপডাউন নেই।');

        // ⓘ গুদামে মাল বসানো আর সরাসরি ঘর নয় — "মজুদ" দলের ড্রপডাউনের ভিতরে
        $fold = $this->fold($bar, 'stock');
        $this->assertStringContainsString('href="'.e(route('inventory.stock.placement')).'"', $fold,
            '⛔ মাল বসানো "মজুদ" দলের ভিতরে নেই।');
    }

    public function test_the_documents_bar_is_one_line_of_menu_groups(): void
    {
        $bar = $this->bar(route('module.dashboard', 'documents'));

        $this->assertLessThanOrEqual(10, $this->cells($bar), '⛔ ডকুমেন্টের বারে দশের বেশি ঘর — আবার দুই লাইন।');
        $this->assertStringContainsString('href="'.e(route('documents.index')).'"', $this->fold($bar, 'docs'),
            '⛔ ডকুমেন্ট সেন্টার "ডকুমেন্ট" দলের ভিতরে নেই।');
    }

    public function test_a_bar_that_already_fit_keeps_the_owners_older_cells(): void
    {
        // ⓘ হিসাবের ভাউচারের এক বোতাম (মালিক, ১৯ সেপ্টেম্বর) — দলের নিয়ম এখানে খাটে না
        $bar = $this->bar(route('accounts.voucher.index', ['type' => 'receipt']));
        $this->assertStringNotContainsString('data-modulebar-fold="papers"', $bar, '⛔ হিসাবের বারও দলে ভাঙল — আগের সিদ্ধান্ত হারাল।');
    }

    private function bar(string $url): string
    {
        $html = (string) $this->get($url)->assertOk()->getContent();
        $start = strpos($html, 'background:var(--color-modulebar');
        $this->assertNotFalse($start, '⛔ পাতায় মডিউলের বার নেই।');

        return substr($html, $start, strpos($html, '</nav>', $start) - $start);
    }

    /** বারের ঘর — সোজা ঘর আর ড্রপডাউনের বোতাম, দুইটাই `modulebar-cell` পরে; ড্রপডাউনের ভিতরের সারি পরে না */
    private function cells(string $bar): int
    {
        return preg_match_all('/class="[^"]*modulebar-cell/', $bar);
    }

    private function fold(string $bar, string $name): string
    {
        $this->assertSame(1, preg_match('/<div x-show="open"[^>]*data-modulebar-fold="'.preg_quote($name, '/').'".*?<\/div>/s', $bar, $m),
            "⛔ '{$name}' ড্রপডাউন নেই।");

        return $m[0];
    }
}
