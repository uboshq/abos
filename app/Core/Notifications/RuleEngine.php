<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\Support\CompanyContext;
use App\Core\Support\NotificationKinds;
use App\Models\NotificationRule;
use App\Models\NotificationTemplateVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ⭐ নিয়মের ইঞ্জিন — একটা ঘটনায় কোন নিয়মগুলো খাটে, আর মিলিয়ে কী দাঁড়ায় (মালিকের স্পেক §৮; ধাপ ৩)।
 *
 * ── কীভাবে ───────────────────────────────────────────────────────────
 * ঘটনার ধরন, শাখা, গুরুত্ব আর মডিউলের পাঠানো মান ([[NotificationVariables::clean()]]) মিলিয়ে দেখা হয়: নিয়ম চালু, আজ তার
 * কার্যকর সময়ের ভিতরে, শাখা মেলে (বা নিয়মে শাখা নেই), আর **সব** শর্ত মেলে। মিললে:
 *   · গুরুত্ব — মেলা নিয়মগুলোর সবচেয়ে উঁচুটা (কেবল নিয়মে বসানো থাকলে)
 *   · মাধ্যম — মেলা নিয়মগুলোর মাধ্যমের যোগফল (কোনো নিয়মে না থাকলে স্বাভাবিক পথ)
 *   · টেমপ্লেট — প্রথম মেলা নিয়মের প্রকাশিত টেমপ্লেট
 *   · দেরি — সবচেয়ে বড়টা; মেয়াদ — সবচেয়ে ছোটটা; একই খবর বারবার নয় — সবচেয়ে লম্বা সময়টা
 *   · প্রাপক — প্রতিটা নিয়মের প্রাপক, [[RecipientResolver]] দিয়ে
 *
 * ⛔ কোনো AI নেই, অনুমান নেই — কেবল তুলনা। টাকার তুলনা bcmath-এ, float কখনো নয়। নিয়ম কোনো কাগজ বদলায় না।
 */
final class RuleEngine
{
    /** @var array<string, Collection<int, NotificationRule>> */
    private array $cache = [];

    /** গুরুত্বের ক্রম — বড় মানে উঁচু */
    private const RANK = ['low' => 1, 'normal' => 2, 'high' => 3, 'critical' => 4];

    /**
     * @param  array<string, string>  $data  পরিষ্কার করা মান
     * @return Collection<int, NotificationRule>
     */
    public function matching(string $type, ?int $branchId, string $priority, array $data, ?Carbon $today = null): Collection
    {
        $today ??= now();

        return $this->rulesFor($type)->filter(fn (NotificationRule $rule) => $this->matches($rule, $branchId, $priority, $data, $today))->values();
    }

    /**
     * ⭐ মেলা নিয়মগুলো মিলিয়ে একটা পরিকল্পনা।
     *
     * @param  array<string, string>  $data
     * @return array{rules: Collection<int, NotificationRule>, priority: ?string, channels: ?list<string>, template: ?NotificationTemplateVersion, delay: int, expires: ?int, cooldown: int}
     */
    public function plan(string $type, ?int $branchId, string $priority, array $data): array
    {
        $rules = $this->matching($type, $branchId, $priority, $data);

        $plan = ['rules' => $rules, 'priority' => null, 'channels' => null, 'template' => null, 'delay' => 0, 'expires' => null, 'cooldown' => 0];

        foreach ($rules as $rule) {
            if (NotificationKinds::isPriority($rule->priority)
                && ($plan['priority'] === null || self::RANK[$rule->priority] > self::RANK[$plan['priority']])) {
                $plan['priority'] = $rule->priority;
            }

            if (filled($rule->channels)) {
                $plan['channels'] = array_values(array_unique(array_merge($plan['channels'] ?? [], (array) $rule->channels)));
            }

            if ($plan['template'] === null && $rule->template?->is_active && $rule->template->published !== null) {
                $plan['template'] = $rule->template->published;
            }

            $plan['delay'] = max($plan['delay'], (int) $rule->delay_minutes);
            $plan['cooldown'] = max($plan['cooldown'], (int) $rule->cooldown_minutes);

            if ($rule->expires_minutes !== null && (int) $rule->expires_minutes > 0) {
                $plan['expires'] = $plan['expires'] === null ? (int) $rule->expires_minutes : min($plan['expires'], (int) $rule->expires_minutes);
            }
        }

        return $plan;
    }

    /** @param  array<string, string>  $data */
    public function matches(NotificationRule $rule, ?int $branchId, string $priority, array $data, Carbon $today): bool
    {
        if (! $rule->is_active) {
            return false;
        }

        if ($rule->effective_from !== null && $today->toDateString() < $rule->effective_from->toDateString()) {
            return false;
        }

        if ($rule->effective_to !== null && $today->toDateString() > $rule->effective_to->toDateString()) {
            return false;
        }

        if ($rule->branch_id !== null && (int) $rule->branch_id !== $branchId) {
            return false;
        }

        $facts = $data + ['priority' => $priority, 'branch_id' => $branchId === null ? '' : (string) $branchId];

        foreach ((array) ($rule->conditions ?? []) as $condition) {
            if (! self::holds($facts, (array) $condition)) {
                return false;
            }
        }

        return true;
    }

    /**
     * একটা শর্ত — ঘরটা না থাকলে শর্ত মেলে না (অজানাকে "হ্যাঁ" ধরা হয় না)।
     *
     * @param  array<string, string>  $facts
     * @param  array<string, mixed>  $condition
     */
    public static function holds(array $facts, array $condition): bool
    {
        $field = (string) ($condition['field'] ?? '');
        $op = (string) ($condition['op'] ?? '');
        $value = trim((string) ($condition['value'] ?? ''));

        if (! in_array($field, NotificationVariables::CONDITION_FIELDS, true) || ! in_array($op, NotificationRule::OPERATORS, true)
            || ! array_key_exists($field, $facts) || $facts[$field] === '') {
            return false;
        }

        $fact = (string) $facts[$field];

        if ($op === 'in') {
            return in_array($fact, array_map('trim', explode(',', $value)), true);
        }

        if (in_array($field, NotificationVariables::NUMERIC_FIELDS, true)) {
            $left = self::number($fact);
            $right = self::number($value);

            if ($left === null || $right === null) {
                return false;
            }

            $cmp = bccomp($left, $right, 4);

            return match ($op) {
                'eq' => $cmp === 0, 'ne' => $cmp !== 0, 'gt' => $cmp > 0, 'gte' => $cmp >= 0, 'lt' => $cmp < 0, 'lte' => $cmp <= 0,
            };
        }

        return match ($op) {
            'eq' => $fact === $value, 'ne' => $fact !== $value,
            default => false,
        };
    }

    /** "১,২৫,০০০.৫০" বা "125000.50" থেকে bcmath-এর সংখ্যা; না পারলে `null` */
    private static function number(string $value): ?string
    {
        $value = strtr($value, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9', ',' => '', ' ' => '', '৳' => '']);

        return preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 ? $value : null;
    }

    /** @return Collection<int, NotificationRule> */
    private function rulesFor(string $type): Collection
    {
        $key = CompanyContext::id().'|'.$type;

        return $this->cache[$key] ??= NotificationRule::query()
            ->with('template.published')
            ->where('event', $type)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    public function forget(): void
    {
        $this->cache = [];
    }
}
