<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Report\Trend;
use Tests\TestCase;

/**
 * "এই মাস বনাম আগের মাস" — গোটা মাস হলে আগের মাসের শেষ দিনটাও (ec, রিপোর্ট লাইন-নিরীক্ষা ⚠️১২, ১৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে: সেপ্টেম্বরের ১–৩০ তুলনা হত আগস্টের ১–৩০-এর সাথে — ৩১ আগস্ট বাদ; ফেব্রুয়ারির সাথে জানুয়ারির ২৯–৩১ বাদ।
 * ⓘ মাসের মাঝখানের পরিসর আগের মাসের একই দিনগুলো, আগের মতো; আর ৩১ মার্চ থেকে পিছিয়ে ফেব্রুয়ারির শেষ পেরোয় না।
 */
final class LastMonthLostItsLastDayTest extends TestCase
{
    public function test_a_whole_month_meets_the_whole_earlier_month(): void
    {
        $this->assertSame(['from' => '2026-08-01', 'to' => '2026-08-31'], Trend::previous(Trend::PREVIOUS_MONTH, '2026-09-01', '2026-09-30'),
            '⛔ সেপ্টেম্বরের সাথে তুলনায় ৩১ আগস্ট বাদ।');
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-01-31'], Trend::previous(Trend::PREVIOUS_MONTH, '2026-02-01', '2026-02-28'),
            '⛔ ফেব্রুয়ারির সাথে তুলনায় জানুয়ারির ২৯–৩১ বাদ।');
        $this->assertSame(['from' => '2026-02-01', 'to' => '2026-02-28'], Trend::previous(Trend::PREVIOUS_MONTH, '2026-03-01', '2026-03-31'));
        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-09'], Trend::previous(Trend::PREVIOUS_MONTH, '2026-10-01', '2026-10-09'),
            'মাসের মাঝখানের পরিসর আর আগের মাসের একই দিন নয়।');
    }
}
