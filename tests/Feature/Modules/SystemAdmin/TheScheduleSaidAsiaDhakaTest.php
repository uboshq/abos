<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Models\Company;
use App\Models\ReportSchedule;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * নির্ধারিত রিপোর্ট: সময় কোম্পানির ছকে, অঞ্চল "ঢাকা", খালি তালিকায় প্রথম কাজের বোতাম, আর নিচে স্থির পট্টি।
 *
 * ── ⭐ সিস্টেম পর্দার নকশা §৫, ১০ অক্টোবর ২০২৬ ─────────────────────────────────────────
 * *"সময় 'সকাল ৮:০০' (08:00 AM নয়), সময়-অঞ্চল 'ঢাকা'; খালি অবস্থায় 'প্রথম রিপোর্ট নির্ধারণ করুন' বোতাম"*। ⓘ সময়টা কোম্পানির
 * বেছে নেওয়া ছকে ([[DateFormat::time()]]) — অন্য সব পর্দা যেমন; "সকাল" শব্দের নতুন ছক এই কাজের বাইরে। অঞ্চলের মান আগের মতোই
 * IANA নাম, কেবল লেখাটা বাংলা।
 */
final class TheScheduleSaidAsiaDhakaTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($this->owner->fresh());
    }

    public function test_an_empty_list_offers_the_first_schedule(): void
    {
        ReportSchedule::query()->delete();

        $html = (string) $this->get(route('system_admin.reports.schedule.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('system_admin.reports.schedule.create'), '/').'"[^>]*data-empty-action/',
            $html,
            '⛔ খালি তালিকায় "প্রথম রিপোর্ট নির্ধারণ করুন" বোতাম নেই।',
        );
        $this->assertStringContainsString('প্রথম রিপোর্ট নির্ধারণ করুন', $html);
    }

    /** ⭐ তালিকার সময় কোম্পানির ছকে — কাঁচা "18:30" নয়। */
    public function test_the_list_shows_the_time_in_the_company_format(): void
    {
        ReportSchedule::query()->create([
            'company_id' => $this->company->id,
            'report_key' => 'sales.by_customer',
            'format' => 'csv',
            'frequency' => 'daily',
            'at_time' => '18:30',
            'timezone' => 'Asia/Dhaka',
            'recipients' => [],
            'created_by' => $this->owner->id,
            'is_active' => true,
        ]);

        $html = (string) $this->get(route('system_admin.reports.schedule.index'))->assertOk()->getContent();

        $want = Carbon::createFromFormat('H:i', '18:30')->format(DateFormat::time());

        $this->assertNotSame('18:30', $want, 'ⓘ কোম্পানির ছক ২৪ ঘণ্টার হলে এই দাবিটা কিছু মাপে না।');
        $this->assertStringContainsString('<span class="num">'.$want.'</span>', $html, '⛔ সময়টা কোম্পানির ছকে নেই।');
        $this->assertStringNotContainsString('<span class="num">18:30</span>', $html, '⛔ এখনো কাঁচা 18:30।');
    }

    /** ⭐ অঞ্চল বাছাই, বাংলায় — মান Asia/Dhaka, লেখা "ঢাকা"। */
    public function test_the_zone_is_a_choice_that_reads_dhaka(): void
    {
        $html = (string) $this->get(route('system_admin.reports.schedule.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<select[^>]*name="timezone"/', $html, '⛔ অঞ্চল এখনো হাতে লেখার ঘর।');
        $this->assertMatchesRegularExpression('/<option value="Asia\/Dhaka"[^>]*>\s*ঢাকা \(বাংলাদেশ\)\s*<\/option>/u', $html,
            '⛔ ঢাকার বিকল্পটা বাংলায় নেই।');
    }

    public function test_cancel_and_save_ride_the_sticky_bar(): void
    {
        $html = (string) $this->get(route('system_admin.reports.schedule.create'))->assertOk()->getContent();

        $form = substr($html, (int) strpos($html, 'action="'.route('system_admin.reports.schedule.store').'"'));
        $form = substr($form, 0, (int) strpos($form, '</form>'));

        $this->assertStringContainsString('data-form-actions', $form, '⛔ স্থির পট্টি নেই।');
        $this->assertStringContainsString('href="'.route('system_admin.reports.schedule.index').'"', $form, '⛔ বাতিল নেই।');
        $this->assertSame(1, substr_count($form, 'type="submit"'), '⛔ সংরক্ষণ একটার বেশি।');
    }
}
