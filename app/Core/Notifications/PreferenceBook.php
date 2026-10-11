<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\Services\SettingsService;
use App\Models\Company;
use App\Models\NotificationPreference;

/**
 * ⭐ একজনের পছন্দ, আর না থাকলে কোম্পানির স্বাভাবিক নীরব সময় (মালিকের স্পেক §৪, §১৪; ধাপ ৩)।
 *
 * ⓘ ব্যক্তির সারি না থাকলে: সব মাধ্যম চালু, কিছু চুপ নয়, সাথে সাথে — আর নীরব সময় কোম্পানির নিয়ন্ত্রণ-প্যানেল থেকে
 * (`notification.quiet_start` / `notification.quiet_end`, ডিফল্টে খালি = নীরব সময় নেই)। সময় অঞ্চল: ব্যক্তির, নয়তো
 * কোম্পানির।
 */
final class PreferenceBook
{
    /** @var array<string, NotificationPreference> */
    private array $cache = [];

    /** @var array<int, string> */
    private array $zones = [];

    public function for(int $userId, int $companyId): NotificationPreference
    {
        $key = $companyId.'|'.$userId;

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $pref = NotificationPreference::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)->where('user_id', $userId)->first();

        if ($pref === null) {
            // ⓘ সারি নেই — কোম্পানির স্বাভাবিক নীরব সময় (থাকলে), বাকি সব স্বাভাবিক
            $start = app(SettingsService::class)->get('notification.quiet_start');
            $end = app(SettingsService::class)->get('notification.quiet_end');

            $pref = new NotificationPreference([
                'company_id' => $companyId,
                'user_id' => $userId,
                'frequency' => 'instant',
                'quiet_enabled' => filled($start) && filled($end),
                'quiet_start' => filled($start) ? (string) $start : null,
                'quiet_end' => filled($end) ? (string) $end : null,
            ]);
        }

        return $this->cache[$key] = $pref;
    }

    /** কোম্পানির সময় অঞ্চল — না থাকলে অ্যাপের */
    public function zone(int $companyId): string
    {
        return $this->zones[$companyId] ??= (string) (Company::query()->whereKey($companyId)->value('timezone') ?: config('app.timezone', 'Asia/Dhaka'));
    }

    public function forget(): void
    {
        $this->cache = [];
    }
}
