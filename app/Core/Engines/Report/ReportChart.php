<?php

declare(strict_types=1);

namespace App\Core\Engines\Report;

use App\Core\Support\DateFormat;
use App\Core\Support\Money;

/**
 * ⭐ রিপোর্টের গ্রাফ — টেবিলের উপরে, রিপোর্টের নিজের সারি থেকে (মালিক, ২৩ নভেম্বর ২০২৬: *"মালিক গ্রাফ পছন্দ করেন"*; অনুমোদিত নমুনা
 *   claude.ai artifact 7jU3Q2Gz2WSGQp6Bt6QhSL ও 37BW47dwWD88uuYN9AryA9 সংস্করণ ২)।
 *
 * ⓘ রিপোর্ট কেবল বলে কোন ঘর ([[ReportDefinition::$chart]]): `type` — `pair` (পাশাপাশি বার, কয়েক সারি), `line` (এক সারির ধারা),
 *   `bars` (শীর্ষ ১০, আড়াআড়ি), `donut` (ভাগ); `label` — নামের ঘর; `series` — অঙ্কের ঘরগুলো। ⛔ সংখ্যা এখানে কষা হয় না — টেবিলের
 *   একই সারি, একই পাতা; গ্রাফ আঁকে হোমের বোর্ডের একই ছাঁচ ([[x-dashboard.board-chart]])।
 * ⓘ কেবল "ABOS" রূপে (বোর্ডের ছাঁচের নকশা ওই রূপেই) আর পর্দায় — Excel, কাগজ আর অন্য রূপে কিছুই বদলায় না (মালিকের নিয়ম, ১৯ নভেম্বর
 *   ২০২৬: অন্য রূপ এক পিক্সেলও নয়)। কোন রূপ, তা পাতা ঠিক করে ([[accounts::report.show]])।
 * ⓘ যে ঘর দেখার চাবি নেই (যেমন খরচ), তা গ্রাফেও নেই — টেবিলের মতোই `columnsFor()`-এর ঘর থেকে বাছা।
 */
final class ReportChart
{
    /** পাশাপাশি বারে এর বেশি দল ধরে না — বেশি হলে প্রথম ক'টা (রিপোর্ট নিজেই বড় থেকে সাজায়) */
    private const GROUPS = 12;

    private const BARS = 10;

    private const SLICES = 6;

    /**
     * বোর্ডের গ্রাফের তথ্য — রিপোর্ট গ্রাফ না চাইলে, বা দেখানোর মতো ঘর না থাকলে `null`।
     *
     * @param  list<ReportColumn>  $columns  টেবিলের ঘর (অনুমতি ছাঁকা)
     * @return array<string, mixed>|null
     */
    public static function for(ReportResult $result, array $columns): ?array
    {
        $plan = $result->report->chart;

        if ($plan === null || $result->rows === []) {
            return null;
        }

        $byKey = [];
        foreach ($columns as $column) {
            $byKey[$column->key] = $column;
        }

        $label = $byKey[$plan['label']] ?? null;
        $series = array_values(array_filter(array_map(fn (string $key) => $byKey[$key] ?? null, $plan['series'])));

        if ($label === null || $series === []) {
            return null;
        }

        $type = (string) $plan['type'];
        $rows = $type === 'bars' ? array_slice($result->rows, 0, self::BARS) : array_slice($result->rows, 0, self::GROUPS);
        $names = array_map(fn (array $row): string => self::name($label, $row[$label->key] ?? null), $rows);
        $title = (string) __($plan['title'] ?? $series[0]->label);
        $base = ['key' => str_replace('.', '-', $result->report->key), 'type' => $type, 'title' => $title, 'sub' => ''];

        return match ($type) {
            'bars' => [...$base, 'colour' => self::colour(0), 'rows' => array_map(fn (array $row, string $name) => [
                'label' => $name,
                'value' => self::value($row[$series[0]->key] ?? 0),
                'text' => ReportResult::number((string) ($row[$series[0]->key] ?? '0'), $series[0]),
            ], $rows, $names)],
            'donut' => self::donut($base, $rows, $names, $series[0], $result),
            'line' => [...$base, 'labels' => $names, 'series' => [['name' => (string) __($series[0]->label), 'colour' => self::colour(0),
                'values' => array_map(fn (array $row) => self::value($row[$series[0]->key] ?? 0), $rows)]],
                'last' => ReportResult::number((string) (end($rows)[$series[0]->key] ?? '0'), $series[0])],
            default => [...$base, 'type' => 'pair', 'labels' => $names, 'series' => array_map(fn (ReportColumn $column, int $i) => [
                'name' => (string) __($column->label),
                'colour' => self::colour($i),
                'values' => array_map(fn (array $row) => self::value($row[$column->key] ?? 0), $rows),
            ], $series, array_keys($series))],
        };
    }

    /**
     * ভাগের গ্রাফ — বড় ক'টা আলাদা, বাকি "অন্যান্য"; মাঝে মোট (রিপোর্টের নিজের মোট, সারির যোগ নয়)।
     *
     * @param  array<string, mixed>  $base
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    private static function donut(array $base, array $rows, array $names, ReportColumn $column, ReportResult $result): array
    {
        $total = (string) ($result->totals[$column->key] ?? '0');
        $sum = max(self::value($total), 0.0) ?: 1.0;
        $parts = [];
        $shown = 0.0;

        foreach (array_slice($rows, 0, self::SLICES) as $i => $row) {
            $value = self::value($row[$column->key] ?? 0);
            $shown += $value;
            $parts[] = ['label' => $names[$i], 'value' => max($value, 0.0), 'colour' => self::colour($i),
                'text' => ReportResult::number((string) ($row[$column->key] ?? '0'), $column), 'share' => round($value / $sum * 100, 1).'%'];
        }

        $rest = self::value($total) - $shown;
        if ($rest > 0.004) {
            $parts[] = ['label' => (string) __('core.report.others'), 'value' => $rest, 'colour' => self::colour(self::SLICES),
                'text' => Money::format((string) $rest), 'share' => round($rest / $sum * 100, 1).'%'];
        }

        return [...$base, 'parts' => $parts, 'centre' => ReportResult::number($total, $column), 'centre_label' => (string) __('core.print.total')];
    }

    /** নামের ঘরের লেখা — তারিখ ছোট করে ("14-11"), লম্বা নাম কেটে */
    private static function name(ReportColumn $label, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if ($label->type === ReportColumn::DATE) {
            return substr(DateFormat::format($value), 0, 5);
        }

        $text = (string) $value;

        return mb_strlen($text) > 22 ? mb_substr($text, 0, 21).'…' : $text;
    }

    private static function value(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /** গ্রাফের রং — রূপের নিজের টোকেন (`--color-chart-1…8`), পাতায় কোনো রং লেখা নেই */
    private static function colour(int $i): string
    {
        return 'var(--color-chart-'.(($i % 8) + 1).')';
    }
}
