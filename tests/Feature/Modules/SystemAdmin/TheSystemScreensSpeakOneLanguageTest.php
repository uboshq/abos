<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\RoleLabel;
use App\Models\Company;
use App\Models\Notice;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⭐ সিস্টেমের পর্দা এক ভাষায় — কন্ট্রোল প্যানেলের পরিকল্পনা, মালিকের অনুমোদিত, ২১ অক্টোবর ২০২৬, দফা ১১: তারিখ "21 অক্টোবর 2026" (মাস
 * বাংলায়, অঙ্ক ইংরেজিতে); রোলের ছোট নাম (ASM, RSM, DSM) বন্ধনীতে মানেসহ; ভিতরের ইংরেজি নামের পাশে পর্দার নাম।
 * ⛔ আগে: "20 Oct 2026", "28/10/2026" আর "Accountant" একই পাতায়।
 */
final class TheSystemScreensSpeakOneLanguageTest extends TestCase
{
    use RefreshDatabase;

    private const ENGLISH_MONTH = '/\b(Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\b \d|\d{1,2}\/\d{1,2}\/\d{4}/';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        app()->setLocale('bn');
    }

    public function test_a_date_reads_as_day_bengali_month_and_year_in_english_digits(): void
    {
        $this->assertSame('21 অক্টোবর 2026', DateFormat::spokenDate('2026-10-21'), '⛔ তারিখ "21 অক্টোবর 2026" ধাঁচে নয়।');
        $this->assertSame('', DateFormat::spokenDate(null), '⛔ খালি তারিখে কিছু একটা ছাপা হলো।');
    }

    public function test_the_role_page_and_the_notice_list_show_spoken_dates_only(): void
    {
        $role = Role::query()->where('company_id', CompanyContext::id())->where('name', 'Accountant')->first()
            ?? Role::query()->forceCreate(['name' => 'Accountant', 'guard_name' => 'web', 'company_id' => CompanyContext::id()]);
        $role->forceFill(['created_at' => '2026-10-21 10:00:00', 'updated_at' => '2026-10-21 10:00:00'])->saveQuietly();

        $html = (string) $this->actingAs($this->owner)->get(route('system_admin.role.edit', $role))->assertOk()->getContent();
        $this->assertStringContainsString('21 অক্টোবর 2026', $html, '⛔ রোলের পাতায় তারিখ কথায় নয়।');
        $this->assertDoesNotMatchRegularExpression(self::ENGLISH_MONTH, $this->main($html), '⛔ রোলের পাতায় ইংরেজি মাস বা অঙ্কের তারিখ।');
        $this->assertMatchesRegularExpression('/data-role-shown-as[^>]*>\s*'.preg_quote(e(__('system_admin::permission.shown_as', ['name' => 'হিসাবরক্ষক'])), '/').'/u', $html, '⛔ ভিতরের নাম "Accountant"-এর পাশে পর্দার নামটা নেই।');

        Notice::create(['title' => 'তারিখের নোটিশ', 'body' => 'x', 'is_active' => true, 'created_by' => $this->owner->id,
            'starts_on' => '2026-10-21', 'ends_on' => null]);
        $list = (string) $this->get(route('system_admin.notice.index'))->assertOk()->getContent();
        $this->assertStringContainsString('21 অক্টোবর 2026', $list, '⛔ নোটিশের তালিকায় তারিখ কথায় নয়।');
        $this->assertDoesNotMatchRegularExpression(self::ENGLISH_MONTH, $this->main($list), '⛔ নোটিশের তালিকায় ইংরেজি মাস বা অঙ্কের তারিখ।');
    }

    public function test_the_user_list_says_the_last_log_in_in_words(): void
    {
        $this->owner->forceFill(['last_login_at' => '2026-10-21 14:35:00'])->saveQuietly();

        $html = (string) $this->actingAs($this->owner)->get(route('system_admin.user.index'))->assertOk()->getContent();
        $this->assertStringContainsString('21 অক্টোবর 2026, 14:35', $html, '⛔ ব্যবহারকারীর তালিকায় শেষ লগইন কথায় নয়।');
        $this->assertDoesNotMatchRegularExpression(self::ENGLISH_MONTH, $this->main($html), '⛔ ব্যবহারকারীর তালিকায় ইংরেজি মাস বা অঙ্কের তারিখ।');
    }

    public function test_sales_role_codes_keep_their_name_and_say_what_they_mean(): void
    {
        foreach (['SR' => 'বিক্রয় প্রতিনিধি', 'ASM' => 'এরিয়া সেলস ম্যানেজার', 'RSM' => 'রিজিওনাল সেলস ম্যানেজার', 'DSM' => 'ডিভিশনাল সেলস ম্যানেজার'] as $code => $meaning) {
            $this->assertSame("{$code} ({$meaning})", RoleLabel::for($code), "⛔ {$code}-এর মানে বন্ধনীতে নেই।");
        }
    }

    /** ⓘ কেবল পাতার কাজের অংশ — পায়ের সংস্করণের তারিখ আর মাথার আজকের দিন অন্যের দায় */
    private function main(string $html): string
    {
        return preg_match('/<main\b.*<\/main>/s', $html, $m) ? $m[0] : $html;
    }
}
