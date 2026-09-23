<?php

declare(strict_types=1);

namespace App\Core\Engines\Print;

/**
 * কাগজের বসানো রূপ — কোম্পানি বেছে নেয়, কেউ কোড লেখে না।
 *
 * ── ⭐ মালিকের নির্দেশ, ২২ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"১০-১৫ ফরমেট রাখবা সেলস বা বিক্রয় ইনভয়েজে/বিলের যাতে আলাদা আলাদা
 * ভাবে কোম্পানী গুলো বেচে নিতে পারে কোডিং না করেই।"*
 *
 * ⓘ একই রাতে দ্বিতীয় নির্দেশ: *"কি কি প্রিন্টে আসবে কি কি কলাম দিবে
 * কোনটার পর কোনটা সব কিছুই নিয়ন্ত্রণ হবে সুইচে।"*
 *
 * ── ⚠️ দুইটা নির্দেশ এক জিনিস, আর সেটা বোঝাই আসল কাজ ─────────────────
 * প্রথমটা শুনে মনে হয় পনেরোটা টেমপ্লেট চাই, দ্বিতীয়টা শুনে মনে হয়
 * একটা লম্বা সুইচের পাতা। ⛔ দুইটা আলাদা করে বানালে একদিন তারা আলাদা
 * কথা বলত — কেউ ফরম্যাট বাছতেন, তারপর একটা সুইচ বদলাতেন, আর কাগজে
 * কোনটা জিতবে তা কেউ বলতে পারত না।
 *
 * ⭐ তাই একটাই জিনিস: **একটা রূপ মানে একটা তৈরি করা প্রোফাইল**। বাছাই
 * করলে সুইচগুলো ভরে যায়, আর তারপর যেকোনো একটা আলাদা করে বদলানো যায়।
 * [[PrintProfile]] ঐ দুইটা মিলিয়ে চূড়ান্ত উত্তরটা দেয়।
 *
 * ── ⚠️ যে ফাঁদটা এখানে সবচেয়ে সহজ ─────────────────────────────────────
 * পনেরোটা নাম বসিয়ে দেওয়া যায় যার দশটা দেখতে **হুবহু এক**। ⛔ তখন
 * তালিকাটা মিথ্যা বলে: মালিক পনেরোটা রূপ পেয়েছেন ভাবেন, আসলে পাঁচটা।
 * ⓘ সেজন্য [[EveryPrintFormatReallyLooksDifferentTest]] প্রতিটা রূপে
 * সত্যিই কাগজ আঁকে আর দেখে কোনো দুইটার ফল এক কি না — "আলাদা লেখা আছে"
 * নয়, **"আলাদা দেখায়"**।
 */
final class PrintFormat
{
    /** ছকের রেখা — চারদিকে ঘেরা, কেবল সারির নিচে, বা কিছুই না। */
    public const RULE_GRID = 'grid';

    public const RULE_ROWS = 'rows';

    public const RULE_CLEAN = 'clean';

    private function __construct(
        public readonly string $name,
        /**
         * কাগজে যে অংশগুলো আসবে।
         *
         * @var list<string>
         */
        public readonly array $parts,
        /**
         * পণ্যের ছকের কলাম — **ক্রম সহ**।
         *
         * ⓘ তালিকার ক্রমটাই কাগজের ক্রম, আর সেটাই মালিকের *"কোনটার পর
         * কোনটা"*। ⚠️ নমুনার ক্রম আমাদের চলতি ক্রম নয়: ওখানে দর আসে
         * পরিমাণের **আগে**, আর একক আসে পরিমাণের **পরে**।
         *
         * @var list<string>
         */
        public readonly array $columns,
        public readonly string $rule,
        /** সারির উচ্চতা কতটা ঘন — ১.০ স্বাভাবিক, ০.৭৫ ঘন, ১.২৫ খোলা */
        public readonly float $density,
        /** সারিগুলো ডোরাকাটা — ত্রিশ সারির বিলে চোখ লাইন হারায় না */
        public readonly bool $zebra,
    ) {}

