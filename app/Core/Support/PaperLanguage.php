<?php

declare(strict_types=1);

namespace App\Core\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ কাগজের ভাষা — যাঁর জন্য কাগজ তাঁর ভাষায়, যিনি ছাপছেন তাঁর নয় (চেকলিস্ট ভা-১৬; a5-র পরিকল্পনা
 * মালিকের-ফাইল/ছাপার-ভাষা-পরিকল্পনা-২৪-অক্টোবর.md; মালিকের পাঁচ উত্তর, ২৪ অক্টোবর ২০২৬; SAP-এর partner print language, Odoo-র partner lang)।
 *
 * ক্রম: ছাপার মেনুতে এবারের বাছাই (`?lang=bn|en`) → পক্ষের "কাগজের ভাষা" → পর্দার ভাষা (কোম্পানির ভাষা পরের ধাপে)।
 * ⓘ এবারের বাছাই পক্ষের master বদলায় না। ⓘ "দুই ভাষা" নকশার কাজ — যে নকশা দুই ভাষা জানে সে নিজেই আঁকে; এখানে বাংলা আর ইংরেজি।
 * ⓘ বাংলা কাগজেও টাকা আর সংখ্যা ইংরেজি অঙ্কে (মালিক, ২৪ অক্টোবর) — কেবল কথায় লেখা টাকা আর লেখা বাংলায়।
 */
final class PaperLanguage
{
    public const BN = 'bn';

    public const EN = 'en';

    public const BOTH = 'both';

    /** @var list<string> */
    public const ALL = [self::BN, self::EN, self::BOTH];

    /** ছাপার মেনুতে এবারের বাছাই — চেনা না হলে কিছুই নয় */
    public static function asked(): ?string
    {
        $lang = request()?->query('lang');

        return is_string($lang) && in_array($lang, [self::BN, self::EN], true) ? $lang : null;
    }

    /** এই কাগজের ভাষা — এবারের বাছাই, নয়তো পক্ষের, নয়তো কোম্পানির, নয়তো পর্দার */
    public static function for(?Model $party = null): string
    {
        return self::asked() ?? self::defaultFor($party);
    }

    /** বাছাই ছাড়া কাগজের ভাষা — ছাপার মেনুতে কোনটা আগে থেকে বাছা থাকবে */
    public static function defaultFor(?Model $party = null): string
    {
        $own = $party?->getAttribute('paper_language');

        if (is_string($own) && in_array($own, [self::BN, self::EN], true)) {
            return $own;
        }

        /*
         * ⓘ ধাপ ১: পক্ষের ভাষা না থাকলে আজকের মতোই পর্দার ভাষা। ⚠️ কোম্পানির ভাষায় যাওয়া পরের ধাপে — নতুন কোম্পানির ডিফল্ট ইংরেজি
         * (মালিকের উত্তর ৩) আর Control Panel-এ কোম্পানির ভাষা দেখানোর সাথে; আজ তিন কোম্পানিই বাংলা, আর ইংরেজি পর্দায় কাজ করা কেউ
         * হঠাৎ বাংলা কাগজ পেতেন।
         */
        return (string) app()->getLocale();
    }
}
