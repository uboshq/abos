<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Support;

/**
 * প্রচলিত কোডগুলো — যেগুলো নিয়ম মেনে বের করা যায় না।
 *
 * ── কেন একটা অভিধান, একটা অ্যালগরিদম নয় ─────────────────────────────
 * `Piece` থেকে `PCS` কোনো নিয়মে আসে না — `P`, `PI`, `PIE` আসে, `PCS`
 * আসে না। `Kilogram` থেকে `KG`-ও নয়। **এগুলো প্রথা, উৎপত্তি নয়** —
 * বছরের পর বছর চালানে যা লেখা হয়েছে তাই।
 *
 * মুদ্রার ক্ষেত্রে ব্যাপারটা আরও কড়া: `BDT`, `USD`, `EUR` — এগুলো
 * **ISO 4217**, একটা আন্তর্জাতিক মান। ব্যাংক, LC আর রপ্তানির কাগজ ওই
 * তিন অক্ষরই চায়। নিজের বানানো কিছু বসালে কাগজটা প্রত্যাখ্যাত হয়।
 *
 * ── কেন এটা কোরে নেই ────────────────────────────────────────────────
 * "একক" আর "মুদ্রা" মাস্টার ডাটার জিনিস। কোরে রাখলে কোর জানত এই
 * মডিউলটা আছে ও তার ভেতরে কী কী তালিকা (§১৯.৭)। কোর কেবল **কৌশলটা**
 * দেয় ([[CodeSuggester::fromName()]]), আর অভিধানটা যায় ডাকার সময়।
 *
 * ── তালিকাটা ছোট, আর ইচ্ছাকৃতভাবে ───────────────────────────────────
 * এখানে সেগুলোই আছে যেগুলো বাংলাদেশের ব্যবসায় সত্যিই টাইপ করা হয়।
 * একশো একক ভরে রাখলে ফাইলটা কেউ পড়ত না, আর অভিধানে না থাকলেও কিছু
 * ভাঙে না — তখন প্রথম তিন অক্ষর বসে, আর মানুষ চাইলে বদলান।
 */
final class CodeConventions
{
    /**
     * পরিমাপের একক।
     *
     * চাবিগুলো **বড় হাতের ইংরেজি নাম**, কারণ [[CodeSuggester]] নামটা
     * ওই রূপে এনেই মেলায়। বহুবচনগুলোও আছে ("PIECES") — মানুষ দুইভাবেই
     * লেখেন, আর একটা বাদ পড়লে ওই বানানে অভিধানটা কাজ করত না।
     *
     * @var array<string, string>
     */
    public const UNITS = [
        'PIECE' => 'PCS',
        'PIECES' => 'PCS',
        'DOZEN' => 'DOZ',
        'CARTON' => 'CTN',
        'KILOGRAM' => 'KG',
        'KILOGRAMS' => 'KG',
        'KILO' => 'KG',
        'GRAM' => 'GM',
        'GRAMS' => 'GM',
        'TON' => 'TON',
        'METRIC TON' => 'MT',
        'LITRE' => 'LTR',
        'LITER' => 'LTR',
        'MILLILITRE' => 'ML',
        'MILLILITER' => 'ML',
        'METRE' => 'MTR',
        'METER' => 'MTR',
        'FEET' => 'FT',
        'FOOT' => 'FT',
        'INCH' => 'IN',
        'BAG' => 'BAG',
        'BAGS' => 'BAG',
        'BOX' => 'BOX',
        'BOTTLE' => 'BTL',
        'PACKET' => 'PKT',
        'PACK' => 'PKT',
        'BUNDLE' => 'BDL',
        'ROLL' => 'ROL',
        'SET' => 'SET',
        'PAIR' => 'PR',
        'DRUM' => 'DRM',
        'SACK' => 'SCK',
        'CASE' => 'CSE',
        'TUBE' => 'TUB',
        'JAR' => 'JAR',
        'CAN' => 'CAN',
        'STRIP' => 'STR',
        'VIAL' => 'VIL',
        'AMPOULE' => 'AMP',
        'TABLET' => 'TAB',
        'CAPSULE' => 'CAP',
        'SQUARE FEET' => 'SFT',
        'SQUARE METRE' => 'SQM',
        'SQUARE METER' => 'SQM',
    ];

