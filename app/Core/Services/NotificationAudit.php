<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\CompanyContext;
use App\Models\NotificationAuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ বিজ্ঞপ্তির নিরীক্ষার খাতায় এক সারি — কে, কী, কখন, কোনটায়, ফল কী (স্পেক §১৩; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১)।
 *
 * ⓘ সাধারণ নিরীক্ষা ([[AuditEngine]]) মডেলের ঘর বদলের খাতা; এখানে খবরের ওপর মানুষের কাজ — সব পড়া, আর্কাইভ, অনুমতি
 * ছাড়া খোলার চেষ্টা, কেন্দ্রের কাজ, পরে মাধ্যম আর নিয়মের বদল। একটা একটা খবর পড়া লেখা হয় না — সেটা খবরের নিজের
 * সারিতে (`read_at`), আর প্রতিটা ক্লিক লিখলে খাতাটা পড়ার অযোগ্য হত।
 *
 * ⛔ `detail`-এ কেবল সংখ্যা, আইডি আর ছোট সংকেত — বার্তার লেখা, ঠিকানা, পাসওয়ার্ড বা টোকেন কখনো নয় ([[forbidden()]])।
 */
final class NotificationAudit
{
    /** ⛔ এই নামের কোনো চাবি খাতায় ঢোকে না — ভুল করে কেউ পাঠালেও */
    private const FORBIDDEN = ['password', 'secret', 'token', 'key', 'credential', 'body', 'email', 'phone'];

    /** @param  array<string, scalar|null|list<int>>  $detail */
    public function record(string $action, ?Model $target = null, string $outcome = 'done', array $detail = []): ?NotificationAuditLog
    {
        $company = CompanyContext::id();

        if ($company === null) {
            return null;
        }

        return NotificationAuditLog::query()->create([
            'company_id' => $company,
            'actor_id' => \App\Core\Support\Actor::userId(),
            'action' => $action,
            'target_type' => $target === null ? null : class_basename($target),
            'target_id' => $target?->getKey(),
            'outcome' => $outcome,
            'detail' => $this->clean($detail) ?: null,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function clean(array $detail): array
    {
        return array_filter($detail, fn ($value, $key) => ! $this->forbidden((string) $key), ARRAY_FILTER_USE_BOTH);
    }

    private function forbidden(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::FORBIDDEN as $word) {
            if (str_contains($key, $word)) {
                return true;
            }
        }

        return false;
    }
}
