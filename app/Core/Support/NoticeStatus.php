<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * একটা নোটিশ তার জীবনে যে অবস্থাগুলোয় থাকতে পারে।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬, ধারা ৪ ─────────────────────
 * *"Create → Review → Approve → Schedule/Publish → Distribute → Read →
 * Acknowledge → Audit"*, আর প্রকাশিত নোটিশ সরাসরি মোছা যাবে না।
 *
 * ── ⚠️ কেন অবস্থাগুলো enum-এ, স্ট্রিং কলামে নয় ───────────────────────
 * ⓘ স্ট্রিং হলে `'publised'` লেখা যেত, আর সারিটা চিরকাল এমন একটা
 * অবস্থায় বসে থাকত যা কোনো কোড চেনে না। ⛔ নোটিশটা তখন কোনো তালিকাতেই
 * আসত না — খসড়াও নয়, প্রকাশিতও নয় — আর কেউ বলতে পারত না ওটা কোথায়
 * গেল।
 *
 * ── ⓘ কেন এটা কোরে, নোটিশ মডিউলে নয় ────────────────────────────────
 * [[Notice]] নিজেই কোরে আছে (নিচের বারটা কোর আঁকে), তাই তার অবস্থাও
 * কোরেই থাকে। ⚠️ [[DocumentStatus]]-এর সাথে মেলানো হয়নি ইচ্ছাকৃতভাবে:
 * ⛔ ঐটা টাকার কাগজের অবস্থা (খসড়া · নিশ্চিত · বাতিল), আর নোটিশের
 * জীবনে এমন ধাপ আছে যা কোনো বিলে নেই — মেয়াদ, প্রত্যাহার, সংরক্ষণাগার।
 */
enum NoticeStatus: string
{
    /** লেখা হচ্ছে — কেউ দেখেননি। */
    case DRAFT = 'draft';

    /** অনুমোদনের জন্য পাঠানো। */
    case SUBMITTED = 'submitted';

    /** কেউ দেখছেন। */
    case UNDER_REVIEW = 'under_review';

    /** সই হয়ে গেছে, কিন্তু এখনো প্রকাশ নয়। */
    case APPROVED = 'approved';

    /** সময় ঠিক করা আছে — নিজে থেকে প্রকাশ হবে। */
    case SCHEDULED = 'scheduled';

    /** সবার চোখের সামনে। */
    case PUBLISHED = 'published';

    /** সময় ফুরিয়েছে। */
    case EXPIRED = 'expired';

    /** তুলে রাখা — খোঁজা যায়, দেখা যায় না। */
    case ARCHIVED = 'archived';

    /** ফিরিয়ে দেওয়া হয়েছে, কারণসহ। */
    case REJECTED = 'rejected';

    /** লেখকই থামিয়ে দিয়েছেন, প্রকাশের আগে। */
    case CANCELLED = 'cancelled';

    /** সাময়িক থামানো — পরে আবার চালু হতে পারে। */
    case SUSPENDED = 'suspended';

    /**
     * প্রকাশের **পরে** ফিরিয়ে নেওয়া।
     *
     * ⚠️ এটা বাতিলের মতো নয়, আর তফাতটা দামি: ⓘ প্রত্যাহার মানে লেখাটা
     * মানুষ **দেখে ফেলেছে**। ⛔ দুইটাকে এক ধরলে খাতা বলত কথাটা কেউ
     * জানে না, অথচ গোটা অফিস জানে।
     */
    case RECALLED = 'recalled';

