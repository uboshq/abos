<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Contracts\ProvisionsCompany;
use App\Modules\Hr\Models\LeaveType;

/**
 * ⭐ প্রমিত ছুটির ধরন — বাংলাদেশ শ্রম আইন ২০০৬-এর প্রচলিত ভাগ (ধারা ১১৫–১১৭, অধ্যায় ৪; a6, ২১ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * তালিকাটা ছিল ছুটির ধরনের পর্দার নিয়ামকের ভিতরে, আর বসত কেবল কেউ খালি পর্দায় বোতাম চাপলে। নতুন কোম্পানিতে কিছুই বসত না, আর
 * তালিকায় নতুন ধরন (মাতৃত্বকালীন) যোগ হলে চলতি কোম্পানিতে সেটা পৌঁছানোর কোনো পথ ছিল না — বোতামটা লুকাত প্রথম ধরন বসার পরেই।
 *
 * ── ⭐ এখন তিন পথ, এক তালিকা ─────────────────────────────────────────
 *  · নতুন কোম্পানি — খোলার দিনে ([[CompanyProvisioner]] → মডিউলের `provisions`)।
 *  · চলতি কোম্পানি — ছুটির ধরনের পর্দার বোতাম (কোনো প্রমিত ধরন না থাকলেই দেখা যায়) বা সব কোম্পানিতে একবারে
 *    `php artisan abos:hr-install-leave-types` ([[InstallLeaveTypes]])।
 * ⓘ বারবার চালানো নিরাপদ: কোড ধরে খোঁজা, মুছে ফেলা সহ — যা আছে (বা ইচ্ছে করে মোছা) তা আবার বসে না, আর কোম্পানির বদলানো দিন
 * বা নাম ছোঁয়া হয় না।
 *
 * ⓘ দিন প্রচলিত প্রমিত; প্রতিষ্ঠান নিজের নীতিতে বদলাবে। অর্জিত ছুটির "বছরে দিন" শূন্য — পাওনা হাজিরা থেকে ([[EarnedLeave]])।
 * মাতৃত্বকালীন: ১৬ সপ্তাহ = ১১২ দিন, বেতনসহ (ধারা ৪৬–৪৭), কেবল নারী কর্মী, ছুটির প্রথম দিনে চাকরি অন্তত ৬ মাস
 * ([[LeaveService::assertEligible()]])।
 */
final class LeaveTypeDefaults implements ProvisionsCompany
{
    /** @var list<array{0: string, 1: string, 2: string, 3: int, 4: bool}> কোড, ইংরেজি, বাংলা, বছরে দিন, বেতনসহ */
    public const ROWS = [
        ['CASUAL', 'Casual Leave', 'নৈমিত্তিক ছুটি', 10, true],
        ['SICK', 'Sick Leave', 'অসুস্থতাজনিত ছুটি', 14, true],
        [LeaveType::EARNED, 'Earned Leave', 'অর্জিত ছুটি', 0, true],
        ['UNPAID', 'Leave Without Pay', 'বিনা বেতনে ছুটি', 0, false],
        [LeaveType::MATERNITY, 'Maternity Leave', 'মাতৃত্বকালীন ছুটি', LeaveType::MATERNITY_DAYS, true],
    ];

    /** @return int কয়টা নতুন বসল */
    public function install(): int
    {
        $added = 0;

        foreach (self::ROWS as [$code, $en, $bn, $days, $paid]) {
            if (LeaveType::query()->where('code', $code)->withTrashed()->exists()) {
                continue;
            }

            LeaveType::create([
                'code' => $code,
                'name_en' => $en,
                'name_bn' => $bn,
                'days_per_year' => $days,
                'is_paid' => $paid,
                'is_active' => true,
                'created_by' => auth()->id(),
            ]);
            $added++;
        }

        return $added;
    }

    /** কোনো প্রমিত ধরন কি এখনো বসেনি — পর্দার বোতাম এটা দেখে */
    public function anyMissing(): bool
    {
        $codes = array_column(self::ROWS, 0);

        return LeaveType::query()->withTrashed()->whereIn('code', $codes)->count() < count($codes);
    }

    public function provisionCompany(): void
    {
        $this->install();
    }
}