    /**
     * মুদ্রা — ISO 4217।
     *
     * ⚠️ এই কোডগুলোর একটাও বদলানো যাবে না। ব্যাংকের কাগজ, LC আর
     * রপ্তানির নথি এগুলোই চেনে; নিজের বানানো কিছু বসালে কাগজটা
     * প্রত্যাখ্যাত হয়।
     *
     * তালিকাটা বাংলাদেশের বাণিজ্যে যেগুলো সত্যিই লাগে — আমদানি,
     * রপ্তানি ও রেমিট্যান্সের প্রধান মুদ্রাগুলো।
     *
     * @var array<string, string>
     */
    public const CURRENCIES = [
        'TAKA' => 'BDT',
        'BANGLADESHI TAKA' => 'BDT',
        'BANGLADESH TAKA' => 'BDT',
        'US DOLLAR' => 'USD',
        'UNITED STATES DOLLAR' => 'USD',
        'DOLLAR' => 'USD',
        'EURO' => 'EUR',
        'POUND' => 'GBP',
        'POUND STERLING' => 'GBP',
        'BRITISH POUND' => 'GBP',
        'INDIAN RUPEE' => 'INR',
        'RUPEE' => 'INR',
        'PAKISTANI RUPEE' => 'PKR',
        'CHINESE YUAN' => 'CNY',
        'YUAN' => 'CNY',
        'JAPANESE YEN' => 'JPY',
        'YEN' => 'JPY',
        'SAUDI RIYAL' => 'SAR',
        'RIYAL' => 'SAR',
        'UAE DIRHAM' => 'AED',
        'DIRHAM' => 'AED',
        'MALAYSIAN RINGGIT' => 'MYR',
        'RINGGIT' => 'MYR',
        'SINGAPORE DOLLAR' => 'SGD',
        'THAI BAHT' => 'THB',
        'KOREAN WON' => 'KRW',
        'AUSTRALIAN DOLLAR' => 'AUD',
        'CANADIAN DOLLAR' => 'CAD',
        'SWISS FRANC' => 'CHF',
        'KUWAITI DINAR' => 'KWD',
        'QATARI RIYAL' => 'QAR',
        'OMANI RIYAL' => 'OMR',
    ];

    /**
     * কর ও ভ্যাট — বাংলাদেশে যেভাবে লেখা হয়।
     *
     * @var array<string, string>
     */
    public const TAXES = [
        'VALUE ADDED TAX' => 'VAT',
        'VAT' => 'VAT',
        'ADVANCE INCOME TAX' => 'AIT',
        'ADVANCE TAX' => 'AT',
        'SUPPLEMENTARY DUTY' => 'SD',
        'TAX DEDUCTED AT SOURCE' => 'TDS',
        'SOURCE TAX' => 'TDS',
        'CUSTOMS DUTY' => 'CD',
        'REGULATORY DUTY' => 'RD',
        'EXEMPT' => 'EXM',
        'ZERO RATED' => 'ZR',
        'NO TAX' => 'NIL',
    ];

    /**
     * কোন তালিকার জন্য কোন অভিধান।
     *
     * চাবিটা [[MasterListController]]-এর `kind` — অর্থাৎ URL-এ যা
     * দেখা যায় (`units`, `currencies`)। এখানে না থাকা তালিকাগুলো
     * অভিধান ছাড়াই চলে, আর সেটাই ঠিক: ব্র্যান্ড বা বিভাগের কোনো
     * প্রচলিত সংক্ষিপ্ত রূপ নেই, ওখানে নামের আদ্যক্ষরই সবচেয়ে ভালো
     * অনুমান।
     *
     * @return array<string, string>
     */
    public static function forKind(string $kind): array
    {
        return match ($kind) {
            'units' => self::UNITS,
            'currencies' => self::CURRENCIES,
            'taxes' => self::TAXES,
            default => [],
        };
    }
}