    /**
     * তেরোটা বসানো রূপ।
     *
     * ── ⓘ কেন প্রতিটার পাশে কার কাগজ লেখা ────────────────────────────
     * নামটা ইংরেজি স্লাগ, কিন্তু বাছেন যিনি তিনি স্লাগ পড়ে বোঝেন না।
     * ⚠️ পর্দায় যায় [[label()]]-এর বাংলা নাম; এখানকার মন্তব্যটা পরের
     * জনের জন্য — যাতে পনেরোতম রূপ বসানোর সময় সে জানে কোনটা কার কাগজ।
     *
     * @return array<string, array{0: list<string>, 1: list<string>, 2: string, 3: float, 4: bool}>
     */
    private static function table(): array
    {
        /* চলতি কাগজের ক্রম — আজ পর্যন্ত যা ছাপা হয়ে এসেছে */
        $ours = ['sl', 'name', 'unit', 'qty', 'free', 'rate', 'amount'];

        /* ⭐ মালিকের নমুনার ক্রম: SL# · Code · Description · Rate · QTY · Unit · Free · Total */
        $sample = ['sl', 'code', 'name', 'rate', 'qty', 'unit', 'free', 'amount'];

        /* দর ছাড়া — গুদাম ও গেটের কাগজ */
        $noMoney = ['sl', 'name', 'unit', 'qty', 'free'];

        $full = PrintProfile::PARTS;
        $noPaid = array_values(array_diff($full, ['paid_table']));
        $plain = array_values(array_diff($full, ['paid_table', 'words']));
        $blankHead = array_values(array_diff($noPaid, ['logo', 'company_name', 'address', 'phone', 'bin']));

        return [
            /* সাধারণ — আজ পর্যন্ত যা ছাপা হয়ে এসেছে, অবিকল */
            'standard' => [$noPaid, $ours, self::RULE_ROWS, 1.0, false],

            /* ⭐ মালিকের নমুনা: পরিবেশকের বিল — ব্যান্ড-ভিত্তিক উপ-মোট, আদায়ের ছক */
            'distributor' => [$full, $sample, self::RULE_GRID, 0.85, false],

            /*
             * ⛔ এখানে `distributor_lite` ছিল — "নমুনার ক্রম, আদায়ের ছক ছাড়া"।
             *
             * ⚠️ [[EveryPrintFormatReallyLooksDifferentTest]] প্রথম চালেই ধরেছে:
             * ওটা আর `distributor` **হুবহু এক কাগজ আঁকে**। ⓘ দুইটার একমাত্র
             * তফাত `paid_table`, আর আদায়ের ছকটা এখনো আঁকা হয় না (কাজটা
             * চলছে) — তাই সুইচটা বন্ধ করলেও কাগজে কিছুই বদলায় না।
             *
             * ⓘ নামটা রেখে দিলে মালিক একটা **ভুয়া বাছাই** পেতেন: তালিকায়
             * দুইটা নাম, কাগজে একটা চেহারা। ⭐ আদায়ের ছক এলে নামটা ফিরে
             * আসবে, আর তখন পাহারাটাই বলে দেবে ওটা সত্যিই আলাদা।
             */

            /* ঘেরা ছক — প্রতিটা ঘরে রেখা, কর-পরিদর্শকের পছন্দের চেহারা */
            'boxed' => [$noPaid, $ours, self::RULE_GRID, 1.0, false],

            /* রেখাহীন — পরিষ্কার; করপোরেট গ্রাহকের কাছে যায় */
            'clean' => [$noPaid, $ours, self::RULE_CLEAN, 1.1, false],

            /* ঘন — অনেক সারির বিল এক পাতায় ধরাতে */
            'compact' => [$plain, $ours, self::RULE_ROWS, 0.75, false],

            /* ঘন + কোড — পণ্যের কোড ধরে মেলানো হয় যেখানে */
            'compact_coded' => [$plain, $sample, self::RULE_ROWS, 0.75, false],

            /* ছাপানো প্যাড — মাথার জায়গা ফাঁকা, কাগজে আগেই ছাপা আছে */
            'letterhead' => [$blankHead, $ours, self::RULE_ROWS, 1.0, false],

            /* ছাপানো প্যাড + ঘেরা ছক */
            'letterhead_boxed' => [$blankHead, $ours, self::RULE_GRID, 1.0, false],

            /* ছাপানো প্যাড + নমুনার ক্রম */
            'letterhead_coded' => [$blankHead, $sample, self::RULE_ROWS, 1.0, false],

            /* ডোরাকাটা — ত্রিশ সারির বিলে চোখ লাইন হারায় না */
            'striped' => [$noPaid, $ours, self::RULE_ROWS, 1.0, true],

            /* ডোরাকাটা + নমুনার ক্রম + আদায় — পাইকারি বাজারের পূর্ণ কাগজ */
            'wholesale' => [$full, $sample, self::RULE_GRID, 0.85, true],

            /* খোলা — বড় লেখা, কম সারি; বয়স্ক পাঠকের জন্য */
            'roomy' => [$noPaid, $ours, self::RULE_ROWS, 1.25, false],

            /*
             * দাম ছাড়া — গুদাম ও গেটের কাগজ, কোনো দর কোথাও নেই।
             *
             * ── ⛔ নামটা সত্যি বলত না, ২৩ সেপ্টেম্বর ২০২৬ ────────────
             * ⓘ এখানে কেবল `$noMoney` কলাম বসত, অর্থাৎ দর ও টাকার
             * **কলাম** দুইটা যেত। ⚠️ কিন্তু `prices` অংশটা চালুই
             * থাকত, তাই কাগজের নিচে "উপ-মোট · ছাড় · প্রদেয়" ঠিকই
             * ছাপা হত।
             *
             * ⛔ ফল: একটা কাগজে কোনো দর নেই, অথচ নিচে "প্রদেয়
             * ১১,০০০" — আর সেটাই সবচেয়ে বাজে অবস্থা, কারণ সংখ্যাটা
             * রয়ে গেল আর তার উৎসটা উধাও। ⚠️ ধরা পড়ল নমুনা আঁকার
             * পর্দাটা বানানোর সময়, চোখে ([[PrintSample]])।
             *
             * ⭐ তাই `prices`ও নামানো — নামটা যা বলে কাগজটা এখন তাই।
             */
            'no_price' => [
                array_values(array_diff($plain, ['prices'])),
                $noMoney, self::RULE_ROWS, 1.0, false,
            ],
        ];
    }

    public static function of(string $name): self
    {
        $known = self::table();
        $name = isset($known[$name]) ? $name : 'standard';
        [$parts, $columns, $rule, $density, $zebra] = $known[$name];

        return new self($name, $parts, $columns, $rule, $density, $zebra);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::table());
    }

    /**
     * কোন রূপে ছাপা হবে।
     *
     * ⓘ ক্রমটা [[PaperSize::chosen()]]-এর মতোই: ঠিকানায় যা চাওয়া হয়েছে
     * → না থাকলে কোম্পানির বসানো রূপ → তাও না থাকলে সাধারণ।
     *
     * ⚠️ অচেনা নাম এলে ৪০৪ নয় — পুরনো বুকমার্কের জন্য বিলটা না ছাপার
     * কোনো কারণ নেই।
     */
    public static function chosen(?string $requested, ?string $configured): string
    {
        foreach ([$requested, $configured] as $candidate) {
            if (is_string($candidate) && in_array($candidate, self::all(), true)) {
                return $candidate;
            }
        }

        return 'standard';
    }

    public function label(): string
    {
        return __('core.print.format.'.$this->name);
    }
}
