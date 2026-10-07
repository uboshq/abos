<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Support;

/**
 * অফারের ধরন — স্পেকের ৭ নম্বর ধারার বারোটা।
 *
 * ── ⭐ মালিকের স্পেক, ২৫ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * শতকরা ছাড় · নির্দিষ্ট টাকার ছাড় · Buy X Get Y · কিনে ছাড় · পরিমাণের
 * স্ল্যাব · মূল্যের স্ল্যাব · কম্বো · বান্ডল · ফ্রি পণ্য · কুপন ·
 * ক্যাশব্যাক · লয়্যালটি।
 *
 * ── ⚠️ বারোটাই এখানে ঘোষিত, অথচ সবগুলো এখনো চলে না ──────────────────
 * ⓘ আর সেটা ইচ্ছাকৃত। ⛔ ধরনগুলো একটা একটা করে যোগ করলে ডাটাবেজের
 * কলামটা বারবার বদলাত, আর পুরনো সারিগুলোর মানে বদলে যেত।
 *
 * ⭐ কিন্তু *"ঘোষিত"* আর *"চলে"* এক নয়, তাই [[PromotionType::isBuilt()]]
 * আলাদা করে বলে কোনটা আজ সত্যিই কাজ করে। ⚠️ এটা না থাকলে পর্দায় বারোটা
 * ধরন বাছা যেত, আর দশটা বেছে নিলে অফারটা **নীরবে কিছুই করত না** — ঠিক
 * সেই আকারের ভুল যা এখানে বারবার ধরা পড়েছে: কাজটা ঘোষিত, জোড়াটা নেই।
 */
enum PromotionType: string
{
    /** §৭-ক — পণ্যে শতকরা ছাড়। */
    case PERCENT_DISCOUNT = 'percent_discount';

    /** §৭-খ — বিলে নির্দিষ্ট টাকার ছাড়। */
    case FIXED_DISCOUNT = 'fixed_discount';

    /** §৭-গ — ১০ কিনলে ১ ফ্রি। */
    case BUY_X_GET_Y = 'buy_x_get_y';

    /** §৭-ঘ — ২০ কিনলে বাড়তি ৫% ছাড়। */
    case BUY_X_GET_DISCOUNT = 'buy_x_get_discount';

    /** §৭-ঙ — পরিমাণের স্ল্যাব: ১–৪৯ → ২%, ৫০–৯৯ → ৫% … */
    case QUANTITY_SLAB = 'quantity_slab';

    /** §৭-চ — মূল্যের স্ল্যাব। */
    case VALUE_SLAB = 'value_slab';

    /** §৭-ছ — ক + খ + গ = ৫,০০০ টাকা। */
    case COMBO = 'combo';

    /** §৭-জ — একাধিক পণ্য একসাথে বিক্রি। */
    case BUNDLE = 'bundle';

    /** §৭-ঝ — ক কিনলে খ ফ্রি। */
    case FREE_PRODUCT = 'free_product';

    /** §৭-ঞ — কুপনের কোড দিয়ে। */
    case COUPON = 'coupon';

    /** §৭-ট — যোগ্য বিলে ক্রেডিট। */
    case CASHBACK = 'cashback';

    /** §৭-ঠ — ভবিষ্যতের কেনাকাটার জন্য পয়েন্ট। */
    case LOYALTY = 'loyalty';

