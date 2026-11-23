<?php

declare(strict_types=1);

namespace App\Modules\Hr\Support;

use App\Core\Services\SettingsService;
use App\Models\BranchSetting;
use App\Modules\Hr\Models\Holiday;
use Illuminate\Support\Carbon;

/**
 * ⭐ কাজের দিন আর ছুটির দিন — সাপ্তাহিক ছুটি আর কোম্পানির ছুটির তালিকা (মালিকের অনুমোদন, ২১ অক্টোবর ২০২৬; বাংলাদেশ শ্রম আইন
 * ২০০৬ ধারা ১০৩ ও ১১৮; a6, ২১ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * ছুটির আবেদনে দিন গোনা হত ক্যালেন্ডারের দিনে: শনি থেকে বৃহস্পতি ছুটি নিলে মাঝের শুক্রবারও কোটা থেকে কাটত, আর বিজয় দিবস পড়লে
 * সেটাও। আইনে সাপ্তাহিক ছুটি আর উৎসবের ছুটি ছুটির আবেদনের দিন নয় (ধারা ১১৫–১১৮)। আর হাজিরার খাতায় ছুটির দিনে কেউ "অনুপস্থিত"
 * লিখলে বেতন থেকে দিনটা কাটত — অথচ সেদিন কাজই ছিল না।
 *
 * ── ⭐ নিয়ম, এক জায়গায় ─────────────────────────────────────────────────
 * একটা দিন ছুটির দিন যদি (ক) সপ্তাহের সেই দিন যা `hr.weekly_off` বলে (ডিফল্ট শুক্রবার), নয়তো (খ) কোম্পানির তালিকায় ([[Holiday]])
 * সেই তারিখ আছে সব শাখার জন্য (`branch_id` খালি) বা কর্মীর নিজের শাখার জন্য। ⓘ শাখাহীন (প্রধান অফিসের) কর্মীর কেবল সব-শাখার ছুটি।
 * ছুটির আবেদনের দিন ([[LeaveService]]), হাজিরার ছুটির ভাগ ([[Attendance::shareOf()]]) আর বেতনের কাটা দিন
 * ([[AttendanceService::unpaidDays()]]) — তিনটাই এখান থেকে; ⛔ দুই জায়গায় দুই নিয়ম হলে কোটা এক কথা আর বেতন আরেক কথা বলত।
 *
 * ── ⚠️ স্মৃতি কেবল এই বস্তুর ─────────────────────────────────────────
 * বছরের ছুটির তালিকা একবার পড়ে মনে রাখে, কিন্তু কেবল এই বস্তুর জীবনে — ⛔ স্থির (static) স্মৃতি টেস্টের এক কেস থেকে পরের কেসে বা
 * এক অনুরোধ থেকে পরেরটায় বাসি তালিকা বয়ে নিত। ডাকার জায়গা একটা কাজের জন্য একটা বস্তু বানায় (`app(WorkCalendar::class)`)।
 * ⓘ কোম্পানি ধরা হয় স্পষ্ট করে (কর্মীর কোম্পানি), প্রসঙ্গের ছাঁকনি সরিয়ে — "গোটা গ্রুপের এক কর্মী-খাতা" চালু থাকলেও কর্মীর নিজের
 * কোম্পানির ছুটিই খাটে।
 */
final class WorkCalendar
{
    /** সাপ্তাহিক ছুটির বাছাই — সেটিংয়ের মান → সপ্তাহের দিন (Carbon: ০ রবি … ৫ শুক্র, ৬ শনি) */
    public const WEEKLY_OFF = [
        'none' => [],
        'friday' => [Carbon::FRIDAY],
        'saturday' => [Carbon::SATURDAY],
        'sunday' => [Carbon::SUNDAY],
        'friday_saturday' => [Carbon::FRIDAY, Carbon::SATURDAY],
        'saturday_sunday' => [Carbon::SATURDAY, Carbon::SUNDAY],
    ];

    public const DEFAULT_WEEKLY_OFF = 'friday';

    /** @var list<int>|null */
    private ?array $weekly = null;

    /** @var array<string, list<int>|null> "কোম্পানি|শাখা" => শাখার নিজের সাপ্তাহিক ছুটি, বা null (কোম্পানির মতো) */
    private array $branchWeekly = [];

    /** @var array<string, array<string, true>> "কোম্পানি|শাখা|বছর" => তারিখ => হ্যাঁ */
    private array $holidays = [];

    public function __construct(private readonly SettingsService $settings) {}

