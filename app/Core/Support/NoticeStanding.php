<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * একজন মানুষ একটা নোটিশের সাথে কোথায় দাঁড়িয়ে।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬, ধারা ১৮ ────────────────────
 * Unread · Read · Acknowledged · Overdue · Escalated।
 *
 * ── ⚠️ কেন এটা নোটিশের অবস্থা নয় ────────────────────────────────────
 * ⓘ [[NoticeStatus]] বলে **নোটিশটা** কোথায় — খসড়া, প্রকাশিত, মেয়াদ
 * শেষ। ⛔ এটা বলে **মানুষটা** কোথায়, আর একই নোটিশে তিরিশজনের তিরিশটা
 * আলাদা উত্তর হয়।
 *
 * ⚠️ দুইটাকে এক কলামে রাখার চেষ্টা হলে নোটিশের সারিটাই তিরিশবার লিখতে
 * হত, আর দুইজন একসাথে পড়লে একজনের অবস্থা হারাত।
 */
enum NoticeStanding: string
{
    /** চোখেই পড়েনি। */
    case UNREAD = 'unread';

    /** পড়েছেন, কিন্তু সই চাওয়া হয়নি বা দেওয়া হয়নি। */
    case READ = 'read';

    /** সই দিয়েছেন। */
    case ACKNOWLEDGED = 'acknowledged';

    /**
     * সময় পেরিয়ে গেছে, সই আসেনি।
     *
     * ⓘ পড়েছেন কি না তাতে কিছু যায় আসে না — ⚠️ *"পড়েছি কিন্তু মানিনি"*
     * আর *"দেখিইনি"*, দুইটাই এখানে একই: কাজটা হয়নি।
     */
    case OVERDUE = 'overdue';

    /** উপরে জানানো হয়েছে। */
    case ESCALATED = 'escalated';

    /**
     * ⛔ এই অবস্থায় কারও কিছু করার বাকি আছে কি না।
     *
     * ⓘ একটাই জায়গা, কারণ প্রশ্নটা তিন জায়গা থেকে আসে: তাগাদার কাজ,
     * ড্যাশবোর্ডের গোনা, আর রিপোর্ট। ⚠️ তিনবার লিখলে একদিন একটায়
     * `ESCALATED` যোগ হত, অন্যটায় নয়।
     */
    public function stillOwes(): bool
    {
        return $this !== self::ACKNOWLEDGED;
    }

    public function label(): string
    {
        return __('core.notice.standing.'.$this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::ACKNOWLEDGED => 'success',
            self::ESCALATED, self::OVERDUE => 'danger',
            self::READ => 'info',
            self::UNREAD => 'muted',
        };
    }
}
