<?php

declare(strict_types=1);

namespace App\Core\Services;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Pagination\AbstractPaginator;

/**
 * পর্দায় যে তালিকাটা দেখা যাচ্ছে, সেটাই CSV হয়ে বেরোয়।
 *
 * ── কেন এভাবে, প্রতিটা কন্ট্রোলারে আলাদা export() নয় ────────────────
 * টুলবারে "Export CSV" লেখা ছিল, লিংকটা ছিল ?export=csv, আর পুরো
 * কোডবেসে কেউ ওই কোয়েরিটা পড়ত না — ক্লিক করলে একই HTML পাতা ফিরে
 * আসত। প্রতিটা তালিকা-পর্দায়। এটা সবচেয়ে খারাপ ধরনের স্টাব: কাজটা
 * আছে বলে দেখায়।
 *
 * প্রতিটা কন্ট্রোলারে একটা করে export() লিখলে কলামগুলো দুই জায়গায়
 * থাকত — একবার পর্দার টেবিলে, একবার CSV-তে — আর একদিন একটায় কলাম যোগ
 * হয়ে অন্যটায় হত না। তখন CSV আর পর্দা আলাদা কথা বলত, আর কোনটা সত্যি
 * তা নিয়ে দুইজন মানুষ তর্ক করত।
 *
 * তাই উৎস একটাই: x-ui.table নিজে যা আঁকছে সেটাই এখানে জমা দেয়, আর
 * ExportListing মিডলওয়্যার সেটাকে ফাইল বানায়। কলাম যোগ হলে দুই
 * জায়গাতেই যোগ হয়, কারণ জায়গা একটাই।
 */
class ListExport
{
    /** @var array{columns: list<array{key: string, label: string}>, values: list<list<string>>, total: ?int}|null */
    private ?array $table = null;

    /**
     * এই পর্দাটা রপ্তানি করতে দেয় না।
     *
     * ── কেন পতাকাটা লাগল ────────────────────────────────────────────
     * টুলবারে `:export="false"` লিখলে বোতামটা লুকাত, কিন্তু ঠিকানায়
     * `?export=csv` লাগিয়ে দিলে ফাইল ঠিকই নামত। অর্থাৎ সুইচটা ছিল
     * **আড়াল, বাধা নয়** — হুবহু সেই ভুল যেটা বন্ধ করা পর্দাগুলোয়
     * একবার ধরা পড়েছিল (৪০ নং)।
     *
     * ধরা পড়েছে রপ্তানির খাতা লিখতে গিয়ে: খাতাটা নিজেই নামানো যাচ্ছিল,
     * আর যিনি নিজের চিহ্ন ঢাকতে চান তাঁর প্রথম কাজই সেটা।
     */
    private bool $refused = false;

    /** এই পর্দায় রপ্তানি নেই — বোতামেও নয়, ঠিকানাতেও নয়। */
    public function refuse(): void
    {
        $this->refused = true;
    }

    /**
     * এই রিকোয়েস্টে CSV চাওয়া হয়েছে কি না।
     */
    public function wanted(): bool
    {
        return ! $this->refused && request()->query('export') === 'csv';
    }

    /**
     * টেবিলটা জমা নেওয়া — x-ui.table রেন্ডার হওয়ার সময়ে।
     *
     * ── প্রথমটাই, পরেরগুলো নয় ───────────────────────────────────────
     * কিছু পর্দায় একাধিক টেবিল থাকে (উপরে সারাংশ, নিচে বিস্তারিত)।
     * সবগুলো জোড়া দিলে CSV-তে দুই রকম কলামের সারি মিশে যেত, আর সেটা
     * কোনো স্প্রেডশিটে খোলার মতো ফাইল হত না। পর্দার প্রধান তালিকাটাই
     * প্রথমে আঁকা হয়, তাই প্রথমটাই ধরা হয়।
     *
     * @param  list<array{key: string, label: string, render: ?Closure}>  $columns
     * @param  iterable<int, mixed>  $rows
     * @param  Closure(mixed, array): mixed  $cell
     */
    public function capture(array $columns, iterable $rows, Closure $cell): void
    {
        if ($this->table !== null || $columns === []) {
            return;
        }

        $values = [];

        foreach ($rows as $row) {
            $line = [];

            foreach ($columns as $column) {
                $line[] = $this->text($cell($row, $column));
            }

            $values[] = $line;
        }

        $this->table = [
            'columns' => array_map(
                fn (array $column): array => ['key' => $column['key'], 'label' => $column['label']],
                $columns,
            ),
            'values' => $values,

            /*
             * মোট কত সারি ছিল — পাতা ভাগ থাকলে।
             *
             * CSV-তে যা বেরোচ্ছে তা এই পাতার সারিগুলো, কারণ paginator
             * নিজের কোয়েরিটা ধরে রাখে না; বাকি পাতাগুলো এখান থেকে আর
             * চাওয়ার উপায় নেই। চুপচাপ কেটে দেওয়া হবে না — টুলবারের
             * লেখাতেই বলা থাকে কতটা বেরোচ্ছে আর মোট কত।
             */
            'total' => $rows instanceof AbstractPaginator ? $rows->total() : null,
        ];
    }

