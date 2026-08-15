<?php

declare(strict_types=1);

namespace App\Core\Engines\Report;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * একটা রিপোর্টের সংজ্ঞা — কোয়েরি, কলাম, ফিল্টার।
 *
 * প্ল্যান সেকশন ২.২: রিপোর্ট engine "কোয়েরি + কলাম + ফিল্টার কনফিগে;
 * রেন্ডার এক জায়গায়"। ৩০+ রিপোর্টে একই কোড বারবার লেখার বদলে প্রতিটা
 * রিপোর্ট শুধু বলে দেয় সে কী চায়।
 *
 * কোয়েরিটা Closure, কারণ ফিল্টারের মান রান-টাইমে আসে — আগেই তৈরি করে
 * রাখা Builder-এ কোম্পানির স্কোপ ভুল সময়ে বসে যেত।
 */
final class ReportDefinition
{
    /** @var list<ReportColumn> */
    public readonly array $columns;

    /**
     * @param  Closure(array<string, mixed>): (QueryBuilder|EloquentBuilder)  $query
     * @param  list<array<string, mixed>>  $columns
     * @param  list<string>  $filters  কোন ফিল্টারগুলো এই রিপোর্টে আছে
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly Closure $query,
        array $columns,
        public readonly array $filters = ['date_range'],
        public readonly ?string $groupBy = null,
        public readonly bool $runningBalance = false,

        /*
         * একটা তারিখ **পর্যন্ত** জের, নাকি একটা পরিসরের ঘটনা?
         *
         * রেওয়ামিল ও ব্যালেন্স শিট একটা মুহূর্তের ছবি — "৩১ জুলাই পর্যন্ত
         * কার কত"। ওখানে "কবে থেকে" প্রশ্নটার কোনো উত্তর নেই; জের সবসময়
         * শুরু থেকেই গোনা। ডে বুক বা লাভ-লোকসান ঠিক উল্টো।
         *
         * পর্দা এটা দেখে ঠিক করে "From" ঘরটা দেখাবে কি না। ঘরটা দেখানো
         * হত দুইদিকেই, আর রেওয়ামিলের পাশে একটা তারিখ-ভরা "From" ঘর বসে
         * থাকলে ব্যবহারকারী ধরে নেন সংখ্যাটা ওই তারিখ থেকে গোনা।
         *
         * পতাকাটা মডিউল নিজে তোলে, কোর কোনো রিপোর্টের নাম জানে না (১৯.৭)।
         */
        public readonly bool $asOfDate = false,

        /*
         * কোন কলামটা সারিগুলোকে সাজায় — "সবচেয়ে বড়" মানে কী।
         *
         * ── এটা দুইটা জিনিস চালু করে ────────────────────────────────
         * ১. **অবদান %** — এই সারিটা মোটের কত অংশ। "প্রথম দশজন ক্রেতা
         *    মোট বিক্রয়ের ৬৮%" — এই বাক্যটা ছাড়া তালিকাটা শুধু বড়
         *    থেকে ছোট, কিন্তু কতটা বড় তা বলে না।
         * ২. **Top N** — উপরের কয়টা সারি। বিশ পাতার তালিকায় ওই
         *    বাক্যটা কেউ কোনোদিন লিখতে পারত না।
         *
         * ── কেন মডিউল বলে, কোর অনুমান করে না ────────────────────────
         * "সবচেয়ে বড়" মানে কোন কলাম, সেটা রিপোর্টভেদে আলাদা: কোথাও
         * মোট বিক্রয়, কোথাও মুনাফা, কোথাও বকেয়া দিন। কোর প্রথম টাকার
         * কলামটা ধরে নিলে মুনাফার রিপোর্ট বিক্রয় ধরে সাজাত, আর সেটা
         * ভুল উত্তর — অথচ দেখতে যুক্তিসঙ্গত।
         *
         * null মানে এই রিপোর্টে "সবচেয়ে বড়" বলে কিছু নেই (যেমন
         * ডে বুক — ওটা সময় ধরে সাজানো, আকার ধরে নয়)।
         */
        public readonly ?string $rankBy = null,
    ) {
        $this->columns = array_map(
            fn (array $column, int $index) => ReportColumn::fromArray($column, $index),
            $columns,
            array_keys($columns),
        );
    }

    /** @return list<ReportColumn> */
    public function totalledColumns(): array
    {
        return array_values(array_filter($this->columns, fn (ReportColumn $c) => $c->total));
    }

    public function hasFilter(string $name): bool
    {
        return in_array($name, $this->filters, true);
    }

    public function isAsOfDate(): bool
    {
        return $this->asOfDate;
    }
}
