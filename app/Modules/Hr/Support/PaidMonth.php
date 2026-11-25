<?php

declare(strict_types=1);

namespace App\Modules\Hr\Support;

use App\Core\Support\DocumentStatus;
use App\Modules\Hr\Models\PayrollRun;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ⛔ বেতন হয়ে যাওয়া মাসের হাজিরা আর ছুটি বদলায় না — একটাই পাহারা, তিন জায়গায় ([[LeaveService::approve()]],
 * [[LeaveService::cancel()]], [[AttendanceService::mark()]]); ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (⚠️২)।
 *
 * ⓘ প্রত্যাহারে এই পাহারা আগেই ছিল (পুনঃঅডিট ৯ অক্টোবর ২০২৬, HR ১০), কিন্তু মঞ্জুরি আর অফিসের দিনের পাতায় ছিল না: সেপ্টেম্বরের
 * বেতন নিশ্চিত হওয়ার পর ২০–২১ সেপ্টেম্বরের ছুটি মঞ্জুর করলে কামাইয়ের সারি দুইটা "ছুটি" হয়ে যেত — হাজিরার পাতা বলত শূন্য দিন
 * কাটা, বেতনশিট বলত দুই দিন। প্রশ্নটা একই, তাই উত্তরটাও এক জায়গায়।
 *
 * ⓘ সব শাখার রান দেখা হয় ([[PayrollRun::acrossBranches()]]) — প্রত্যাহারের আগের নিয়মটাই, বদলানো হয়নি।
 */
final class PaidMonth
{
    /**
     * @param  int  $companyId  কর্মীর নিজের কোম্পানি — মাথায় বাছা কোম্পানি নয়: কর্মী-খাতা এক হলে ([[SharedAcrossCompaniesWhenAsked]]) এক
     *                          কোম্পানির দেওয়া মাস অন্য কোম্পানির কর্মীকে আটকাবে না, আর অন্য কোম্পানি থেকেও দেওয়া মাস বদলাবে না (২৫ নভেম্বর ২০২৬)
     * @param  ?Carbon  $to  ⓘ `null` = শেষ নেই — `$from`-এর মাস আর তার পরের সব মাস (বেতনের কাঠামো, নিচে)
     * @param  string  $field  কোন ঘরে ভুলটা দেখাবে
     * @param  string  $messageKey  `:month` আর `:no` নেয় এমন বার্তা
     * @param  ?int  $employeeId  ⓘ দিলে কেবল যে রানে এই কর্মীর বেতনশিট আছে — নতুন কর্মী আগের রানে ছিলেন না, সেই মাস তাঁর জন্য খোলা
     *
     * ⭐ বেতনের কাঠামো — §৮ ⚠️৫, a6, ২১ অক্টোবর ২০২৬ ([[SalaryStructureService::set()]]): একটা তারিখ থেকে বসানো অঙ্ক সেই মাস
     * আর তার পরের প্রতিটা মাসের বেতনশিটে খাটে, তাই প্রশ্নটা "এই মাস বা তার পরের কোনোটার বেতন কি দেওয়া হয়ে গেছে" — শেষ খোলা।
     *
     * @throws ValidationException সেই মাসগুলোর কোনোটার বেতন নিশ্চিত হয়ে থাকলে
     */
    public static function assertOpen(int $companyId, Carbon $from, ?Carbon $to, string $field, string $messageKey, ?int $employeeId = null): void
    {
        $paid = self::runs($companyId)
            ->where('status', DocumentStatus::CONFIRMED)
            // ⓘ কেবল মাসের বেতন — উৎসব ভাতা হাজিরা, ছুটি বা অধিকাল পড়ে না, তাই সেগুলো তার কারণে আটকায় না (a6, ২১ অক্টোবর ২০২৬)
            ->ofType(PayrollRun::SALARY)
            ->whereDate('month', '>=', $from->copy()->startOfMonth()->toDateString())
            ->when($to !== null, fn ($q) => $q->whereDate('month', '<=', $to->copy()->startOfMonth()->toDateString()))
            ->when($employeeId !== null, fn ($q) => $q->whereHas('payslips', fn ($slips) => $slips->withoutGlobalScope('company')->where('employee_id', $employeeId)))
            ->orderBy('month')
            ->first();

        if ($paid !== null) {
            throw ValidationException::withMessages([
                $field => __($messageKey, [
                    'month' => $paid->month->locale(app()->getLocale())->translatedFormat('F Y'),
                    'no' => $paid->document_no,
                ]),
            ]);
        }
    }

    /** এই কোম্পানির এই মাসের বেতন দেওয়া হয়ে গেছে কি না — একই প্রশ্ন, না থামিয়ে */
    public static function isPaid(int $companyId, Carbon $month): bool
    {
        return self::runs($companyId)
            ->where('status', DocumentStatus::CONFIRMED)
            ->ofType(PayrollRun::SALARY)
            ->whereDate('month', $month->copy()->startOfMonth()->toDateString())
            ->exists();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<PayrollRun> */
    private static function runs(int $companyId): \Illuminate\Database\Eloquent\Builder
    {
        return PayrollRun::acrossBranches()->withoutGlobalScope('company')->where('hr_payroll_runs.company_id', $companyId);
    }
}
