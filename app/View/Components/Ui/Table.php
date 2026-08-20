<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\Core\Services\ListExport;
use Illuminate\View\Component;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * তালিকা দেখানোর একমাত্র কম্পোনেন্ট — সেকশন ১৫.২৪ ও ২০.৩।
 *
 * কলামগুলো PHP-তে সংজ্ঞায়িত, Blade-এ <td> হাতে লেখা নয়। কারণ মোবাইলে
 * প্রতিটা সারি card হয়ে যায়, আর তখন প্রতিটা ঘরে data-label না থাকলে
 * ব্যবহারকারী শুধু কতগুলো সংখ্যা দেখে — কোনটা কী বোঝার উপায় থাকে না।
 *
 * হাতে লিখলে সেটা ভোলা সবচেয়ে সহজ, আর ডেস্কটপে টেস্ট করলে কখনো ধরা পড়ে
 * না। এখানে label ছাড়া কলাম তৈরিই করা যায় না — ভোলার সুযোগ নেই।
 */
class Table extends Component
{
    /** @var list<array{key: string, label: string, numeric: bool, width: ?string, render: ?\Closure}> */
    public array $normalised = [];

    /**
     * @param  iterable<int, mixed>  $rows
     * @param  list<array<string, mixed>|string>  $columns
     */
    public function __construct(
        public iterable $rows = [],
        array $columns = [],
        public ?string $empty = null,
        public bool $compact = false,
        /*
         * কার্ড রূপ বড় পর্দাতেও।
         *
         * ছোট পর্দায় এটা এমনিতেই হয় (CSS), তাই এই সুইচটা শুধু বড় পর্দার
         * জন্য — ব্যবহারকারী টুলবারের View টগল থেকে বেছে নেন। কিছু
         * তালিকা কার্ড হিসেবেই বেশি পড়ার মতো, বিশেষ করে যেখানে সারি কম
         * আর প্রতিটা সারিতে অনেক তথ্য।
         */
        public bool $grid = false,

        /*
         * পাতার যোগফলের সারি — কলামের চাবি => যা লেখা হবে।
         *
         * ── কেন "এই পাতায়", "মোট" নয় ────────────────────────────────
         * তালিকা পাতায় ভাগ হয়। এখানে যা যোগ করা হয় তা **চোখের সামনের
         * সারিগুলোরই** যোগ, গোটা তালিকার নয়। "মোট" লিখলে কেউ ওটাকে
         * বকেয়ার মোট ভেবে নিতেন, আর তিন নম্বর পাতায় গিয়ে অন্য সংখ্যা
         * দেখে বিভ্রান্ত হতেন।
         *
         * যোগফলটা যে দিচ্ছে সে-ই লেখাটাও দেয়, তাই কম্পোনেন্ট কোনো
         * অঙ্ক নিজে করে না — ভুল যোগফল দেখানোর চেয়ে না দেখানো ভালো।
         *
         * @var array<string, string>
         */
        public array $totals = [],

        /*
         * যোগফলের সারিটা আসলে কী বলছে।
         *
         * ── কেন লেখাটা বাছাই করা যায় ─────────────────────────────────
         * ডিফল্টে "এই পাতার যোগফল", আর বেশিরভাগ তালিকায় সেটাই সত্যি।
         * কিন্তু সব শেষ সারি যোগফল নয়: টাকা ও হেফাজতের পর্দায় শেষ
         * সারিটা বলে **"এই টাকা এই মুহূর্তে কারও হেফাজতে নেই"** — ওটা
         * যোগ নয়, একটা আলাদা অবস্থা।
         *
         * লেখাটা স্থির রাখলে ওই পর্দাটা হয় মিথ্যা বলত, নয় কম্পোনেন্টের
         * বাইরে হাতে লেখা টেবিল হয়ে থাকত — আর হাতে লেখা টেবিল থিম
         * মানে না।
         */
        public ?string $totalsLabel = null,
    ) {
        foreach ($columns as $index => $column) {
            if (is_string($column)) {
                throw new InvalidArgumentException(
                    "Column {$index} is just a string. Every column needs a label, because on a phone the "
                    .'table header is hidden and the label is the only thing telling the reader what a '
                    .'value is.'
                );
            }

            if (! isset($column['key'], $column['label'])) {
                throw new InvalidArgumentException(
                    "Column {$index} needs both 'key' and 'label'."
                );
            }

            $this->normalised[] = [
                'key' => $column['key'],
                'label' => $column['label'],
                'numeric' => (bool) ($column['numeric'] ?? false),
                'width' => $column['width'] ?? null,
                // ঘরের ভেতরে লিংক বা ব্যাজ বসানোর একমাত্র পথ।
                //
                // এটা ছাড়া প্রতিটা তালিকা-স্ক্রিনে HtmlString হাতে বানিয়ে
                // কম্পোনেন্ট নিজে রেন্ডার করতে হত — Customer মডিউল লিখতে
                // গিয়ে ঠিক সেটাই ঘটেছিল, আর সেটাই ছিল ভিত্তির ফাঁকের চিহ্ন।
                'render' => $column['render'] ?? null,
            ];
        }

        /*
         * টুলবারের Columns মেনুতে যেগুলোর টিক তোলা, সেগুলো এখানে বাদ।
         *
         * ── কেন এই জোড়াটা না থাকলে বোতামটা মৃত ──────────────────────────
         * টুলবারে Columns মেনু আগেই লেখা ছিল, আর সে টিক তোলা কলামগুলো
         * ঠিকানায় `?hide=code,branch` হিসেবে বসাত। কিন্তু টেবিল ওই
         * প্যারামিটারটা পড়তই না — অর্থাৎ মেনুতে টিক তুললে ঠিকানা বদলাত,
         * পাতা নতুন করে খুলত, আর কলামটা যেমন ছিল তেমনই থাকত।
         *
         * এটাই এই টুলবারের নিজের মন্তব্যে সতর্ক করা সেই ভুল: "কাজটা আছে
         * বলে দেখায়"। ছয়টা মৃত বোতাম সরানো হয়েছিল ঠিক এই কারণেই, আর
         * সপ্তমটা নীরবে থেকে গিয়েছিল।
         *
         * ঠিকানায় রাখার কারণ টুলবারে লেখা: লিংকটা পাঠালে সহকর্মী ঠিক
         * যা দেখছেন তা-ই দেখেন।
         *
         * সব কলাম লুকানো গেলে টেবিলটা শূন্য হয়ে যেত — তখন লুকানোটাই
         * উপেক্ষা করা হয়, কারণ কলামহীন টেবিলের চেয়ে সব কলাম ভালো।
         */
        $hidden = collect(explode(',', (string) request('hide')))
            ->map(fn (string $key) => trim($key))
            ->filter()
            ->all();

        if ($hidden !== []) {
            $kept = array_values(array_filter(
                $this->normalised,
                fn (array $column) => ! in_array($column['key'], $hidden, true),
            ));

            if ($kept !== []) {
                $this->normalised = $kept;
            }
        }
    }