    /**
     * আগের অনুরোধের টেবিলটা ফেলে দেওয়া।
     *
     * ── কেন এটা দরকার ───────────────────────────────────────────────
     * বাঁধনটা scoped, তাই সাধারণ php-fpm-এ প্রতিটা অনুরোধে নতুন বস্তু
     * আসে। কিন্তু একটাই প্রসেসে যদি পরপর দুইটা অনুরোধ চলে (Octane, বা
     * কোনো স্ক্রিপ্ট) তখন প্রথমটার ধরা টেবিলই রয়ে যেত — আর capture()
     * প্রথমটাকেই আঁকড়ে ধরে বলে দ্বিতীয় পর্দার রপ্তানিতে প্রথম পর্দার
     * সারিগুলো নামত। ধরা পড়েছিল ঠিক এভাবেই: অডিটের রপ্তানিতে
     * সরবরাহকারীর তালিকা।
     *
     * কন্টেইনার কখন বস্তু ফেলে দেয় তার উপর ভরসা না করে, মিডলওয়্যার
     * প্রতিটা অনুরোধের শুরুতে নিজেই পরিষ্কার করে।
     */
    public function reset(): void
    {
        $this->table = null;

        // অস্বীকারটাও প্রতি অনুরোধে নতুন — নাহলে একটা রপ্তানি-বিহীন
        // পর্দা দেখার পর পরের পর্দার রপ্তানিও নীরবে বন্ধ থাকত
        $this->refused = false;
    }

    /**
     * ধরা টেবিলটা, না ধরলে null।
     *
     * @return array{columns: list<array{key: string, label: string}>, values: list<list<string>>, total: ?int}|null
     */
    public function captured(): ?array
    {
        return $this->table;
    }

    /**
     * CSV ফাইলের বিষয়বস্তু — না ধরলে null।
     */
    public function csv(): ?string
    {
        if ($this->table === null) {
            return null;
        }

        /*
         * শুরুতে BOM।
         *
         * ছাড়া দিলে Excel ফাইলটা ANSI ধরে খোলে আর প্রতিটা বাংলা অক্ষর
         * প্রশ্নবোধক হয়ে যায় — ব্যবহারকারীর কাছে তখন রপ্তানিটা নষ্ট,
         * যদিও ফাইলটা ঠিকই আছে।
         */
        $csv = "\xEF\xBB\xBF";

        $csv .= $this->line(array_column($this->table['columns'], 'label'));

        foreach ($this->table['values'] as $row) {
            $csv .= $this->line($row);
        }

        return $csv;
    }

    /**
     * ফাইলের নাম — রুট থেকে, তাই প্রতিটা পর্দার নিজের নাম।
     */
    /**
     * কয়টা সারি ফাইলে গেল — খাতার জন্য।
     *
     * দশ সারির একটা ফাইল আর দশ হাজার সারির ফাইল দুইটার মানে সম্পূর্ণ
     * আলাদা, অথচ খাতায় দুইটাই "একটা রপ্তানি"।
     */
    public function rowCount(): int
    {
        return count($this->table['values'] ?? []);
    }

    public function filename(): string
    {
        $route = (string) (request()->route()?->getName() ?? 'list');

        return 'abos-'.str_replace('.', '-', $route).'-'.now()->format('Y-m-d').'.csv';
    }

    /**
     * একটা সারি — \r\n সহ।
     *
     * @param  list<string>  $values
     */
    private function line(array $values): string
    {
        return implode(',', array_map($this->field(...), $values))."\r\n";
    }

    /**
     * একটা ঘর — মুড়ে দেওয়া, আর সূত্র হিসেবে চলতে না দেওয়া।
     */
    private function field(string $value): string
    {
        /*
         * = + - @ দিয়ে শুরু হলে সামনে একটা উদ্ধৃতি।
         *
         * ── কেন ─────────────────────────────────────────────────────
         * Excel ও LibreOffice ওই চারটা অক্ষরে শুরু হওয়া ঘরকে সূত্র ধরে
         * চালায়। কেউ গ্রাহকের নামে =HYPERLINK(...) বা একটা কমান্ড
         * লিখে রাখলে সেটা আমাদের CSV হয়ে অন্য কারো মেশিনে গিয়ে চলত —
         * ডাটাবেজে নিরীহ একটা নাম, স্প্রেডশিটে একটা প্রোগ্রাম।
         *
         * TAB আর CR-ও ধরা হয়েছে, কারণ ওগুলো দিয়ে শুরু করলে কিছু
         * সংস্করণ সামনের অক্ষরটা ফেলে দেয় আর তার পরেরটাই সূত্র হয়ে যায়।
         */
        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            $value = "'".$value;
        }

        if (preg_match('/[",\r\n]/', $value) === 1) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }

    /**
     * একটা ঘরের বিষয়বস্তু থেকে পড়ার মতো লেখা।
     *
     * ঘরে View বসতে পারে (ব্যাজ, লিংক), HtmlString বসতে পারে, তারিখ বা
     * সংখ্যাও বসতে পারে। CSV-তে যাবে মানুষ যা পড়ে সেটাই — ব্যাজের
     * ভেতরের শব্দটা, লিংকের লেখাটা।
     */
    private function text(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof Renderable) {
            $value = $value->render();
        } elseif ($value instanceof Htmlable) {
            $value = $value->toHtml();
        }

        if (is_array($value)) {
            return '';
        }

        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES, 'UTF-8');

        /*
         * Blade-এর ইন্ডেন্ট ও লাইন ভাঙা এক ফাঁকায় নামানো।
         *
         * ছাড়া দিলে প্রতিটা ঘরে বিশ-ত্রিশটা স্পেস আর নতুন লাইন যেত,
         * আর স্প্রেডশিটে ঘরগুলো লম্বা হয়ে পড়ার অযোগ্য হত।
         */
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
