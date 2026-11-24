<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Services\ListExport;

/**
 * ⭐ নিজের পর্দা আঁকা রিপোর্টের ছাপার কাগজ — কেন্দ্রীয় ছাঁচে (মালিক, ২৩ নভেম্বর ২০২৬: *"প্রতিটা রিপোর্টের আলাদা ছাপার ছাঁচ —
 * A4, কোম্পানির প্যাড, সময় আর ছাঁকনি, যোগফল, সইয়ের রেখা, ইংরেজি অঙ্ক — আর প্রিন্ট ভিউ বোতাম, নতুন ট্যাবে"*)।
 *
 * ⓘ ইঞ্জিনের রিপোর্ট নিজের টেবিল [[ReportExport::capture()]]-এ দেয়; মালিকানা বিবরণী, গ্রুপ রিপোর্ট, নিট সম্পদের মতো পর্দা নিজের
 *   টেবিল আঁকে, তাই তারা এখানে টেবিলটা দেয় — `?export=print|pdf` হলে [[ExportListing]] ধরা টেবিল থেকে `print.listing` কাগজ বানায়
 *   ([[ListPaper]]), কাগজের মাথা, সময়, শাখা, ছাপলেন, সইয়ের রেখা সব সেখানেই। ⛔ সংখ্যা এখানে কষা হয় না — পর্দার একই সংখ্যা।
 * ⓘ সারির ধরন: `row` সাধারণ, `head` ভাগের মাথা, `total` উপমোট, `grand` সর্বমোট — কাগজে মোটা ও ছায়ায়।
 */
final class ReportPaper
{
    /**
     * @param  list<array{0: string, 1: bool}>  $columns  [লেবেল, সংখ্যা কি না]
     * @param  list<array{cells: list<string>, kind?: string}>  $rows
     * @param  list<string>  $facts  ছাঁকনির এক লাইন — "শাখা: …", "গ্রুপ: …"
     * @param  list<string>|null  $footer  সর্বমোটের সারি (প্রথম ঘরে লেবেল)
     */
    public static function capture(string $title, string $period, array $facts, array $columns, array $rows, ?array $footer = null): void
    {
        $export = app(ListExport::class);

        if (! $export->isPaper() || $columns === []) {
            return;
        }

        $keys = array_map(fn (int $i): string => 'c'.$i, array_keys($columns));

        $export->describe([
            'title' => $title,
            'title_en' => '',
            'period' => $period,
            'facts' => array_values(array_filter($facts, fn ($f) => is_string($f) && $f !== '')),
        ]);

        $export->numeric(array_values(array_filter($keys, fn (string $key, int $i): bool => (bool) $columns[$i][1], ARRAY_FILTER_USE_BOTH)));

        $export->capture(
            array_map(fn (string $key, int $i): array => ['key' => $key, 'label' => (string) $columns[$i][0], 'render' => null], $keys, array_keys($keys)),
            array_map(fn (array $row): array => ['__cells' => array_values($row['cells']), ...(($row['kind'] ?? 'row') === 'row' ? [] : ['__section' => $row['kind']])], $rows),
            fn (array $row, array $column): string => (string) ($row['__cells'][(int) substr($column['key'], 1)] ?? ''),
        );

        if ($footer !== null) {
            $export->footer($footer);
        }
    }

    /**
     * কেবল কাগজের মাথা — টেবিলটা পাতার `x-ui.table` নিজেই ধরে ([[ListExport::capture()]])।
     *
     * @param  list<string>  $facts
     */
    public static function describe(string $title, string $period, array $facts = []): void
    {
        $export = app(ListExport::class);

        if (! $export->isPaper()) {
            return;
        }

        $export->describe([
            'title' => $title,
            'period' => $period,
            'facts' => array_values(array_filter($facts, fn ($f) => is_string($f) && $f !== '')),
        ]);
    }

    /** "প্রিন্ট ভিউ"-এর ঠিকানা — এই পাতা, একই ছাঁকনি, `export=print` */
    public static function href(array $extra = []): string
    {
        return url()->current().'?'.http_build_query(array_merge(collect(request()->query())->except(['export', 'page'])->all(), $extra, ['export' => 'print']));
    }
}