    public function render(): View
    {
        /*
         * জেনারেটর হলে একবারেই তালিকা বানিয়ে নেওয়া।
         *
         * রপ্তানি আর পর্দা দুইজনেই সারিগুলো ঘুরে দেখে, আর জেনারেটর
         * দ্বিতীয়বার ঘোরানো যায় না — তখন CSV-তে সব সারি থাকত আর
         * পর্দাটা খালি আসত (বা উল্টোটা)। Collection ও paginator
         * Countable, তাই তাদের এই খরচটা লাগে না।
         */
        if (! is_array($this->rows) && ! $this->rows instanceof \Countable) {
            $this->rows = iterator_to_array($this->rows);
        }

        /*
         * টুলবারের "Export CSV" এখান থেকেই তার ডেটা পায়।
         *
         * কলাম দুই জায়গায় লেখা হয় না বলেই এখানে — যা পর্দায় আঁকা হচ্ছে
         * হুবহু সেটাই ফাইলে যায়।
         */
        $export = app(ListExport::class);

        if ($export->wanted()) {
            $export->capture($this->normalised, $this->rows, $this->cell(...));
        }

        return view('components.ui.table');
    }

    /**
     * একটা ঘরের বিষয়বস্তু।
     *
     * কলামের নিজের render থাকলে সেটাই চলে, আর তার ফেরত দেওয়া HtmlString
     * অক্ষত থাকে (লিংক, ব্যাজ)। না থাকলে সাধারণ মান, যা Blade নিজেই
     * escape করে — অর্থাৎ HTML বেরোনোর একমাত্র দরজা এই render, যেটা
     * ডেভেলপার সচেতনভাবে লেখে।
     *
     * ── render দ্বিতীয় একটা সংখ্যাও পায়: এই পাতায় সারিটা কত নম্বর ──
     * ক্রম নম্বরের কলামের জন্য (SL#), যেটা কোনো ঘরে জমা থাকে না — ওটা
     * সারির অবস্থান, তথ্য নয়। যে কলামগুলোর দরকার নেই তারা কেবল প্রথম
     * প্যারামিটারটা নেয়, আর PHP বাড়তিটা চুপচাপ ফেলে দেয়।
     *
     * পাতার নম্বর এখানে যোগ করা হয় না — সেটা তালিকার নিজের কাজ
     * (paginator->firstItem()), কারণ টেবিল জানে না সে কোন পাতায় আছে।
     *
     * @param  array{key: string, render: ?\Closure}  $column
     */
    public function cell(mixed $row, array $column, int $index = 0): mixed
    {
        if ($column['render'] instanceof \Closure) {
            return ($column['render'])($row, $index);
        }

        return data_get($row, $column['key']);
    }
}
