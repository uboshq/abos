<?php

declare(strict_types=1);

namespace App\Core\Engines\Report;

/**
 * একবার চালানো রিপোর্টের ফল।
 *
 * সারি, যোগফল ও পাতার তথ্য এক জায়গায় — স্ক্রিন, PDF ও Excel তিনটাই এই
 * একই বস্তু থেকে তৈরি হয়, তাই তিন জায়গায় তিন রকম সংখ্যা দেখানোর সুযোগ নেই।
 */
final class ReportResult
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $totals
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public readonly ReportDefinition $report,
        public readonly array $rows,
        public readonly array $totals,
        public readonly int $totalRows,
        public readonly int $page,
        public readonly int $perPage,
        public readonly array $filters,
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->totalRows / $this->perPage));
    }

    public function hasMorePages(): bool
    {
        return $this->page < $this->lastPage();
    }

    /** পাতায় দেখানোর জন্য — "১০০টির মধ্যে ১–৫০" */
    public function showing(): array
    {
        if ($this->isEmpty()) {
            return ['from' => 0, 'to' => 0, 'of' => 0];
        }

        $from = ($this->page - 1) * $this->perPage + 1;

        return [
            'from' => $from,
            'to' => $from + count($this->rows) - 1,
            'of' => $this->totalRows,
        ];
    }

    /**
     * একটা ঘরের মান — টাকা ও পরিমাণ ঠিক দশমিকে।
     *
     * ফরম্যাটিং এখানে, ভিউতে নয়: একই সংখ্যা স্ক্রিনে দুই দশমিকে আর PDF-এ
     * চার দশমিকে দেখালে ব্যবহারকারী ধরে নেয় দুটো আলাদা হিসাব।
     */
    public function format(array $row, ReportColumn $column): string
    {
        $value = $row[$column->key] ?? null;

        if ($value === null || $value === '') {
            return '';
        }

        if ($column->isNumeric()) {
            // টাকার অঙ্ক সবসময় ইংরেজি সংখ্যায় (সেকশন ১৮.৪) — number_format
            // ইংরেজি অঙ্কই দেয়, আর সেটাই চাই।
            return number_format((float) $value, $column->decimals(), '.', ',');
        }

        return (string) $value;
    }

    public function formatTotal(ReportColumn $column): string
    {
        $value = $this->totals[$column->key] ?? null;

        return $value === null ? '' : number_format((float) $value, $column->decimals(), '.', ',');
    }
}