    /**
     * এখান থেকে কোথায় কোথায় যাওয়া যায়।
     *
     * ── ⚠️ কেন তালিকাটা এক জায়গায়, শর্তে ছড়ানো নয় ───────────────────
     * ⓘ পথগুলো ছড়িয়ে লিখলে একটা নতুন পর্দা লিখতে গিয়ে কেউ নিজের মতো
     * একটা পথ বানাত — যেমন সংরক্ষণাগার থেকে সোজা প্রকাশ। ⛔ তখন
     * অনুমোদনের ধাপটা নীরবে এড়ানো যেত, আর পাহারা দেওয়ার কোনো একক
     * জায়গা থাকত না।
     *
     * @return list<self>
     */
    public function nextAllowed(): array
    {
        return match ($this) {
            self::DRAFT => [self::SUBMITTED, self::CANCELLED],
            self::SUBMITTED => [self::UNDER_REVIEW, self::APPROVED, self::REJECTED, self::CANCELLED],
            self::UNDER_REVIEW => [self::APPROVED, self::REJECTED],

            /* ⓘ সই হওয়ার পর দুইটা পথ: এখনই, নাকি সময় ধরে */
            self::APPROVED => [self::PUBLISHED, self::SCHEDULED, self::CANCELLED],
            self::SCHEDULED => [self::PUBLISHED, self::CANCELLED, self::SUSPENDED],

            /*
             * ⛔ প্রকাশের পর `DRAFT`-এ ফেরা নেই, আর সেটাই মূল কথা।
             *
             * ⚠️ ফেরা গেলে লেখাটা নীরবে বদলে আবার প্রকাশ করা যেত, আর
             * যিনি প্রথমটা পড়েছেন তিনি ভিন্ন কথা পড়েছেন — অথচ খাতায়
             * একটাই নোটিশ। ⓘ সম্পাদনার পথ [[NoticeVersion]], এই পথ নয়।
             */
            self::PUBLISHED => [self::EXPIRED, self::RECALLED, self::SUSPENDED, self::ARCHIVED],
            self::SUSPENDED => [self::PUBLISHED, self::RECALLED, self::ARCHIVED],
            self::EXPIRED, self::RECALLED, self::REJECTED, self::CANCELLED => [self::ARCHIVED],

            /* ⛔ সংরক্ষণাগারই শেষ — ফেরানোর পথ `restore()`, অবস্থা-বদল নয় */
            self::ARCHIVED => [],
        };
    }

    /** এখান থেকে ওখানে যাওয়া যায় কি না। */
    public function canBecome(self $next): bool
    {
        return in_array($next, $this->nextAllowed(), true);
    }

    /**
     * ⭐ এই অবস্থার নোটিশ মানুষের চোখে পড়ে কি না।
     *
     * ⓘ একটাই জায়গা, কারণ প্রশ্নটা তিন জায়গা থেকে আসে: নিচের বার,
     * নোটিশের তালিকা, আর অপঠিতের গোনা। ⚠️ তিন জায়গায় তিনবার লিখলে
     * একদিন একটায় `SUSPENDED` যোগ হত, অন্যটায় নয়।
     */
    public function isLive(): bool
    {
        return $this === self::PUBLISHED;
    }

    /** ⛔ এই অবস্থা থেকে আর কোথাও যাওয়ার নেই। */
    public function isFinal(): bool
    {
        return $this->nextAllowed() === [];
    }

    /**
     * ⛔ এই নোটিশ কি কোনোদিন মানুষের চোখে পড়েনি।
     *
     * ── ⚠️ কেন প্রশ্নটা "প্রকাশিত কি না" নয় ─────────────────────────
     * ⓘ মোছা যায় কি না তা ঠিক হয় **অতীত** দিয়ে, বর্তমান দিয়ে নয়। ⛔
     * একটা প্রত্যাহার করা নোটিশ এখন প্রকাশিত নয়, কিন্তু মানুষ ওটা
     * পড়ে ফেলেছে — মুছে দিলে খাতা বলত কথাটা কেউ জানে না, অথচ গোটা
     * অফিস জানে।
     *
     * ⓘ তাই তালিকাটা উল্টো দিক থেকে লেখা: কোন অবস্থাগুলো **নিশ্চিতভাবে**
     * প্রকাশের আগের। ⚠️ নতুন কোনো অবস্থা যোগ হলে সে নিজে থেকেই নিরাপদ
     * দিকে পড়বে — অর্থাৎ মোছা যাবে না।
     */
    public function wasNeverSeen(): bool
    {
        return in_array($this, [
            self::DRAFT,
            self::SUBMITTED,
            self::UNDER_REVIEW,
            self::APPROVED,
            self::SCHEDULED,
            self::REJECTED,
            self::CANCELLED,
        ], true);
    }

    public function label(): string
    {
        return __('core.notice.status.'.$this->value);
    }
}