    /**
     * ⭐ আজ এই ধরনটা সত্যিই চলে কি না।
     *
     * ── ⛔ কেন এই তালিকাটা দরকার ────────────────────────────────────
     * ⓘ বারোটা ধরন ঘোষিত, কিন্তু ইঞ্জিন একদিনে বারোটা শেখে না। ⚠️ যে
     * ধরনটা ইঞ্জিন চেনে না, সেটা বেছে অফার বানালে অফারটা তৈরি হত,
     * তালিকায় বসত, *"সক্রিয়"* দেখাত — আর বিলে **কিছুই করত না**।
     *
     * ⛔ আর ঐ নীরবতাটাই এখানকার সবচেয়ে দামি ভুল: কোথাও লাল হয় না,
     * অথচ ক্রেতা তাঁর প্রাপ্য ছাড়টা পান না।
     *
     * ⓘ তাই পর্দা কেবল এইগুলোই বাছতে দেবে, আর নতুন ধরন চালু হলে
     * এখানেই যোগ হবে — এক জায়গায়, এক সারিতে।
     */
    public function isBuilt(): bool
    {
        return match ($this) {
            /* ⭐ প্রথম ধাপ — মালিক ফ্রি মাল রোজ ব্যবহার করেন */
            self::BUY_X_GET_Y => true,
            self::QUANTITY_SLAB => true,
            self::VALUE_SLAB => true,

            /*
             * ⭐ দ্বিতীয় ধাপ — ইঞ্জিন এগুলো আগে থেকেই হিসাব করতে পারত।
             *
             * ⓘ [[PromotionEngine]] ধরন দেখে না, কেবল শর্ত আর সুবিধা দেখে।
             * ⚠️ তাই এই চারটার জন্য নতুন হিসাব লাগেনি — কেবল এই দরজা বন্ধ ছিল।
             * ⛔ তবু প্রতিটার জন্য ইঞ্জিনের আসল উত্তর মাপা হয়েছে
             * ([[TheEngineKnewFourMoreTypesAndNobodyCouldPickThemTest]]) — দরজা খোলা
             * কেবল *"বাছা যায়"* প্রমাণ করে, *"ঠিক হিসাব করে"* নয়।
             */
            self::PERCENT_DISCOUNT => true,
            self::FIXED_DISCOUNT => true,
            self::BUY_X_GET_DISCOUNT => true,
            self::FREE_PRODUCT => true,

            /* ⭐ কুপন — কোড আর তার খাতা [[CouponDesk]]-এ; ইঞ্জিন কোড ছাড়া প্রস্তাব করে না */
            self::COUPON => true,

            /*
             * ⛔ এখনো নয়, আর কারণগুলো আলাদা:
             * ⓘ COMBO/BUNDLE — একাধিক পণ্য একসাথে, অথচ ইঞ্জিন সারি ধরে দেখে।
             * ⓘ CASHBACK — ক্রেতার খাতায় টাকা বসে, অর্থাৎ হিসাব মডিউলের কাজ।
             * ⓘ LOYALTY — পয়েন্টের খাতা এখনো নেই।
             */
            default => false,
        };
    }

    /**
     * ⭐ এই ধরনটা উপহার দেয় কি না — অর্থাৎ মজুদ নড়ে কি না।
     *
     * ⚠️ প্রশ্নটা দামি: উপহার দিলে **আসল পণ্য গুদাম থেকে কমে**
     * (স্পেক §৮), আর তখন লট, গুদাম ও মূল্যায়ন তিনটাই লাগে। ⓘ ছাড়ে
     * কেবল টাকা কমে, মজুদ অক্ষত থাকে।
     */
    public function givesGoods(): bool
    {
        return in_array($this, [self::BUY_X_GET_Y, self::FREE_PRODUCT, self::COMBO, self::BUNDLE], true);
    }

    /**
     * ⛔ এই ধরনের অফারে কোন সুবিধা বসতে পারে।
     *
     * ⓘ *"ফ্রি পণ্য"* অফারে ২০% ছাড় বসলে অফারের নাম এক কথা বলত, বিলে
     * বসত আরেক কথা — আর তালিকা দেখে কেউ বুঝতেন না। ⚠️ স্ল্যাবে তিনটাই চলে,
     * কারণ স্ল্যাব কেবল *"কত কিনলে"* বলে, *"কী পাবেন"* বলে না।
     *
     * @return list<BenefitKind>
     */
    public function allowedBenefits(): array
    {
        return match ($this) {
            self::PERCENT_DISCOUNT => [BenefitKind::PERCENT],
            self::FIXED_DISCOUNT => [BenefitKind::AMOUNT],
            self::BUY_X_GET_DISCOUNT => [BenefitKind::PERCENT, BenefitKind::AMOUNT],
            self::BUY_X_GET_Y, self::FREE_PRODUCT => [BenefitKind::GOODS],
            self::QUANTITY_SLAB, self::VALUE_SLAB, self::COUPON,
            self::COMBO, self::BUNDLE => [BenefitKind::PERCENT, BenefitKind::AMOUNT, BenefitKind::GOODS],
            self::LOYALTY => [BenefitKind::POINTS],
            default => [],
        };
    }

    /** ⓘ আজ যেগুলো সত্যিই বাছা যায়। @return list<self> */
    public static function built(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => $t->isBuilt()));
    }

    public function label(): string
    {
        return __('promotion::type.'.$this->value);
    }
}
