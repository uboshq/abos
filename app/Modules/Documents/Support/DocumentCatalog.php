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
 * ⭐ দ্বিতীয় ধাপ (৯ অক্টোবর ২০২৬): কোম্পানি নিজের ফোল্ডার আর ধরন যোগ করতে পারে প্রশাসনের
 * পর্দায় ([[DocumentType]], [[DocumentCategory]]) — মালিকের তালিকা তবুও কোডেই, সবার আগে।
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

    /*
     * ── ⭐ অবস্থা — পরিকল্পনা §২২-এর দশটা (দ্বিতীয় ধাপ, ৯ অক্টোবর ২০২৬) ────────────
     * খসড়া → জমা → পর্যালোচনায় → (বদল চাওয়া / বাতিল) → অনুমোদিত → প্রকাশিত → আর্কাইভ।
     * মেয়াদ পেরোলে "মেয়াদোত্তীর্ণ"; মুছলে "মোছা" (রিসাইকেল বিনে)।
     */
    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const UNDER_REVIEW = 'under_review';

    public const CHANGES_REQUESTED = 'changes_requested';

    /**
     * ⭐ অনুমোদিত — নিয়মটা প্রথম দিন থেকে বাঁধা (§৯): অনুমোদিত কাগজ নিজের জায়গায় বদলায় না,
     * বদল মানে নতুন ভার্সন ([[DocumentPolicy::update()]])।
     */
    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const PUBLISHED = 'published';

    /**
     * ⭐ অনুমোদন ছাড়া প্রকাশিত — কোম্পানিতে কাগজের অনুমোদন বন্ধ থাকলে (fe, ১১ অক্টোবর ২০২৬; মালিকের "সব সইয়ে চালু/বন্ধ সুইচ" নিয়ম)।
     * ⓘ বন্ধ মানে সই লাগে না — কিন্তু কেউ অনুমোদন না করলে "অনুমোদিত" লেখা মিথ্যা। UB-তে সব ধারা বন্ধ, তাই সেখানে প্রতিটা জমা
     * আগে "অনুমোদিত" দেখাত।
     */
    public const PUBLISHED_UNAPPROVED = 'published_unapproved';

    public const EXPIRED = 'expired';

    public const ARCHIVED = 'archived';

    public const DELETED = 'deleted';

    /** @var list<string> */
    public const STATUSES = [
        self::DRAFT, self::SUBMITTED, self::UNDER_REVIEW, self::CHANGES_REQUESTED, self::APPROVED,
        self::REJECTED, self::PUBLISHED, self::PUBLISHED_UNAPPROVED, self::EXPIRED, self::ARCHIVED, self::DELETED,
    ];

    /**
     * ⓘ যে অবস্থায় বিবরণ নিজের জায়গায় বদলানো যায় — খসড়া, বা ফেরত আসা কাগজ।
     * ⛔ জমা/পর্যালোচনায় থাকা কাগজ নয় (যিনি সই করছেন তিনি যা দেখছেন সেটা নড়বে না), আর
     * অনুমোদিত/প্রকাশিত তো নয়ই (§৯)।
     *
     * @var list<string>
     */
    public const EDITABLE = [self::DRAFT, self::CHANGES_REQUESTED, self::REJECTED, self::EXPIRED];

    /**
     * ⓘ অনুমোদিত বা তার পরের — এদের বদল কেবল নতুন ভার্সন হয়ে।
     *
     * @var list<string>
     */
    public const SEALED = [self::APPROVED, self::PUBLISHED, self::PUBLISHED_UNAPPROVED];

    /**
     * সেন্টারের মেয়াদের ছাঁকনি (§১২) — `expired` আর সামনের ৭/৩০/৯০ দিন।
     *
     * @var list<string>
     */
    public const EXPIRY_WINDOWS = ['expired', '7', '30', '90'];
}
