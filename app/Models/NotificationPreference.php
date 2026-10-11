<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ⭐ একজনের নিজের পছন্দ — কোন মাধ্যমে, কোন শ্রেণি চুপ, কত ঘন ঘন, নীরব সময় (মালিকের স্পেক §৪, §১৪; ধাপ ৩)।
 *
 * ⓘ সারি না থাকা মানে স্বাভাবিক: সব মাধ্যম চালু, কিছু চুপ নয়, সাথে সাথে, নীরব সময় নেই।
 * ⛔ জরুরি খবর কখনো চুপ হয় না, দেরিও হয় না — নীরব সময়েও না (স্পেক §১৪ "Critical Exception")।
 */
class NotificationPreference extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    /** @var list<string> */
    public const FREQUENCIES = ['instant', 'daily', 'weekly'];

    /** @var list<string> পর্দার সময় অঞ্চল — বাংলাদেশ আগে (সূচির পর্দাও এটাই নেয়) */
    public const ZONES = ['Asia/Dhaka', 'Asia/Kolkata', 'Asia/Dubai', 'Asia/Singapore', 'Europe/London', 'UTC'];

    protected $fillable = [
        'company_id', 'user_id', 'channels', 'muted_categories', 'frequency', 'digest_hour', 'digest_day',
        'quiet_enabled', 'quiet_start', 'quiet_end', 'timezone',
        'delegate_user_id', 'delegate_from', 'delegate_until',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'muted_categories' => 'array',
            'quiet_enabled' => 'boolean',
            'digest_hour' => 'integer',
            'digest_day' => 'integer',
            'delegate_from' => 'date',
            'delegate_until' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** ⭐ আজ যিনি এই মানুষটার খবর পাবেন (ছুটির দায়িত্ব) — না থাকলে `null` */
    public function delegateToday(): ?int
    {
        if ($this->delegate_user_id === null) {
            return null;
        }

        $today = now()->toDateString();

        if ($this->delegate_from !== null && $today < $this->delegate_from->toDateString()) {
            return null;
        }

        if ($this->delegate_until !== null && $today > $this->delegate_until->toDateString()) {
            return null;
        }

        return (int) $this->delegate_user_id;
    }

    public function allowsChannel(string $channel): bool
    {
        return (($this->channels ?? [])[$channel] ?? true) !== false;
    }

    public function mutes(string $category): bool
    {
        return in_array($category, (array) ($this->muted_categories ?? []), true);
    }

    /**
     * এই মুহূর্তটা নীরব সময়ে পড়ে কি না — আর পড়লে নীরবতা কখন শেষ (অ্যাপের সময় অঞ্চলে — ডেটাবেসের ঘর সেভাবেই পড়া হয়)। রাত পেরোনো সময়ও চলে (২২:০০–০৭:০০)।
     */
    public function quietUntil(Carbon|CarbonImmutable $now, string $fallbackZone): ?CarbonImmutable
    {
        if (! $this->quiet_enabled || blank($this->quiet_start) || blank($this->quiet_end) || $this->quiet_start === $this->quiet_end) {
            return null;
        }

        $zone = $this->timezone ?: $fallbackZone;
        $local = CarbonImmutable::instance($now)->setTimezone($zone);
        [$sh, $sm] = array_map('intval', explode(':', (string) $this->quiet_start));
        [$eh, $em] = array_map('intval', explode(':', (string) $this->quiet_end));

        $start = $local->setTime($sh, $sm);
        $end = $local->setTime($eh, $em);

        if ($start < $end) {
            // ⓘ একই দিনের ভিতরে (১৩:০০–১৪:০০)
            return $local >= $start && $local < $end ? $end->setTimezone(config('app.timezone')) : null;
        }

        // ⓘ রাত পেরোনো (২২:০০–০৭:০০): শুরুর পরে আজ রাতে, নয়তো শেষের আগে আজ ভোরে
        if ($local >= $start) {
            return $end->addDay()->setTimezone(config('app.timezone'));
        }

        return $local < $end ? $end->setTimezone(config('app.timezone')) : null;
    }
}
