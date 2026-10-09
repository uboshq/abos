<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/**
 * ডকুমেন্টের স্থির তালিকাগুলো — ফোল্ডার, ধরন, গোপনীয়তা, অবস্থা, মেয়াদের জানালা।
 *
 * ⭐ মালিকের পরিকল্পনা থেকে (৮ অক্টোবর ২০২৬, প্রথম ধাপ): ফোল্ডার §৪, ধরন §১৫/§২১,
 * গোপনীয়তা §১৪, অবস্থা §২২, মেয়াদ §১২।
 *
 * ⓘ লেখা lang-এ (`documents::catalog.*`); এখানে কেবল চাবি আর ক্রম।
 * ⚠️ নিজের ফোল্ডার বা ধরন বানানোর পর্দা প্রশাসনের (§২০) — সেদিন তালিকাটা টেবিলে সরবে।
 */
final class DocumentCatalog
{
    /**
     * মালিকের ন'টা ফোল্ডার, মালিকের ক্রমে (§৪)।
     *
     * @var list<string>
     */
    public const FOLDERS = [
        'company', 'contracts', 'hr', 'finance', 'sales', 'purchase', 'inventory', 'legal', 'compliance',
    ];

    /**
     * ডকুমেন্টের ধরন — §১৫-এর সম্পর্কের তালিকা থেকে (চুক্তি, সনদ, চিঠি…)।
     *
     * ⓘ `other` শেষে — যা কোনোটায় পড়ে না।
     *
     * @var list<string>
     */
    public const TYPES = [
        'contract', 'agreement', 'license', 'certificate', 'invoice', 'letter',
        'policy', 'report', 'identity', 'form', 'other',
    ];

    public const PUBLIC = 'public';

    public const INTERNAL = 'internal';

    public const CONFIDENTIAL = 'confidential';

    public const HIGHLY_CONFIDENTIAL = 'highly_confidential';

    public const RESTRICTED = 'restricted';

    /**
     * গোপনীয়তার পাঁচ ধাপ, খোলা থেকে বন্ধের দিকে (§১৪)।
     *
     * ⚠️ ক্রমটাই নিয়ম: উপরের ধাপের চাবি নিচের সব ধাপ খোলে ([[DocumentAccess]])।
     *
     * @var list<string>
     */
    public const LEVELS = [
        self::PUBLIC, self::INTERNAL, self::CONFIDENTIAL, self::HIGHLY_CONFIDENTIAL, self::RESTRICTED,
    ];

    /** অবস্থা — আজ খসড়া আর আর্কাইভ; অনুমোদনের ধারা (§১০) এলে বাকিগুলো */
    public const DRAFT = 'draft';

    /**
     * ⭐ অনুমোদিত — আজ কোনো পর্দা এখানে পৌঁছায় না, কিন্তু নিয়মটা আজই বাঁধা (§৯):
     * অনুমোদিত কাগজ নিজের জায়গায় বদলায় না, বদল মানে নতুন ভার্সন ([[DocumentPolicy::update()]])।
     */
    public const APPROVED = 'approved';

    public const ARCHIVED = 'archived';

    /** @var list<string> */
    public const STATUSES = [self::DRAFT, self::APPROVED, self::ARCHIVED];

    /**
     * সেন্টারের মেয়াদের ছাঁকনি (§১২) — `expired` আর সামনের ৭/৩০/৯০ দিন।
     *
     * @var list<string>
     */
    public const EXPIRY_WINDOWS = ['expired', '7', '30', '90'];
}
