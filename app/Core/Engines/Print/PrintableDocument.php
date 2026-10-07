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

        /**
         * ⭐ এই কাগজের বিপরীতে আসা টাকার সারিগুলো — ২২ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ মালিকের নমুনা বিলের বাঁ-নিচে একটা ছোট ছক: ক্রম · লেনদেন
         * নম্বর · তারিখ · কোন পথে · বিবরণ · টাকা। ⚠️ উদ্দেশ্য একটাই —
         * গ্রাহক যেন ফোন করে জিজ্ঞেস না করেন *"আমার জমাটা বসেছে কি না"*।
         *
         * ⛔ খালি রাখলে ছকটা আঁকাই হয় না, তাই যে কাগজে টাকার প্রশ্ন
         * নেই (চালান, অর্ডার) সেখানে কিছুই বদলায় না।
         *
         * @var list<array{no: int, ref: string, date: string, method: string, narration: string, amount: string}>
         */
        public readonly array $payments = [],

        /**
         * ⭐ কাগজের QR — সই-করা ঠিকানা (১ অক্টোবর ২০২৬, মালিক: "এক কাগজে এক QR")।
         *
         * ⓘ গেট পাসে গেটম্যান এটাই স্ক্যান করে "মাল বেরোল" চাপেন। ⛔ এখানে কেবল অস্বচ্ছ
         * টোকেনের ঠিকানা আসে, চালান নম্বর বা দোকানের নাম নয় ([[PaperToken]])। খালি থাকলে আঁকা হয় না।
         */
        public readonly ?string $qrUrl = null,

        /**
         * ⭐ ছাপার মুহূর্তে বেছে নেওয়া — টাকাসহ (true) না টাকা ছাড়া (false); null মানে বাছা হয়নি, তখন কাগজের
         * সাধারণ নিয়ম (`showMoney` আর দামের সুইচ)। ⓘ চালান, মালিক, ২ অক্টোবর ২০২৬ — বাছা থাকলে সেটাই চূড়ান্ত।
         */
        public readonly ?bool $pricesChosen = null,
    ) {}

    /**
     * উপরের সতর্কবার্তাটা বদলে একটা কপি — যেমন "DUPLICATE"।
     *
     * ── কেন নতুন কপি, বসিয়ে দেওয়া নয় ────────────────────────────────
     * DTO-টা readonly, আর সেটা ইচ্ছাকৃত: কাগজটা তৈরি হওয়ার পর কেউ
     * যেন তার লাইন বা যোগফল বদলাতে না পারে। বার্তাটা কেবল তখনই জানা
     * যায় যখন দেখা হয় এই কাগজ আগে ছাপা হয়েছিল কি না — অর্থাৎ DTO
     * বানানোর পরে। তাই বদল নয়, নতুন একটা কপি।
     *
     * একই বার্তা দুইবার বসে না, কিন্তু আলাদা বার্তাগুলো সবই থাকে —
     * কারণটা নিচে।
     */
    public function withNotice(?string $notice): self
    {
        if ($notice === null || in_array($notice, $this->notices(), true)) {
            return $this;
        }

        /*
         * আগেরটা থাকলে নতুনটা তার **পাশে** বসে, জায়গায় নয়।
         *
         * ── কী ভুল হচ্ছিল ───────────────────────────────────────────
         * আগে প্রথম বার্তাটাই থেকে যেত আর পরেরগুলো নীরবে হারাত। গেটপাসে
         * তৈরির সময়েই "দাম লেখা নেই" বসে, তাই **বাতিল করা চালানের
         * গেটপাসে "বাতিল" কথাটা কোনোদিন উঠত না** — আর ওই কাগজটাই
         * দেখিয়ে গেট থেকে মাল বেরোয়। DUPLICATE-ও একই কারণে হারাত।
         *
         * একটা কাগজের একাধিক কথা বলার থাকতে পারে: "বাতিল", "দাম লেখা
         * নেই", "DUPLICATE" — তিনটাই সত্যি, আর তিনটাই পাঠকের জানা
         * দরকার। তাই বার্তা একটা নয়, তালিকা।
         */
        $stacked = [...$this->notices(), $notice];

        return new self(
            title: $this->title,
            meta: $this->meta,
            lines: $this->lines,
            totals: $this->totals,
            signatures: $this->signatures,
            showMoney: $this->showMoney,
            amountInWords: $this->amountInWords,
            narration: $this->narration,
            notice: implode(' · ', $stacked),
            payments: $this->payments,
            qrUrl: $this->qrUrl,
            pricesChosen: $this->pricesChosen,
        );
    }

    /**
     * কাগজের বার্তাগুলো — আলাদা আলাদা।
     *
     * @return list<string>
     */
    /**
     * "আগেও ছাপা হয়েছে"-র চিহ্ন — দুই ভাষার লেখাই এটা দিয়ে শুরু (`core.print.duplicate_notice`)।
     *
     * ⭐ মালিক, ৩০ সেপ্টেম্বর ২০২৬: লেখায় কততম ছাপা ("DUPLICATE — Print No. 3")। ⚠️ তাই লেখাটা আর এক রকম নয়,
     * আর হুবহু মিলিয়ে চেনা যায় না; শুরুর শব্দে চেনা হয়। ⓘ ভাষা-নিরপেক্ষ — ছাপার সময় ইঞ্জিন লোকেল বদলায়,
     * আর নোটিশটা বানানো হয় ব্যবহারকারীর ভাষায়; হুবহু মেলাতে গেলে দুই ভাষার মাঝে চিহ্নটা হারাত।
     */
    public const DUPLICATE_MARK = 'DUPLICATE';

    /** নোটিশটা কি "আগেও ছাপা হয়েছে"? */
    public static function isDuplicateNotice(string $notice): bool
    {
        return str_starts_with(trim($notice), self::DUPLICATE_MARK);
    }

    /** এই কাগজের "আগেও ছাপা হয়েছে" লেখা, নম্বরসহ — না থাকলে null */
    public function duplicateNotice(): ?string
    {
        foreach ($this->notices() as $notice) {
            if (self::isDuplicateNotice($notice)) {
                return $notice;
            }
        }

        return null;
    }

    public function notices(): array
    {
        if ($this->notice === null || trim($this->notice) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(' · ', $this->notice))));
    }

    /**
     * টাকার অঙ্ক থেকে কথায় বসিয়ে একটা কপি।
     *
     * টেমপ্লেটে না করে এখানে, কারণ ভাষাটা ছাপার ভাষা — ব্যবহারকারীর চলতি
     * ভাষা নয়। PrintEngine ছাপার সময় লোকেল বদলে দেয়, তাই DTO তৈরির
     * মুহূর্তে ডাকলে ভুল ভাষায় বসত।
     */
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

            /*
             * ⛔ এই লাইনটা ছিল না — ২৮ সেপ্টেম্বর ২০২৬ পর্যন্ত।
             *
             * ⓘ প্রতিটা বিল ছাপার আগে এই কপিটা বানানো হয়, আর কপিতে আদায়ের
             * সারিগুলো আসত না। ⚠️ ফল: আদায়ের ছকটা **কোনোদিন কাগজে ওঠেনি**,
             * অথচ সারি তোলার পরীক্ষা সবুজ ছিল — কারণ পরীক্ষা সারিগুলো তুলত,
             * কাগজ আঁকত না। [[AClassicTableInvoiceCanBeChosenTest]] এখন কাগজটাই আঁকে।
             */
            payments: $this->payments,
            qrUrl: $this->qrUrl,
            pricesChosen: $this->pricesChosen,
        );
    }
}
