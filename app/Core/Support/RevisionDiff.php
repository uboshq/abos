<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Models\DocumentRevision;

/**
 * সংশোধনের আগে আর পরে — পর্দার জন্য তুলনা ([[x-ui.revisions]], ৩ অক্টোবর ২০২৬)।
 *
 * ⓘ চারটা অংশ — মাথা, সারি, খাতা, মজুদ। মাথায় ঘর ধরে; বাকি তিনটায় ক্রম ধরে সারি মেলানো হয়,
 * আর যেটার জোড়া নেই সেটা "নতুন" বা "বাদ"।
 *
 * ⚠️ সারি id ধরে মেলানো হয় না, ইচ্ছাকৃত: বেশিরভাগ কাগজ সম্পাদনায় সারিগুলো মুছে নতুন করে বসায়
 * (ভাউচার, বিল), তাই id প্রতিবার নতুন — id ধরলে প্রতিটা সারি "বাদ" আর "নতুন" দেখাত, অথচ কিছুই
 * বদলায়নি। ছবিতে id থাকেই না।
 */
final class RevisionDiff
{
    public const SECTIONS = ['header', 'lines', 'ledger', 'stock'];

    public const SAME = 'same';

    public const CHANGED = 'changed';

    public const ADDED = 'added';

    public const REMOVED = 'removed';

    /**
     * @return array<string, mixed> অংশ → মাথায় ঘরের তালিকা, বাকিগুলোয় সারির তালিকা
     */
    public static function of(DocumentRevision $revision): array
    {
        $before = (array) $revision->before;
        $after = (array) $revision->after;

        $out = ['header' => self::fields((array) ($before['header'] ?? []), (array) ($after['header'] ?? []))];

        foreach (['lines', 'ledger', 'stock'] as $section) {
            $out[$section] = self::rows(
                array_values((array) ($before[$section] ?? [])),
                array_values((array) ($after[$section] ?? [])),
            );
        }

        return $out;
    }

    /**
     * ঘর ধরে — বদলানোগুলো আগে, তারপর অপরিবর্তিত।
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{field: string, before: mixed, after: mixed, changed: bool}>
     */
    public static function fields(array $before, array $after): array
    {
        $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));

        $rows = array_map(fn (string $key) => [
            'field' => $key,
            'before' => $before[$key] ?? null,
            'after' => $after[$key] ?? null,
            'changed' => self::differ($before[$key] ?? null, $after[$key] ?? null),
        ], $keys);

        usort($rows, fn ($a, $b) => [$a['changed'] ? 0 : 1, $a['field']] <=> [$b['changed'] ? 0 : 1, $b['field']]);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return list<array{state: string, position: int, fields: list<array{field: string, before: mixed, after: mixed, changed: bool}>}>
     */
    public static function rows(array $before, array $after): array
    {
        $out = [];
        $count = max(count($before), count($after));

        for ($i = 0; $i < $count; $i++) {
            $old = $before[$i] ?? null;
            $new = $after[$i] ?? null;

            $state = match (true) {
                $old === null => self::ADDED,
                $new === null => self::REMOVED,
                default => self::SAME,
            };

            $fields = self::fields((array) ($old ?? []), (array) ($new ?? []));

            if ($state === self::SAME && in_array(true, array_column($fields, 'changed'), true)) {
                $state = self::CHANGED;
            }

            $out[] = ['state' => $state, 'position' => $i + 1, 'fields' => $fields];
        }

        return $out;
    }

    /** কোনো অংশে কিছু বদলেছে কি না — না বদলালে পর্দায় অংশটা গুটানো থাকে। */
    public static function sectionChanged(array $section, string $name): bool
    {
        if ($name === 'header') {
            return in_array(true, array_column($section, 'changed'), true);
        }

        foreach ($section as $row) {
            if ($row['state'] !== self::SAME) {
                return true;
            }
        }

        return false;
    }

    /**
     * পর্দার জন্য একটা মান — খালি হলে "—", ডাটাবেসের চার-দশমিক অঙ্ক টাকার ছাঁচে।
     */
    public static function show(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? '✓' : '✗';
        }

        $text = (string) $value;

        if (preg_match('/^-?\d+\.\d{4}$/', $text) === 1) {
            return Money::format($text);
        }

        return $text;
    }

    /** ঘরের নাম মানুষের ভাষায় — জানা না থাকলে ঘরের নামটাই, `_` ছাড়া। */
    public static function label(string $field): string
    {
        $key = 'revision.field.'.$field;
        $words = __($key);

        return is_string($words) && $words !== $key ? $words : str_replace('_', ' ', $field);
    }

    /**
     * ⓘ অঙ্ক অঙ্ক হিসেবে মেলে (`500` আর `500.0000` একই), বাকি সব লেখা হিসেবে; খালি আর `null` একই।
     */
    private static function differ(mixed $a, mixed $b): bool
    {
        $number = '/^-?\d+(\.\d+)?$/';
        $a = is_bool($a) ? (int) $a : $a;
        $b = is_bool($b) ? (int) $b : $b;

        if (preg_match($number, (string) $a) === 1 && preg_match($number, (string) $b) === 1) {
            return bccomp((string) $a, (string) $b, 4) !== 0;
        }

        return (string) ($a ?? '') !== (string) ($b ?? '');
    }
}
