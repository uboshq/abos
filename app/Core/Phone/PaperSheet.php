<?php

declare(strict_types=1);

namespace App\Core\Phone;

/**
 * কাগজের পাতা এক ছাঁচে — সইয়ের পাতার একই আকার ([[ShowsItselfForSigning]]): ঘর আর মান, সারির কলাম, সারি, যোগফল।
 *
 * <p>ⓘ খালি মানের ঘর বাদ — ফোনে "—"-এর সারি চোখ টানত। মান সবই লেখা, ফোন কিছু গোনে না।
 */
final class PaperSheet
{
    /**
     * @param  array<string, string|null>  $facts  নাম → মান
     * @param  array<string, array{0: string, 1?: bool}>  $columns  চাবি → [নাম, সংখ্যা কি না]
     * @param  list<array<string, string|null>>  $rows
     * @param  array<string, string|null>  $totals  কলামের চাবি → যোগফল
     * @return array{facts: list<array{label: string, value: string}>, columns: list<array{key: string, label: string, numeric: bool}>, rows: list<array<string, ?string>>, totals: array<string, string>}
     */
    public static function make(array $facts, array $columns = [], array $rows = [], array $totals = []): array
    {
        $facts = array_filter($facts, fn ($v) => $v !== null && trim((string) $v) !== '');

        return [
            'facts' => array_values(array_map(
                fn ($label, $value) => ['label' => (string) $label, 'value' => (string) $value],
                array_keys($facts),
                $facts,
            )),
            'columns' => array_values(array_map(
                fn (string $key, array $c) => ['key' => $key, 'label' => (string) $c[0], 'numeric' => (bool) ($c[1] ?? false)],
                array_keys($columns),
                $columns,
            )),
            'rows' => array_values($rows),
            'totals' => array_map('strval', array_filter($totals, fn ($v) => $v !== null)),
        ];
    }
}
