<?php

declare(strict_types=1);

namespace App\Core\Engines\Print;

use App\Core\Support\AmountInWords;

/**
 * ছাপার যোগ্য একটা ডকুমেন্ট — টেমপ্লেটের সাথে মডিউলের চুক্তি।
 *
 * ── কেন একটা DTO, সরাসরি মডেল নয় ─────────────────────────────────────
 * টেমপ্লেটকে মডেল দিলে তাকে জানতে হত কোন মডেলে লাইনের নাম `lines`, কোথায়
 * পরিমাণের ঘরটা `delivered_qty` আর কোথায় `received_qty`। তখন প্রতিটা নতুন
 * ডকুমেন্টের জন্য টেমপ্লেটে একটা করে শর্ত জুড়ত, আর কোর মডিউলের নাম জেনে
 * ফেলত (সেকশন ১৯.৭)।
 *
 * এখন উল্টো: মডিউল নিজের মডেলকে এই আকারে অনুবাদ করে দেয়, আর টেমপ্লেট
 * শুধু এই একটাই আকার চেনে। নতুন মডিউল এলে টেমপ্লেটে হাত পড়ে না।
 *
 * ── কেন টাকা দেখানো ঐচ্ছিক ───────────────────────────────────────────
 * গেটপাস ও ডেলিভারি অর্ডারে দাম থাকে না — ইচ্ছাকৃত। গেটপাস দারোয়ানের
 * কাগজ, আর ডেলিভারি অর্ডার গুদামের লোকের। ওখানে দাম ছাপলে গাড়ির চালক
 * থেকে দারোয়ান পর্যন্ত সবাই জেনে যেতেন কোন গ্রাহক কী দরে কেনেন, অথচ
 * কারও ওটা জানার দরকার নেই।
 */
final class PrintableDocument
{
    /**
     * @param  string  $title  কাগজের মাথায় যা ছাপা হবে
     * @param  array<string, string>  $meta  লেবেল => মান (নম্বর, তারিখ, পক্ষ…)
     * @param  list<array{name: string, qty: string, unit: string, rate: string, amount: string}>  $lines
     * @param  array<string, string>  $totals  লেবেল => অঙ্ক; শেষেরটা মোটা করে
     * @param  list<string>  $signatures  স্বাক্ষরের ঘরের লেবেল
     * @param  string|null  $notice  উপরে বড় করে সতর্কবার্তা — যেমন "খসড়া"
     */
    public function __construct(
        public readonly string $title,
        public readonly array $meta = [],
        public readonly array $lines = [],
        public readonly array $totals = [],
        public readonly array $signatures = [],
        public readonly bool $showMoney = true,
        public readonly ?string $amountInWords = null,
        public readonly ?string $narration = null,
        public readonly ?string $notice = null,
    ) {}

    /**
     * টাকার অঙ্ক থেকে কথায় বসিয়ে একটা কপি।
     *
     * টেমপ্লেটে না করে এখানে, কারণ ভাষাটা ছাপার ভাষা — ব্যবহারকারীর চলতি
     * ভাষা নয়। PrintEngine ছাপার সময় লোকেল বদলে দেয়, তাই DTO তৈরির
     * মুহূর্তে ডাকলে ভুল ভাষায় বসত।
     */
    /**
     * উপরের সতর্কবার্তাটা বদলে একটা কপি — যেমন "DUPLICATE"।
     *
     * ── কেন নতুন কপি, বসিয়ে দেওয়া নয় ────────────────────────────────
     * DTO-টা readonly, আর সেটা ইচ্ছাকৃত: কাগজটা তৈরি হওয়ার পর কেউ
     * যেন তার লাইন বা যোগফল বদলাতে না পারে। বার্তাটা কেবল তখনই জানা
     * যায় যখন দেখা হয় এই কাগজ আগে ছাপা হয়েছিল কি না — অর্থাৎ DTO
     * বানানোর পরে। তাই বদল নয়, নতুন একটা কপি।
     *
     * আগেরটা থাকলে সেটাই থাকে: খসড়ার "চূড়ান্ত নয়" লেখাটা DUPLICATE-এর
     * চেয়ে বেশি জরুরি — খসড়া দিয়ে কেউ টাকা চাইতে গেলে সেটা বড় ভুল।
     */
    public function withNotice(?string $notice): self
    {
        if ($this->notice !== null || $notice === null) {
            return $this;
        }

        return new self(
            title: $this->title,
            meta: $this->meta,
            lines: $this->lines,
            totals: $this->totals,
            signatures: $this->signatures,
            showMoney: $this->showMoney,
            amountInWords: $this->amountInWords,
            narration: $this->narration,
            notice: $notice,
        );
    }

    public function withWordsFor(string $amount, string $locale): self
    {
        return new self(
            title: $this->title,
            meta: $this->meta,
            lines: $this->lines,
            totals: $this->totals,
            signatures: $this->signatures,
            showMoney: $this->showMoney,
            amountInWords: AmountInWords::of($amount, $locale),
            narration: $this->narration,
            notice: $this->notice,
        );
    }
}
