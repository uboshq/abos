<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * ডকুমেন্টের অবস্থা — নিয়ম ৫।
 *
 * enum না রেখে const রাখা হয়েছে কারণ ডাটাবেজে string বসে আর রিপোর্টের কাঁচা
 * SQL-এও একই মান দরকার হয়; enum ব্যবহারে প্রতিবার ->value লিখতে হত এবং
 * কোথাও না কোথাও বাদ পড়ত।
 */
final class DocumentStatus
{
    /** তৈরি হয়েছে, হিসাবে যায়নি — মুক্তভাবে সম্পাদনাযোগ্য */
    public const DRAFT = 'draft';

    /** হিসাবে বসেছে — বদলাতে অনুমোদন লাগবে */
    public const CONFIRMED = 'confirmed';

    /** বাতিল — হিসাব উল্টো এন্ট্রি দিয়ে ফেরানো হয়েছে, রো রয়ে গেছে */
    public const CANCELLED = 'cancelled';

    /** সম্পূর্ণ ও বন্ধ — যেমন পুরো আদায় হয়ে যাওয়া ইনভয়েস */
    public const CLOSED = 'closed';

    /**
     * সবগুলো — যাচাইয়ের জন্য।
     *
     * `Metric` এটা ধরে দেখে কেউ অজানা কোনো অবস্থা গোনার কথা লিখল কি
     * না; টাইপো হলে সংখ্যাটা নিঃশব্দে শূন্য হত, আর কেউ ধরতে পারত না।
     *
     * @var list<string>
     */
    public const ALL = [self::DRAFT, self::CONFIRMED, self::CANCELLED, self::CLOSED];

    /**
     * যে অবস্থার কাগজ হিসাবে গোনা হয় — খসড়া নয়, বাতিল নয়।
     *
     * ── কেন এটা একটা নামওয়ালা ধ্রুবক ────────────────────────────────
     * তালিকাটা আগে ছয় জায়গায় হাতে লেখা ছিল, আর হাতে লেখা তালিকা একদিন
     * আলাদা হয়। ঠিক তাই হয়েছিল: কাউন্টারের "আজ কত বিক্রি" খসড়াও গুনত,
     * হোম পর্দা গুনত না। ধরে-রাখা একটা বিলের টাকা ক্যাশিয়ারের ঘরে যোগ
     * হয়ে বসে থাকত অথচ ড্রয়ারে আসেনি, আর শিফট মেলানোর সময় নগদ কম পড়ত।
     *
     * এখন নিয়মটা এখানেই, একবার। কোয়েরিতে `->posted()`; সংজ্ঞা বলার
     * দরকার হলে `DocumentStatus::POSTED`।
     *
     * @var list<string>
     */
    public const POSTED = [self::CONFIRMED, self::CLOSED];

    /** @return list<string> */
    public static function all(): array
    {
        return [self::DRAFT, self::CONFIRMED, self::CANCELLED, self::CLOSED];
    }

    public static function label(string $status, ?string $locale = null): string
    {
        return __('core.status.'.$status, [], $locale);
    }
}
