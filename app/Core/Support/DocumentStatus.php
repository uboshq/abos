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
