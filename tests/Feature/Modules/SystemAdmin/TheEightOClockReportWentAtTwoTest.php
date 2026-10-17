<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Models\ReportSchedule;
use App\Modules\SystemAdmin\Services\ScheduleService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * সকাল ৮টার রিপোর্ট রাত ২টায় যেত — সিস্টেম-অ্যাডমিন লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ [[ScheduleService::nextRunFor()]] সময়টা UTC-তে বদলে ফেরত দিত। Laravel জমা করে Carbon-এর নিজের ঘড়ির লেখা, রূপান্তর ছাড়া —
 * তাই "০৮:০০ ঢাকা" জমা হত "০২:০০", আর [[ReportSchedule::scopeDue()]] সেটা তুলনা করত ঢাকার `now()`-এর সাথে: ৬ ঘণ্টা আগে।
 * ⓘ দাবিটা মাপে ঠিক যা ডাটাবেজে বসে (`fromDateTime`) — ঘড়ির লেখাই আসল প্রশ্ন।
 */
final class TheEightOClockReportWentAtTwoTest extends TestCase
{
    public function test_an_eight_oclock_daily_report_is_stored_as_eight_oclock_app_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 06:00:00', config('app.timezone')));

        try {
            $schedule = new ReportSchedule(['frequency' => 'daily', 'at_time' => '08:00', 'timezone' => config('app.timezone')]);

            $next = app(ScheduleService::class)->nextRunFor($schedule);

            $this->assertSame('2026-10-14 08:00:00', $schedule->fromDateTime($next),
                'ডাটাবেজে সকাল ৮টা বসেনি — অ্যাপের ঘড়ির সাথে তুলনায় রিপোর্ট অন্য সময়ে যেত।');
        } finally {
            Carbon::setTestNow();
        }
    }
}
