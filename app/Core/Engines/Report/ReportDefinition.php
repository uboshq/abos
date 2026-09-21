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

        /*
         * কে এই রিপোর্টটা দেখতে পারেন — অনুমতির নাম।
         *
         * ── কেন definition-এ, শুধু কন্ট্রোলারে নয় ───────────────────
         * পর্দায় অনুমতি বসে কন্ট্রোলারের মিডলওয়্যারে। কিন্তু নির্ধারিত
         * রিপোর্টের কোনো কন্ট্রোলার নেই — ক্রন নিজে চালায়। তখন "এই
         * রিপোর্টটা কে পেতে পারেন" প্রশ্নের উত্তর definition-এ না থাকলে
         * সূচি সেটা যাচাই করতেই পারত না, আর একজনের বানানো সূচি
         * অনুমতিহীন দশজনের ইমেইলে ক্রয়মূল্য পাঠাত।
         *
         * ── null মানে "সবার জন্য খোলা" নয়, "জানি না" ─────────────────
         * null হলে সূচি রিপোর্টটা **সূচি-নির্মাতা ছাড়া কাউকে পাঠাবে না**।
         * ঐচ্ছিক ঘর বলে বেশিরভাগ রিপোর্ট এটা ঘোষণা করবে না; তখন
         * "null = সবাই" ধরে নিলে পাহারাটা নীরবে কিছুই যাচাই করত না।
         * না-জানা মানে বাইরে পাঠানো নয় — যে রিপোর্ট সত্যিই বিতরণযোগ্য,
         * কেউ একটা করে ভেবে এই ঘোষণাটা বসাবে।
         */
        public readonly ?string $permission = null,

        /**
         * সারিগুলোর উপরে এক লাইনে ফলটা — ঐচ্ছিক।
         *
         * ── ⛔ কী ভাঙা ছিল, ৭ সেপ্টেম্বর ২০২৬ ────────────────────────
         * লাভ-ক্ষতির পর্দা খাত ধরে ধরে সব দেখাত, আর নিচে লিখত
         * **"সর্বমোট ২৭,০০০"** — ব্যস। ⓘ লাভ হয়েছে না ক্ষতি, পর্দা
         * কোথাও বলত না।
         *
         * ⚠️ অথচ মালিকের কাছে ওটাই একমাত্র প্রশ্ন। ⛔ একটা লাভ-ক্ষতি
         * হিসাব যেটা লাভ কত তা বলে না, সে হিসাব নয় — সে একটা তালিকা।
         *
         * ── কেন ইঞ্জিনে, আলাদা পর্দায় নয় ────────────────────────────
         * ⓘ স্থিতিপত্রের নিজের একটা পর্দা আছে, আর সে ওখানেই ব্যানারটা
         * আঁকে। ⚠️ লাভ-ক্ষতির জন্য দ্বিতীয় একটা পর্দা বানালে ছাঁকনি,
         * রপ্তানি, ছাপা — সব দুইবার লিখতে হত, আর একদিন একটা বদলে
         * অন্যটা পিছিয়ে থাকত।
         *
         * ⭐ ঘরটা `null` রাখলে কিছুই বদলায় না, তাই বাকি ৩৬টা রিপোর্ট
         * অক্ষত।
         *
         * ⓘ আর্গুমেন্ট একটাই — যোগফলগুলো। ঘোষণাটায় আগে দুইটা লেখা ছিল,
         * অথচ একমাত্র বাস্তবায়ন (`CoreReports::profitAndLoss()`) আর একমাত্র
         * কল-সাইট (`ReportController`) দুইটাই একটা দেয়। কোড ঠিক ছিল, ঘোষণা
         * ভুল — আর ঘোষণাটাই পরের জন পড়ত।
         *
         * @var null|Closure(array<string, string>): array{label: string, value: string, good: bool}
         */
        public readonly ?Closure $summary = null,
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

    /**
     * একটা ঘোষিত ছাঁকনি ঠিকানায় কোন নামে আসে।
     *
     * ⓘ নামদুটো এক নয়: ঘোষণায় `branch`, ঠিকানায় `branch_id`। আগে এই
     * অনুবাদটা আটটা কন্ট্রোলারে আটবার হাতে লেখা ছিল।
     *
     * @var array<string, list<string>>
     */
    private const ASKED_AS = [
        'date_range' => ['from', 'to'],
        'branch' => ['branch_id'],
        'party_type' => ['party_type_id'],
        'cost_centre' => ['cost_center_id'],
        'account' => ['account_id'],
    ];

    /**
     * ⭐ ঠিকানা থেকে যে ঘরগুলো নেওয়া হবে — ঘোষণা থেকেই।
     *
     * ── ⛔ কেন এটা কোরে, কন্ট্রোলারে নয় (২১ সেপ্টেম্বর ২০২৬) ────────
     * আটটা রিপোর্ট কন্ট্রোলারের প্রতিটায় `$request->only([...])`-এর
     * একটা **হাতে লেখা** তালিকা ছিল, আর তালিকাগুলো এক ছিল না।
     *
     * ⚠️ ফল: ছয়টা কন্ট্রোলার `party_type_id` পাঠাত না, অথচ রিপোর্টগুলো
     * ছাঁকনিটা **ঘোষণা করত** আর পর্দায় ঘরটা আঁকা হত। ⛔ ব্যবহারকারী
     * বেছে দিতেন, পাতা আবার আসত, আর **কিছুই বদলাত না** — কোনো ত্রুটি
     * নেই, কোনো পরীক্ষা লাল নেই। মালিকের কথা: *"Filter Fanctional
     * korba full"*।
     *
     * ⓘ এখন ঘরটা যে ঘোষণা থেকে **আঁকা** হয়, সেই একই ঘোষণা থেকেই
     * **পড়া** হয় — দুইটা আলাদা হওয়ার আর কোনো উপায় নেই।
     *
     * @return list<string>
     */
    public function requestKeys(): array
    {
        $keys = [];

        foreach ($this->filters as $filter) {
            foreach (self::ASKED_AS[$filter] ?? [$filter] as $key) {
                $keys[] = $key;
            }
        }

        /*
         * ⓘ এই তিনটা কোনো রিপোর্টের নিজস্ব নয় — প্রতিটা তালিকার সাথেই
         * আসে, তাই ঘোষণায় লেখার কিছু নেই।
         *
         * ⚠️ `top` কেবল তখনই কিছু করে যখন রিপোর্ট `rankBy` বলে দিয়েছে
         * (ইঞ্জিন নিজে দেখে নেয়), আর `compare` কেবল `date_range` থাকলে।
         * ⛔ তবু দুইটাই সবসময় পাঠানো হয়: না পাঠালে ইঞ্জিনের ঐ
         * পরীক্ষাগুলো কোনোদিন চলতই না, আর নিয়মটা দুই জায়গায় দুইবার
         * লেখা হত।
         */
        return [...$keys, 'q', 'top', 'compare'];
    }

    /**
     * কোন কলামগুলোতে খোঁজা যায়।
     *
     * ⓘ লেখার কলাম — নাম, নথির নম্বর, তারিখ। ⛔ টাকা আর পরিমাণ বাদ:
     * ওখানে `১২৩` লিখলে `১২৩৪৫.০০`ও মিলত। শতাংশ বাদ, কারণ ওটা
     * কষা সংখ্যা, কেউ ওটা ধরে খোঁজেন না।
     *
     * @return list<string>
     */
    public function searchableColumns(): array
    {
        $searchable = [ReportColumn::TEXT, ReportColumn::DOCUMENT, ReportColumn::DATE];

        return array_values(array_map(
            fn (ReportColumn $c) => $c->key,
            array_filter($this->columns, fn (ReportColumn $c) => in_array($c->type, $searchable, true)),
        ));
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
