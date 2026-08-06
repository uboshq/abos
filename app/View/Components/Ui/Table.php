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
     * @param  array{key: string, render: ?\Closure}  $column
     */
    public function cell(mixed $row, array $column): mixed
    {
        if ($column['render'] instanceof \Closure) {
            return ($column['render'])($row);
        }

        return data_get($row, $column['key']);
    }
}
