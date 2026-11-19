<?php

declare(strict_types=1);

namespace App\Core\Dashboard;

use Illuminate\Support\Carbon;

/**
 * ⭐ পাতার মাথার নিচে Copilot-এর এক লাইন — মালিক, ১৮ নভেম্বর ২০২৬: *"Copilot থাকতে বলছি"* (নীল প্যানেলের নমুনা)।
 * ⓘ পরের সিদ্ধান্তে (একই দিন) হোম পাতায় নয় — সফটওয়্যার খোলার পপআপে বসবে (fe-র শাখা); এখানে কেবল কথাটা বানানো।
 *
 * ── কোথা থেকে কথা ─────────────────────────────────────────────────────
 * নতুন কিছু গোনা হয় না: হোম পাতা যে ঘরগুলো আগেই বানিয়েছে ([[Widget]]), সেখান থেকেই পড়া —
 *   · "আজ আদায়" সূচকের তুলনা (গত সপ্তাহের একই বার, [[SalesWidgets::againstLastWeek()]]) — কেবল "আজ" বাছা থাকলে
 *   · "খসড়া ভাউচার" করণীয়ের সংখ্যা (পোস্ট হয়নি এমন ভাউচার, [[AccountsWidgets]])
 * ⓘ ঘরগুলো ব্যবহারকারীর চাবি দেখে আগেই ছাঁকা ([[WidgetRegistry::forUser()]]) — তাই যে সংখ্যা তিনি পাতায় দেখেন না,
 * Copilot-ও সেটা বলে না।
 *
 * ── দুটো প্রশ্ন ──────────────────────────────────────────────────────
 * চাপলে উপরের বারের Copilot (পুরো পর্দার জিজ্ঞাসা) খোলে, প্রশ্নটা ঘরে লেখা — পাঠান মানুষ নিজে।
 * ⛔ যেখানে বসবে সেখানে চাবি `assistant.use` না থাকলে গোটা লাইনটাই নেই — জিজ্ঞাসার দরজাই যার বন্ধ, তাকে প্রশ্ন দেখানো মিথ্যা।
 */
final class CopilotLine
{
    /**
     * @param  list<string>  $sentences
     * @param  list<string>  $questions
     */
    private function __construct(
        public readonly array $sentences,
        public readonly array $questions,
    ) {}

    /**
     * @param  array<string, list<Widget>>  $groups  হোমের ঘরগুলো, দল ধরে
     */
    public static function from(array $groups, string $period): self
    {
        $sentences = [];

        if ($period === 'today') {
            $collected = self::find($groups['kpi'] ?? [], (string) __('sales::dashboard.collected_today'));
            if ($collected !== null && $collected->delta !== null) {
                $sentences[] = self::collectedSentence($collected->delta);
            }
        }

        $drafts = self::find($groups['todo'] ?? [], (string) __('accounts::dashboard.draft_vouchers'));
        $count = $drafts === null ? 0 : (int) preg_replace('/\D/', '', strtr($drafts->value, self::DIGITS));
        if ($count > 0) {
            $sentences[] = trans_choice('home.copilot_drafts', $count, ['count' => $count]);
        }

        if ($sentences === []) {
            $sentences[] = (string) __('home.copilot_quiet');
        }

        return new self($sentences, [(string) __('home.copilot_q_owed'), (string) __('home.copilot_q_spent')]);
    }

    public function text(): string
    {
        return implode(' ', $this->sentences);
    }

    /** "+6.0%" → "আজ আদায় গত বুধবারের চেয়ে 6% বেশি।" — শতাংশ দশমিকের শূন্য ছাড়া, সংখ্যা ইংরেজিতে (মালিকের নিয়ম) */
    private static function collectedSentence(string $delta): string
    {
        // ⓘ লেখাটা bcmath-এ পড়া — float নয় ([[MoneyIsNeverAFloatTest]]); অচেনা লেখা হলে "সমান"
        $raw = trim(str_replace(['%', '+', ','], '', $delta));
        $value = is_numeric($raw) ? bcadd($raw, '0', 1) : '0.0';
        $sign = bccomp($value, '0', 1);
        $day = Carbon::today()->subWeek()->locale(app()->getLocale())->dayName;
        $pct = rtrim(rtrim(ltrim($value, '-'), '0'), '.');

        return (string) __(match (true) {
            $sign > 0 => 'home.copilot_collected_up',
            $sign < 0 => 'home.copilot_collected_down',
            default => 'home.copilot_collected_same',
        }, ['day' => strtr($day, self::DIGITS), 'pct' => $pct]);
    }

    /** @param  list<Widget>  $widgets */
    private static function find(array $widgets, string $label): ?Widget
    {
        foreach ($widgets as $widget) {
            if ($widget->label === $label) {
                return $widget;
            }
        }

        return null;
    }

    private const DIGITS = ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'];
}
