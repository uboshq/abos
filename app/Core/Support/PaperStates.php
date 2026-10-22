<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * ⭐ কাগজের অবস্থার চিপ — ওয়েবের দিক। একটাই তালিকা: `resources/design/paper_states.json` (অ্যাপের
 * `paper_state.dart`-এর একই ফাইল; মালিকের অনুমোদিত নকশা, ২০ অক্টোবর ২০২৬)। প্রতিটা অবস্থা আইকন + রং + বাংলা লেখা একসাথে
 * (WCAG 2.2 SC 1.4.1)। ⛔ এখানে কোনো লেখা বা রং হাতে লেখা নয় — বদলাতে হলে ফাইলটা, তাতে অ্যাপ আর ওয়েব এক থাকে।
 *
 * ⓘ ফাইলের দুই রূপই পড়া যায়: v2-র ছোট লেখা (`label_bn_short`, `semantics_bn_without_level`) থাকলে সেটা, না থাকলে v1-এর
 * (`label_bn`, `label_bn_without_level`, `semantics_bn`)।
 * ⛔ `app_only: true` অবস্থা (যেমন v2-র "unsent" — ফোনে এখনো পাঠানো হয়নি) ওয়েবে কখনো আঁকা হয় না: ওয়েবে এমন কাগজ থাকেই
 * না, তাই [[chip()]] `null` ফেরায় আর চিপ কিছুই আঁকে না।
 * ⓘ ধাপের অঙ্ক ইংরেজিতে ("2-এর 1") — ফাইলের `placeholders.digits = latin`, মালিকের নিয়ম "খ"।
 */
final class PaperStates
{
    /**
     * ⓘ Material Symbols-এর নাম → এই প্রকল্পের নিজের আঁকা আইকন (`x-ui.icon`): প্রকল্পে Material Symbols ফন্ট নেই, আর CSP-তে
     * বাইরের ফন্ট বসানোও হয় না। কাছাকাছি রেখা: edit_note→edit, hourglass_top→clock, draw→edit, warning→alert-triangle;
     * undo আর do_not_disturb_on সেটে নতুন যোগ (২০ অক্টোবর ২০২৬)।
     */
    public const ICONS = [
        'edit_note' => 'edit',
        'hourglass_top' => 'clock',
        'undo' => 'undo',
        'draw' => 'edit',
        'check_circle' => 'check_circle',
        'do_not_disturb_on' => 'do_not_disturb_on',
        'warning' => 'alert-triangle',
        // ⓘ v4 (a6 fbff00bfe, ২০ অক্টোবর ২০২৬): প্রত্যাখ্যাত আর অনুমোদিত — দুইটাই সেটে নতুন যোগ
        'cancel' => 'cancel',
        'verified' => 'verified',
    ];

    /** ⓘ v4-এর কাগজের ধরন — `<name>_<kind>` থাকলে সেই ধরনে সেটাই (যেমন মজুদের কাগজে "পাকা · মজুদে উঠেছে"); ডিফল্ট টাকা */
    public const KINDS = ['money', 'stock', 'claim'];

    /** @var array<string, mixed>|null এক প্রক্রিয়ায় একবার পড়া — ফাইলটা স্থির, স্থাপনার সাথে বদলায় */
    private static ?array $file = null;

    /**
     * একটা অবস্থার চিপ — লেখা, পর্দা-পাঠকের লেখা, আইকন, আলো/অন্ধকারের রং। অচেনা বা কেবল-অ্যাপের অবস্থায় `null`।
     *
     * @return array{state: string, label: string, speech: string, icon: string, role: string, border_style: string,
     *     light: array{foreground: string, background: string, border: string},
     *     dark: array{foreground: string, background: string, border: string}}|null
     */
    public static function chip(string $state, ?int $level = null, ?int $levels = null, string $kind = 'money'): ?array
    {
        $kind = in_array($kind, self::KINDS, true) ? $kind : 'money';
        $file = self::file();
        $row = $file['states'][$state] ?? null;

        if (! is_array($row) || ($row['app_only'] ?? false) === true) {
            return null;
        }

        $role = $file['roles'][$row['role'] ?? 'neutral'] ?? $file['roles']['neutral'] ?? [];
        $withLevel = $level !== null && $levels !== null && $level > 0 && $levels > 0;

        $label = $withLevel
            ? self::first($row, ['label_bn_short', 'label_bn'], $kind)
            : self::first($row, ['label_bn_short_without_level', 'label_bn_without_level', 'label_bn'], $kind);
        $speech = $withLevel
            ? self::first($row, ['semantics_bn'], $kind)
            : self::first($row, ['semantics_bn_without_level', 'semantics_bn'], $kind);

        return [
            'state' => $state,
            'label' => self::fill($label, $withLevel ? $level : null, $levels),
            'speech' => self::fill($speech !== '' ? $speech : $label, $withLevel ? $level : null, $levels),
            'icon' => self::ICONS[$row['icon'] ?? ''] ?? 'clock',
            'role' => (string) ($row['role'] ?? 'neutral'),
            'border_style' => ($row['border_style'] ?? 'solid') === 'dashed' ? 'dashed' : 'solid',
            'light' => self::colours($role['light'] ?? []),
            'dark' => self::colours($role['dark'] ?? []),
        ];
    }

    /** @return array<string, mixed> */
    private static function file(): array
    {
        if (self::$file === null) {
            $raw = @file_get_contents(resource_path('design/paper_states.json'));
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            self::$file = is_array($decoded) ? $decoded : ['roles' => [], 'states' => []];
        }

        return self::$file;
    }

    /**
     * প্রথম যে চাবিটা আছে — আগে সব চাবির ধরনের রূপ (`label_bn_stock`), তারপর সাধারণগুলো (v4-এর `kinds` নিয়ম)।
     * ⓘ দুই ধাপে, এক চাবি ধরে নয়: নইলে `label_bn_short` থাকায় মজুদের `label_bn_stock` কখনো পৌঁছাত না।
     *
     * @param  list<string>  $keys
     */
    private static function first(array $row, array $keys, string $kind = 'money'): string
    {
        foreach ([array_map(fn (string $key): string => $key.'_'.$kind, $keys), $keys] as $pass) {
            foreach ($pass as $candidate) {
                if (isset($row[$candidate]) && is_string($row[$candidate]) && $row[$candidate] !== '') {
                    return $row[$candidate];
                }
            }
        }

        return '';
    }

    /**
     * ধাপ বসানো, ইংরেজি অঙ্কে। ধাপ না জানা থাকলে "{levels}-এর {level}" অংশটা (আগের " · " বা ", " সহ) বাদ — v1 ফাইলের
     * `semantics_bn`-এ ধাপছাড়া রূপ নেই।
     */
    private static function fill(string $text, ?int $level, ?int $levels): string
    {
        if ($level === null) {
            return trim((string) preg_replace('/\s*[·,]?\s*\{levels\}-এর \{level\}/u', '', $text));
        }

        return strtr($text, ['{level}' => (string) $level, '{levels}' => (string) $levels]);
    }

    /** @return array{foreground: string, background: string, border: string} */
    private static function colours(array $set): array
    {
        $hex = fn (string $key, string $fallback): string => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($set[$key] ?? '')) === 1
            ? (string) $set[$key]
            : $fallback;

        return [
            'foreground' => $hex('foreground', '#131E29'),
            'background' => $hex('background', '#EFF1F2'),
            'border' => $hex('border', '#788FA6'),
        ];
    }
}
