<?php

declare(strict_types=1);

namespace App\Core\Engines\Report;

use App\Core\Support\Money;

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

        /**
         * কোন সময়ের সাথে তুলনা চলছে — চাওয়া না হলে null।
         *
         * @var array{key: string, from: string, to: string}|null
         */
        public readonly ?array $comparison = null,

        /**
         * ছাঁকা ছাড়া মোট কয়টা সারি ছিল।
         *
         * ── কেন এটা আলাদা করে রাখা ──────────────────────────────────
         * "শুধু উপরের দশটা" চাইলে `totalRows` দশ হয়ে যায়, আর তখন
         * পর্দা বলতে পারত না যে আসলে ২৪০টা সারি আছে। "২৪০-এর মধ্যে
         * প্রথম ১০" আর "১০টা সারি" দুইটা আলাদা কথা, আর দ্বিতীয়টা
         * পড়ে মানুষ ভাবত ওইটুকুই সব।
         */
        public readonly ?int $fullRowCount = null,
    ) {}

    /** উপরের কয়টা সারি দেখানো হচ্ছে — পুরো তালিকা হলে false */
    public function isTopOnly(): bool
    {
        return $this->fullRowCount !== null && $this->fullRowCount > $this->totalRows;
    }

    /**
     * এই ব্যবহারকারী যে কলামগুলো দেখতে পাবেন — কলাম নেওয়ার একমাত্র পথ।
     *
     * ── কেন এখানে, প্রতিটা পর্দায় নয় ────────────────────────────────
     * একটা রিপোর্ট তিনভাবে বেরোয়: পর্দায়, রপ্তানিতে, ছাপায়। তিন
     * জায়গায় আলাদা করে `@can` লিখলে একদিন একটায় লেখা হত আর বাকি
     * দুইটায় নয় — আর তখন আড়ালটা **আড়াল না থাকার চেয়ে খারাপ** হত,
     * কারণ পর্দা দেখে সবাই ধরে নিত সংখ্যাটা ঢাকা আছে।
     *
     * তিনটাই এই একটা তালিকা থেকে তৈরি হয় (`ListExport` পর্দার কলামই
     * ধরে নেয়), তাই এক জায়গায় বসালে তিনটাতেই বসে।
     *
     * @return list<ReportColumn>
     */
    public function columnsFor(mixed $user): array
    {
        $columns = array_values(array_filter(
            $this->report->columns,
            fn (ReportColumn $column) => $column->visibleTo($user),
        ));

        return [...$columns, ...$this->addedColumns($user)];
    }

    /**
     * চাওয়া হলে যে কলামগুলো জুড়ে বসে — অবদান % ও তুলনা।
     *
     * ── কেন সংজ্ঞায় লেখা থাকে না ────────────────────────────────────
     * এগুলো রিপোর্টের নিজের কলাম নয়, একটা **প্রশ্নের** কলাম: "কে আসল"
     * বা "আগের চেয়ে কেমন"। ২২টা রিপোর্টের সংজ্ঞায় তিনটা করে বাড়তি
     * কলাম লিখলে ৬৬ জায়গায় একই জিনিস থাকত, আর একদিন একটায় লেখা হত
     * বাকিগুলোয় নয়।
     *
     * ── কেন এগুলোও অনুমতি মানে ──────────────────────────────────────
     * অবদানের শতাংশ যে কলাম ধরে গোনা, সেটাই যদি ঢাকা থাকে তবে
     * শতাংশটাও ঢাকা থাকতে হবে — মুনাফার ৪০% জানা থাকলে আর বিক্রয়
     * জানা থাকলে মুনাফার অঙ্কটা এক ভাগেই বেরিয়ে আসে।
     *
     * @return list<ReportColumn>
     */
    private function addedColumns(mixed $user): array
    {
        $rank = $this->report->rankBy;

        if ($rank === null) {
            return [];
        }

        $ranked = null;

        foreach ($this->report->columns as $column) {
            if ($column->key === $rank) {
                $ranked = $column;
                break;
            }
        }

        if ($ranked === null || ! $ranked->visibleTo($user)) {
            return [];
        }

        $added = [];

        if (array_key_exists('contribution_percent', $this->rows[0] ?? [])) {
            $added[] = ReportColumn::fromArray([
                'key' => 'contribution_percent',
                'label' => 'core.report.contribution',
                'type' => ReportColumn::PERCENT,
                'total' => false,
                'permission' => $ranked->permission,
            ], count($this->report->columns));
        }

        if ($this->comparison !== null) {
            $added[] = ReportColumn::fromArray([
                'key' => 'previous_value',
                'label' => 'core.report.compare_'.$this->comparison['key'],
                'type' => ReportColumn::MONEY,
                'total' => false,
                'permission' => $ranked->permission,
            ], count($this->report->columns) + 1);

            $added[] = ReportColumn::fromArray([
                'key' => 'change_percent',
                'label' => 'core.report.change',
                'type' => ReportColumn::PERCENT,
                'total' => false,
                'permission' => $ranked->permission,
            ], count($this->report->columns) + 2);
        }

        return $added;
    }

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
            /*
             * টাকার অঙ্ক সবসময় ইংরেজি সংখ্যায় (সেকশন ১৮.৪), আর গোল করা
             * bcmath-এ — `number_format` হলে float ছুঁতে হত।
             *
             * যোগফলগুলো bcadd দিয়ে গোনা, অথচ এখানে float-এ ফরম্যাট করলে
             * ঠিক-মাঝামাঝি অঙ্কে (x.xx5) মোট আর সারি দুই দিকে গোল হত —
             * এক পর্দায় দুইটা সংখ্যা, যোগ করলে মেলে না।
             */
            return Money::format($value, $column->decimals());
        }

        return (string) $value;
    }

    public function formatTotal(ReportColumn $column): string
    {
        $value = $this->totals[$column->key] ?? null;

        return $value === null ? '' : Money::format($value, $column->decimals());
    }
}
