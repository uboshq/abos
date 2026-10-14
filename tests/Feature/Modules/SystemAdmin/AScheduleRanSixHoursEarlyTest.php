<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Models\Company;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Modules\SystemAdmin\Services\ScheduleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * নির্ধারিত রিপোর্ট ঘড়ির সময়েই চলে — ছয় ঘণ্টা আগে নয় (ec, রিপোর্ট লাইন-নিরীক্ষা ⛔১, ১৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে: পরের সময় UTC-তে ফেরত দেওয়া হত, আর Eloquent সেটা দেয়ালের ঘড়ি হিসেবেই লিখত — ঢাকার সকাল ৮টা জমা হত "02:00",
 * আবার পড়া হত ঢাকার রাত ২টা। "সন্ধ্যা ৮টার আজকের বিক্রি" চলত দুপুর ২টায়, সন্ধ্যার বিক্রি ছাড়া; তালিকায় দেখাত "02:00"।
 *
 * দাবি:
 *  - সকাল ৮টার সূচির পরের সময় ঢাকার ৮টা;
 *  - রাত ২:৩০-এ সূচিটা "সময় হয়েছে" নয়, সকাল ৮টায় হয়;
 *  - তালিকায় পরের সময় ৮টা।
 */
final class AScheduleRanSixHoursEarlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_eight_oclock_schedule_waits_for_eight_oclock(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($owner);

        $this->assertSame('Asia/Dhaka', config('app.timezone'), 'ⓘ অ্যাপ ঢাকায় না চললে দাবিটা কিছু মাপে না।');

        $this->travelTo(Carbon::parse('2026-10-14 01:00', 'Asia/Dhaka'));

        $schedule = app(ScheduleService::class)->create([
            'report_key' => 'sales.by_customer', 'frequency' => 'daily', 'format' => 'csv',
            'at_time' => '08:00', 'timezone' => 'Asia/Dhaka',
        ]);

        $this->assertSame('2026-10-14 08:00', $schedule->fresh()->next_run_at->format('Y-m-d H:i'),
            '⛔ সকাল ৮টার সূচির পরের সময় ৮টা নয়।');

        $this->travelTo(Carbon::parse('2026-10-14 02:30', 'Asia/Dhaka'));
        $this->assertSame(0, ReportSchedule::query()->due()->count(), '⛔ সকাল ৮টার সূচি রাত ২:৩০-এ চলার সময় হয়ে গেল।');

        $this->travelTo(Carbon::parse('2026-10-14 08:00', 'Asia/Dhaka'));
        $this->assertSame(1, ReportSchedule::query()->due()->count(), 'সকাল ৮টাতেও সময় হয়নি — দাবি অন্ধ।');

        $html = (string) $this->get(route('system_admin.reports.schedule.index'))->assertOk()->getContent();
        $want = Carbon::parse('2026-10-14 08:00', 'Asia/Dhaka')->format(DateFormat::date().' '.DateFormat::time());
        $this->assertStringContainsString($want, $html, '⛔ তালিকায় পরের সময় সকাল ৮টা নয়।');
    }
}
