<?php

declare(strict_types=1);

namespace App\Core\Engines\Report;

use App\Core\Services\ListExport;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;

/**
 * রিপোর্টের পর্দাটা যা দেখাচ্ছে, সেটাই CSV হয়ে নামে।
 *
 * ── কেন এটা আলাদা করে লাগল ──────────────────────────────────────────
 * তালিকার পর্দাগুলো `x-ui.table` ব্যবহার করে, আর সেটা নিজেই রপ্তানিতে
 * জমা দেয়। রিপোর্টের পর্দাটা নিজের টেবিল আঁকে (কলামের ধরন, ড্রিল,
 * যোগফলের সারি — সবই আলাদা), তাই ওই জমাটা কোনোদিন হয়নি।
 *
 * ফল: রিপোর্টের টুলবারে "Export CSV" লেখা ছিল, লিংকটা ছিল
 * `?export=csv`, আর ক্লিক করলে **একই HTML পাতা** ফিরে আসত। ফাইলটা
 * নামত না, কোনো ভুলও দেখাত না।
 *
 * সবচেয়ে খারাপ দিকটা ছিল পরীক্ষায়: "ক্রয়মূল্য রপ্তানিতে ঢাকা" প্রমাণ
 * করতে গিয়ে যে পরীক্ষাটা লেখা হয়েছিল সেটা আসলে HTML পাতাটাই পড়ত।
 * পাতায় কলামটা এমনিতেই ঢাকা, তাই পরীক্ষাটা পাশ করত — **রপ্তানি নামের
 * জিনিসটা না থাকলেও।**
 *
 * ── কেন কলামগুলো আবার বাছা হয় না ────────────────────────────────────
 * `columnsFor()` একবারই ছাঁকে, আর পর্দা ও রপ্তানি দুইজনেই সেই একই
 * তালিকা পায়। এখানে দ্বিতীয়বার অনুমতি দেখলে একদিন দুইটা আলাদা হত, আর
 * তখন পর্দায় ঢাকা একটা সংখ্যা ফাইলে বেরিয়ে যেত।
 */
final class ReportExport
{
    /**
     * পর্দার টেবিলটা রপ্তানিতে জমা দেওয়া।
     *
     * @param  list<ReportColumn>  $columns  `columnsFor()`-এর ফল, নতুন করে ছাঁকা নয়
     */
    public static function capture(ReportResult $result, array $columns): void
    {
        $export = app(ListExport::class);

        if (! $export->wanted() || $columns === []) {
            return;
        }

        $export->capture(
            array_map(
                fn (ReportColumn $column): array => [
                    'key' => $column->key,
                    'label' => __($column->label),
                    'render' => null,
                ],
                $columns,
            ),
            $result->rows,
            fn (mixed $row, array $column): string => self::text(
                $columns[self::indexOf($columns, $column['key'])],
                is_array($row) ? ($row[$column['key']] ?? null) : null,
            ),
        );
    }

    /** @param  list<ReportColumn>  $columns */
    private static function indexOf(array $columns, string $key): int
    {
        foreach ($columns as $i => $column) {
            if ($column->key === $key) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * একটা ঘরের লেখা — পর্দায় যা দেখা যায়, হুবহু।
     *
     * ── কেন শূন্য এখানে শূন্যই থাকে ─────────────────────────────────
     * পর্দায় শূন্য দেখানো হয় না, কারণ একটা কলামে অর্ধেক সারিতে "0.00"
     * থাকলে চোখ প্রতিটাতে থামে। কিন্তু স্প্রেডশিটে খালি ঘর আর শূন্য এক
     * জিনিস নয় — খালি ঘর যোগ হয় না, আর কেউ বুঝতেও পারে না কেন যোগফল
     * মিলছে না। ফাইলটা মানুষ পড়ে না, সূত্র পড়ে।
     */
    private static function text(ReportColumn $column, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return match ($column->type) {
            ReportColumn::MONEY => Money::format((string) $value, $column->decimals()),
            ReportColumn::QUANTITY => Money::format((string) $value, $column->decimals()),
            ReportColumn::DATE => DateFormat::format($value),
            default => (string) $value,
        };
    }
}