    /** এই দিনটা কি ছুটির দিন — সাপ্তাহিক, বা তালিকার (সব শাখার বা এই শাখার) */
    public function isOffDay(Carbon $day, ?int $companyId, ?int $branchId): bool
    {
        if (in_array($day->dayOfWeek, $this->weeklyOff($companyId, $branchId), true)) {
            return true;
        }

        return isset($this->holidaysOf($companyId, $branchId, $day->year)[$day->toDateString()]);
    }

    /** দুই তারিখের মাঝে (দুই মাথাসহ) কাজের দিন কয়টা */
    public function workingDays(Carbon $from, Carbon $to, ?int $companyId, ?int $branchId): int
    {
        $count = 0;
        $day = $from->copy()->startOfDay();
        $last = $to->copy()->startOfDay();

        while ($day->lte($last)) {
            if (! $this->isOffDay($day, $companyId, $branchId)) {
                $count++;
            }

            $day->addDay();
        }

        return $count;
    }

    /**
     * ⭐ একটা দিনের পরে `$days`টা কাজের দিন গুনে শেষ দিনটা — দিনটা নিজে গোনায় নয় (শ্রম আইন ২০০৬ ধারা ১২৩-এর "মজুরিকাল শেষের সাত
     * কর্মদিবস"; [[PayrollService::wageDeadline()]]; a6, ২১ অক্টোবর ২০২৬)। ⓘ সাপ্তাহিক ছুটি (শাখার নিজের, নইলে কোম্পানির) আর তালিকার
     * ছুটি বাদ — [[isOffDay()]]-এর একই নিয়ম, তাই ছুটির কোটা, বেতনের কাটা দিন আর আইনের শেষ দিন এক পঞ্জিকায়।
     */
    public function addWorkingDays(Carbon $after, int $days, ?int $companyId, ?int $branchId): Carbon
    {
        $day = $after->copy()->startOfDay();

        while ($days > 0) {
            $day->addDay();

            if (! $this->isOffDay($day, $companyId, $branchId)) {
                $days--;
            }
        }

        return $day;
    }

    /**
     * @return list<int> সপ্তাহের যে দিনগুলো ছুটি — শাখার নিজের বাছাই থাকলে সেটা, নইলে কোম্পানির
     *
     * ⭐ শাখার বদল — fe-র উত্তর ৬, ২১ অক্টোবর ২০২৬ (শ্রম আইন ধারা ১০৩ ও ১১৪: দোকান আর কারখানার সাপ্তাহিক ছুটি আলাদা হতে পারে)।
     * ⓘ বদলটা শাখার সেটিংয়ের সারিতে (`branch_settings`, [[BranchSettings]] — `hr.weekly_off` `per_branch`); কোম্পানি ধরা হয় স্পষ্ট করে,
     * প্রসঙ্গ থেকে নয়। অচেনা মান হলে কোম্পানির নিয়ম।
     */
    public function weeklyOff(?int $companyId = null, ?int $branchId = null): array
    {
        if ($companyId !== null && $branchId !== null) {
            $key = $companyId.'|'.$branchId;

            if (! array_key_exists($key, $this->branchWeekly)) {
                $own = BranchSetting::query()
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->where('key', 'hr.weekly_off')
                    ->value('value');

                $this->branchWeekly[$key] = is_string($own) && array_key_exists($own, self::WEEKLY_OFF) ? self::WEEKLY_OFF[$own] : null;
            }

            if ($this->branchWeekly[$key] !== null) {
                return $this->branchWeekly[$key];
            }
        }

        return $this->weekly ??= self::WEEKLY_OFF[$this->weeklyOffKey()];
    }

    /** কোম্পানির সেটিংয়ের মান — অচেনা হলে ডিফল্ট (শুক্রবার), যাতে ভাঙা একটা সারি গোটা সপ্তাহকে কাজের দিন না বানায় */
    public function weeklyOffKey(): string
    {
        $value = (string) $this->settings->get('hr.weekly_off', self::DEFAULT_WEEKLY_OFF);

        return array_key_exists($value, self::WEEKLY_OFF) ? $value : self::DEFAULT_WEEKLY_OFF;
    }

    /** @return array<string, true> */
    private function holidaysOf(?int $companyId, ?int $branchId, int $year): array
    {
        $key = $companyId.'|'.$branchId.'|'.$year;

        if (! array_key_exists($key, $this->holidays)) {
            $this->holidays[$key] = $companyId === null ? [] : Holiday::query()->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereBetween('holiday_date', [$year.'-01-01', $year.'-12-31'])
                ->where(fn ($q) => $branchId === null
                    ? $q->whereNull('branch_id')
                    : $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
                ->pluck('holiday_date')
                ->mapWithKeys(fn ($date) => [Carbon::parse($date)->toDateString() => true])
                ->all();
        }

        return $this->holidays[$key];
    }
}
